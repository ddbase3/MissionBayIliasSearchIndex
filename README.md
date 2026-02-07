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
  * read roles for access checks at search time

### 2) During delete jobs

A vector-store proxy resource sits between the `AiEmbeddingNode` and the real vector store.

* When the embedding pipeline calls `deleteByFilter(..., { content_uuid: ... })`, the proxy deletes the DB search index rows for this content.
* Then it forwards the delete call to the real vector store.

---

## Architecture overview

### Resources provided by this plugin

* `FullTextIndexingParserAgentResource`

  * Implements `IAgentContentParser`
  * Docks a list of real parsers at dock `parsers`
  * Builds and stores a phonetic word index based on `AgentParsedContent`
  * Ensures required DB tables exist

* `SearchIndexVectorStoreProxyAgentResource`

  * Implements `IAgentVectorStore`
  * Docks the real store at dock `store`
  * Deletes index rows on `deleteByFilter(content_uuid)`
  * Ensures required DB tables exist

### Services (lightweight, not docked)

The phonetic components are small classes (not AgentResources) and are loaded by `FullTextIndexingParserAgentResource` using the BASE3 `IClassMap`.

* `ColognePhoneticEncoder` (implements `IPhoneticEncoder`)
* `ReversePhoneticStringToIntConverter` (implements `IPhoneticIntConverter`)

Both implement `Base3\Api\IBase`.

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

Stores the UI link for the content.

Schema:

* `content_id BINARY(16) NOT NULL`
* `direct_link VARCHAR(512) NOT NULL`
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

The parser proxy loads the stopword list once per instance.

Language is taken from `AgentContentItem.metadata['lang']` if present, otherwise default is `de`.

---

## Phonetic indexing

### Why phonetic codes?

Users often misspell, and German spelling variants are common. A phonetic representation can match similar-sounding words.

### Default implementation: Cologne Phonetics

`ColognePhoneticEncoder` converts a word into a digit string (e.g. `"Meyer" -> "67..."`).

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

## Search concept (planned)

The search feature is not implemented in this plugin yet, but the index is designed for fast matching.

### Query transformation

When a user searches for a word:

1. Normalize the input
2. Apply `ColognePhoneticEncoder`
3. Reverse the code
4. Convert to integer using `ReversePhoneticStringToIntConverter`

This produces the query integer `q`.

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
* `lang` (string, e.g. `de`)

---

## Usage

### Minimal agent flow example

This example shows how to place both proxies into an existing embedding flow.

Key points:

* The `AiEmbeddingNode.parser` dock receives **only** the parser proxy.
* The parser proxy docks the real parsers via dock `parsers`.
* The `AiEmbeddingNode.vectordb` dock receives **only** the vector store proxy.
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

---

## Notes and limitations

* The current tokenizer removes numbers and non-letter characters.
* Phrase search is intentionally not implemented.
* The search query logic is planned and described above, but the actual search API/UI is not part of this plugin yet.

---

## License

GPLv3.
