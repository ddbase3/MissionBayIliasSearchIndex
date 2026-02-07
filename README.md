# MissionBayIliasSearchIndex

DB-backed word-level search index for ILIAS content, built automatically during the MissionBay embedding pipeline.

This plugin extends the MissionBay Agent System inside the BASE3 Framework ecosystem and is meant to be used together with an embedding flow (extract → parse → chunk → embed → vector store). While embeddings are great for semantic search, this plugin adds a lightweight, fast, and deterministic word search index based on **phonetic codes**.

Created by Daniel Dahme (BASE3). Licensed under GPLv3.

---

## What this plugin does

### 1) During parsing (upsert jobs)

A parser proxy resource sits between the `AiEmbeddingNode` and the real parser list.

* It forwards `supports()` and `parse()` to the first matching real parser (priority order).
* It takes the returned `AgentParsedContent`, extracts text, tokenizes it into words, removes stopwords, and creates a **phonetic word index**.
* It stores the index in a small MySQL table.
* It also stores:

  * a direct link to the content (UI URL)
  * title and description for UI display
  * read roles for access checks at search time

### 2) During delete jobs

A vector-store proxy resource sits between the `AiEmbeddingNode` and the real vector store.

* When the embedding pipeline calls `deleteByFilter(..., { content_uuid: ... })`, the proxy deletes the DB search index rows for this content.
* Then it forwards the delete call to the real vector store.

### 3) Search UI (AJAX)

This plugin includes an MVC-based search UI display.

* A styled input field that searches via AJAX.
* Debounced requests (example: 500ms idle).
* Search starts at a minimum query length (default: 3 characters).
* Multiple word queries are supported.
* The UI renders up to 10 results showing:

  * title
  * description (shortened)
  * direct link

---

## Architecture overview

### Resources provided by this plugin

* `FullTextIndexingParserAgentResource`

  * Implements `IAgentContentParser`
  * Docks a list of real parsers at dock `parsers`
  * Builds and stores a phonetic word index based on `AgentParsedContent`
  * Stores direct link, title, description, and read roles
  * Ensures required DB tables exist

* `SearchIndexVectorStoreProxyAgentResource`

  * Implements `IAgentVectorStore`
  * Docks the real store at dock `store`
  * Deletes index rows on `deleteByFilter(content_uuid)`
  * Ensures required DB tables exist

### Display (MVC)

* `IliasSearchIndexDisplay`

  * Implements `Base3\Api\IDisplay`
  * Renders HTML (template) or JSON (AJAX endpoint)
  * Executes the phonetic DB search and returns result items
  * Applies the same tokenization rules as indexing, including stopword filtering

### Services (lightweight, not docked)

The phonetic components are small classes (not AgentResources).

* `ColognePhoneticEncoder` (implements `IPhoneticEncoder`)
* `ReversePhoneticStringToIntConverter` (implements `IPhoneticIntConverter`)

Both implement `Base3\Api\IBase`.

The indexing resource loads them via `IClassMap`.

The search UI can either load them via config (recommended) or fall back to default names (current default implementation).

---

## Database tables

The plugin uses three minimal tables.

### 1) `base3_content_search_index`

Stores one row per (content_id, token_int).

* `content_id` is the canonical content identifier (`BINARY(16)`) derived from `content_uuid` (hex) via `UNHEX()`.
* `token_int` is the numeric representation of the phonetic code.

Schema:

* `content_id BINARY(16) NOT NULL`
* `token_int BIGINT NOT NULL`
* `PRIMARY KEY (content_id, token_int)`
* `KEY idx_token (token_int)`

### 2) `base3_content_direct_link`

Stores UI and display fields for the content.

Schema:

* `content_id BINARY(16) NOT NULL`
* `direct_link VARCHAR(512) NOT NULL`
* `title VARCHAR(255) NOT NULL`
* `description TEXT NOT NULL`
* `PRIMARY KEY (content_id)`

### 3) `base3_content_read_roles`

Stores read permissions for the content.

Schema:

* `content_id BINARY(16) NOT NULL`
* `role_id INT NOT NULL`
* `PRIMARY KEY (content_id, role_id)`
* `KEY idx_role (role_id)`

All tables are automatically created via `CREATE TABLE IF NOT EXISTS ...` when the resources initialize.

---

## Stopwords

Stopwords are stored as INI files inside:

* `local/StopWords/`

Example file:

* `local/StopWords/stopwords.de.ini`

Format:

```ini
[stopwords]
words[] = "und"
words[] = "oder"
words[] = "der"
```

Stopword filtering is applied:

* during indexing (parser proxy)
* during search (search UI)

This is important to keep query tokens aligned with the indexed token set.

Language during indexing is taken from `AgentContentItem.metadata['lang']` if present, otherwise default is `de`.

The current search UI defaults to `de` as well.

---

## Phonetic indexing

### Why phonetic codes?

Users often misspell, and German spelling variants are common. A phonetic representation can match similar-sounding words.

### Default implementation: Cologne Phonetics

`ColognePhoneticEncoder` converts a word into a digit string (for example: `"Meyer" -> "67..."`).

Unlike Soundex, Cologne Phonetics is a good default for German.

### Converting the phonetic string into an integer

We want a numeric value for fast operations and compact storage.

However, Cologne Phonetics can produce an initial `0` for words starting with vowels. A simple `string -> int` conversion would drop leading zeros.

To preserve that information, we use a trick:

1. Take the phonetic digit string
2. Reverse it
3. Convert to integer

Example:

* phonetic code: `"076942"`
* reversed: `"249670"`
* stored int: `249670`

This approach preserves the initial `0` because after reversing it becomes a trailing `0`.

### Tokenization rules (current version)

For the first iteration we keep it minimal:

* Normalize to lowercase
* Remove all non-letters
* Split on whitespace
* Drop words shorter than 2 characters
* Remove stopwords
* Apply `array_unique()`

Numbers are currently removed. This may be refined later.

---

## Search concept

The search feature is implemented as an MVC display and uses the same index design as described below.

### Query transformation

When a user searches for a word:

1. Normalize the input
2. Apply `ColognePhoneticEncoder`
3. Reverse the code
4. Convert to integer using `ReversePhoneticStringToIntConverter`

This produces the query integer `q`.

Stopwords are removed at query time as well.

### Suffix matching via modulo

The stored integer is the reversed phonetic code.

This makes suffix matching possible using a simple modulo operation:

* Let `token_int` be the stored value
* Let `q` be the query value
* Let `k` be the number of digits in `q`

Match if:

* `q == token_int mod 10^k`

Example:

* stored `token_int = 76942`
* query `q = 942` (k = 3)
* compute `76942 mod 1000 = 942`
* match

This supports prefix matches on the original (non-reversed) phonetic string.

### Multi-word queries

For multi-word queries, the search endpoint generates one phonetic integer per word and looks for content IDs that match **all** terms.

Implementation detail:

* SQL uses an `OR` pre-filter to reduce scanned rows
* then uses a `GROUP BY content_id` with a HAVING clause that enforces term coverage

### Performance note

Modulo operations may not be index-friendly in SQL. If needed, a future optimization can add generated columns for common digit lengths, for example:

* `token_mod_3 = token_int % 1000`

and index those columns to make queries fully index-accelerated.

---

## Required metadata

The embedding pipeline must provide these metadata fields in `AgentContentItem.metadata`:

* `content_uuid` (required)

  * 32-char hex string
  * used as canonical identifier

Optional, but used when present:

* `direct_link` (string)
* `read_roles` (array of int role IDs)
* `lang` (string, for example `de`)

The direct-link table additionally stores `title` and `description` when available.

---

## Usage

### Minimal agent flow example

This example shows how to place both proxies into an existing embedding flow.

Key points:

* The `AiEmbeddingNode.parser` dock receives only the parser proxy.
* The parser proxy docks the real parsers via dock `parsers`.
* The `AiEmbeddingNode.vectordb` dock receives only the vector store proxy.
* The vector store proxy docks the real store via dock `store`.

```json
{
	"nodes": [
		{ "id": "embedding", "type": "aiembeddingnode", "docks": {
			"extractor": [ "extractor" ],
			"parser": [ "parserproxy" ],
			"chunker": [ "chunker" ],
			"embedder": [ "embeddingcache" ],
			"vectordb": [ "vectordbproxy" ],
			"logger": [ "logger" ]
		}, "inputs": {
			"mode": "replace",
			"debug": true,
			"debug_preview_len": 180
		}}
	],
	"resources": [
		{ "id": "extractor", "type": "iliasembeddingqueueextractoragentresource", "config": {
			"claim_limit": { "mode": "fixed", "value": 3 }
		}},

		{ "id": "fileparser", "type": "iliasfileunstructuredparseragentresource" },
		{ "id": "pageparser", "type": "iliaspageparseragentresource" },
		{ "id": "courseparser", "type": "iliascourseparseragentresource" },
		{ "id": "fallbackparser", "type": "structuredobjectparseragentresource" },

		{ "id": "parserproxy", "type": "fulltextindexingparseragentresource", "docks": {
			"parsers": [ "fileparser", "pageparser", "courseparser", "fallbackparser" ],
			"logger": [ "logger" ]
		}, "config": {
			"phonetic_encoder": { "mode": "fixed", "value": "colognephoneticencoder" },
			"phonetic_int_converter": { "mode": "fixed", "value": "reversephoneticstringtointconverter" }
		}},

		{ "id": "chunker", "type": "iliaschunkeragentresource" },

		{ "id": "embeddingcache", "type": "embeddingcacheagentresource", "docks": {
			"embedding": [ "embedder" ],
			"logger": [ "logger" ]
		}},
		{ "id": "embedder", "type": "qualitusembeddingproxyagentresource" },

		{ "id": "vectordbproxy", "type": "searchindexvectorstoreproxyagentresource", "docks": {
			"store": [ "vectordb" ],
			"logger": [ "logger" ]
		}},
		{ "id": "vectordb", "type": "qualitusqdrantvectorstoreagentresource" },

		{ "id": "logger", "type": "loggerresource" }
	]
}
```

---

## Search UI

### Endpoint

The search UI display supports both:

* `out=html` (renders the search page)
* `out=json&action=search&q=...` (AJAX search)

Example (JSON):

* `base3.php?name=iliassearchindexdisplay&out=json&action=search&q=...`

### Behavior

* The browser sends debounced requests after typing.
* Search only starts when the normalized query length is at least 3 characters.
* The query is tokenized and stopwords are removed.
* Up to 6 query words are used (to keep SQL bounded).
* The endpoint returns up to 10 results.

### Result structure

Each result item contains:

* `content_id` (hex string)
* `direct_link` (UI link)
* `title`
* `description`

Role checks are not implemented yet.

---

## Configuration

### FullTextIndexingParserAgentResource

Config keys:

* `phonetic_encoder`

  * Fixed value: the `getName()` of an `IPhoneticEncoder` implementation
  * Example: `colognephoneticencoder`

* `phonetic_int_converter`

  * Fixed value: the `getName()` of an `IPhoneticIntConverter` implementation
  * Example: `reversephoneticstringtointconverter`

Optional keys:

* `stopword_dir`

  * Override stopword directory

### SearchIndexVectorStoreProxyAgentResource

Optional keys:

* `search_table`
* `direct_link_table`
* `read_roles_table`

Defaults:

* `base3_content_search_index`
* `base3_content_direct_link`
* `base3_content_read_roles`

### IliasSearchIndexDisplay

Current defaults:

* encoder: `colognephoneticencoder`
* int converter: `reversephoneticstringtointconverter`

These can be made configurable later if multiple encoders are active.

---

## Notes and limitations

* The current tokenizer removes numbers and non-letter characters.
* Phrase search is intentionally not implemented.
* SQL modulo matching is simple and robust; indexing optimizations may be added later.
* Access checks via `base3_content_read_roles` are planned.

---

## License

GPLv3.
