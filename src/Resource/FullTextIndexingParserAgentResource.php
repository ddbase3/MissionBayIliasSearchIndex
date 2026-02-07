<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Resource;

use Base3\Api\IClassMap;
use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use MissionBay\Agent\AgentNodeDock;
use MissionBay\Api\IAgentContentParser;
use MissionBay\Api\IAgentContext;
use MissionBay\Dto\AgentContentItem;
use MissionBay\Dto\AgentParsedContent;
use MissionBay\Resource\AbstractAgentResource;
use MissionBayIliasSearchIndex\Api\IPhoneticEncoder;
use MissionBayIliasSearchIndex\Api\IPhoneticIntConverter;

final class FullTextIndexingParserAgentResource extends AbstractAgentResource implements IAgentContentParser {

	private const DOCK_PARSERS = 'parsers';
	private const DOCK_LOGGER = 'logger';

	private const META_CONTENT_UUID = 'content_uuid';
	private const META_DIRECT_LINK = 'direct_link';
	private const META_READ_ROLES = 'read_roles';
	private const META_LANG = 'lang';

	private ?ILogger $logger = null;

	/** @var IAgentContentParser[] */
	private array $parsers = [];

	private ?IPhoneticEncoder $phonetic = null;
	private ?IPhoneticIntConverter $converter = null;

	private string $stopWordDir = '';

	private string $searchTable = 'base3_content_search_index';
	private string $directLinkTable = 'base3_content_direct_link';
	private string $readRolesTable = 'base3_content_read_roles';

	private bool $tablesReady = false;

	/** @var array<string,bool> */
	private array $stopWords = [];

	private array|string|null $phoneticEncoderNameConfig = null;
	private array|string|null $phoneticIntConverterNameConfig = null;

	public function __construct(
		private readonly IDatabase $db,
		private readonly IClassMap $classMap,
		?string $id = null
	) {
		parent::__construct($id);

		$this->stopWordDir = dirname(__DIR__, 2) . '/local/StopWords';
	}

	public static function getName(): string {
		return 'fulltextindexingparseragentresource';
	}

	public function getDescription(): string {
		return 'Parser proxy that forwards to inner parsers and builds a word-level phonetic search index.';
	}

	public function getDockDefinitions(): array {
		return [
			new AgentNodeDock(
				name: self::DOCK_PARSERS,
				description: 'Inner parsers (first match wins by priority).',
				interface: IAgentContentParser::class,
				maxConnections: 99,
				required: true
			),
			new AgentNodeDock(
				name: self::DOCK_LOGGER,
				description: 'Optional logger.',
				interface: ILogger::class,
				maxConnections: 1,
				required: false
			),
		];
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$stopDir = $config['stopword_dir'] ?? null;
		if (is_string($stopDir) && trim($stopDir) !== '') {
			$this->stopWordDir = rtrim(trim($stopDir), '/');
		}

		$this->phoneticEncoderNameConfig = $config['phonetic_encoder'] ?? null;
		$this->phoneticIntConverterNameConfig = $config['phonetic_int_converter'] ?? null;
	}

	public function init(array $resources, IAgentContext $context): void {
		$this->logger = $resources[self::DOCK_LOGGER][0] ?? null;

		$this->parsers = $resources[self::DOCK_PARSERS] ?? [];
		usort($this->parsers, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

		$this->phonetic = $this->loadPhoneticEncoder();
		$this->converter = $this->loadPhoneticIntConverter();

		$this->ensureTables();
		$this->log('Initialized (parsers=' . count($this->parsers) . ')');
	}

	public function getPriority(): int {
		return (int)($this->config['priority'] ?? 0);
	}

	public function supports(AgentContentItem $item): bool {
		return $this->findFirstSupportingParser($item) !== null;
	}

	public function parse(AgentContentItem $item): AgentParsedContent {
		$parser = $this->findFirstSupportingParser($item);
		if (!$parser) {
			throw new \RuntimeException('FullTextIndexingParserAgentResource: no inner parser supports this item.');
		}

		$parsed = $parser->parse($item);

		$this->ensureTables();
		$this->ensureStopWordsLoaded($this->getLanguage($item));

		$this->indexParsedContent($item, $parsed);

		return $parsed;
	}

	private function loadPhoneticEncoder(): IPhoneticEncoder {
		$name = $this->resolveNameConfig($this->phoneticEncoderNameConfig);
		if ($name === '') {
			throw new \RuntimeException('FullTextIndexingParserAgentResource: missing config phonetic_encoder.');
		}

		$inst = $this->classMap->getInstanceByInterfaceName(IPhoneticEncoder::class, $name);
		if (!$inst || !($inst instanceof IPhoneticEncoder)) {
			throw new \RuntimeException("FullTextIndexingParserAgentResource: phonetic_encoder '{$name}' not found.");
		}

		return $inst;
	}

	private function loadPhoneticIntConverter(): IPhoneticIntConverter {
		$name = $this->resolveNameConfig($this->phoneticIntConverterNameConfig);
		if ($name === '') {
			throw new \RuntimeException('FullTextIndexingParserAgentResource: missing config phonetic_int_converter.');
		}

		$inst = $this->classMap->getInstanceByInterfaceName(IPhoneticIntConverter::class, $name);
		if (!$inst || !($inst instanceof IPhoneticIntConverter)) {
			throw new \RuntimeException("FullTextIndexingParserAgentResource: phonetic_int_converter '{$name}' not found.");
		}

		return $inst;
	}

	private function resolveNameConfig(array|string|null $cfg): string {
		if (is_string($cfg)) {
			return strtolower(trim($cfg));
		}

		if (is_array($cfg)) {
			$value = $cfg['value'] ?? null;
			if (is_string($value)) {
				return strtolower(trim($value));
			}
		}

		return '';
	}

	private function indexParsedContent(AgentContentItem $item, AgentParsedContent $parsed): void {
		if (!$this->phonetic || !$this->converter) {
			throw new \RuntimeException('FullTextIndexingParserAgentResource: missing phonetic services.');
		}

		$uuidHex = $this->requireContentUuidHex($item);

		$text = $this->extractText($parsed);
		if ($text === '') {
			return;
		}

		$words = $this->splitIntoWords($text);
		if (!$words) {
			return;
		}

		$words = $this->filterStopWords($words);
		if (!$words) {
			return;
		}

		$unique = array_values(array_unique($words));
		if (!$unique) {
			return;
		}

		$contentIdSql = "UNHEX('" . $this->esc($uuidHex) . "')";

		$this->upsertDirectLink($item, $contentIdSql);
		$this->replaceReadRoles($item, $contentIdSql);

		foreach ($unique as $w) {
			$code = trim($this->phonetic->encode($w));
			if ($code === '') {
				continue;
			}

			$tokenInt = (int)$this->converter->toInt($code);
			if ($tokenInt <= 0) {
				continue;
			}

			$this->insertToken($contentIdSql, $tokenInt);
		}
	}

	private function insertToken(string $contentIdSql, int $tokenInt): void {
		$this->db->connect();

		$table = $this->escapeIdent($this->searchTable);
		$tokenInt = (int)$tokenInt;

		$sql = "INSERT IGNORE INTO {$table} (content_id, token_int)
				VALUES ({$contentIdSql}, {$tokenInt})";

		$this->db->nonQuery($sql);
	}

	private function upsertDirectLink(AgentContentItem $item, string $contentIdSql): void {
		$link = $item->metadata[self::META_DIRECT_LINK] ?? null;
		if (!is_string($link) || trim($link) === '') {
			return;
		}

		$link = trim($link);

		$this->db->connect();

		$table = $this->escapeIdent($this->directLinkTable);
		$linkEsc = $this->esc($link);

		$sql = "INSERT INTO {$table} (content_id, direct_link)
				VALUES ({$contentIdSql}, '{$linkEsc}')
				ON DUPLICATE KEY UPDATE direct_link = VALUES(direct_link)";

		$this->db->nonQuery($sql);
	}

	private function replaceReadRoles(AgentContentItem $item, string $contentIdSql): void {
		$roles = $item->metadata[self::META_READ_ROLES] ?? null;
		if (!is_array($roles) || !$roles) {
			return;
		}

		$roleIds = [];
		foreach ($roles as $r) {
			$id = (int)$r;
			if ($id > 0) {
				$roleIds[] = $id;
			}
		}

		$roleIds = array_values(array_unique($roleIds));
		if (!$roleIds) {
			return;
		}

		$this->db->connect();

		$table = $this->escapeIdent($this->readRolesTable);

		$this->db->nonQuery("DELETE FROM {$table} WHERE content_id = {$contentIdSql}");

		foreach ($roleIds as $rid) {
			$rid = (int)$rid;
			$sql = "INSERT IGNORE INTO {$table} (content_id, role_id)
					VALUES ({$contentIdSql}, {$rid})";
			$this->db->nonQuery($sql);
		}
	}

	private function extractText(AgentParsedContent $parsed): string {
		$text = trim((string)($parsed->text ?? ''));
		if ($text !== '') {
			return $text;
		}

		$structured = $parsed->structured ?? null;
		if (is_array($structured) || is_object($structured)) {
			$json = json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			return trim((string)$json);
		}

		return '';
	}

	/**
	 * Letters-only normalization (numbers removed for now).
	 *
	 * @return string[]
	 */
	private function splitIntoWords(string $text): array {
		$text = mb_strtolower($text);

		$text = preg_replace('/[^\p{L}]+/u', ' ', $text) ?? $text;
		$text = trim($text);

		if ($text === '') {
			return [];
		}

		$parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (!$parts) {
			return [];
		}

		$out = [];
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			if (mb_strlen($p) < 2) {
				continue;
			}
			$out[] = $p;
		}

		return $out;
	}

	/**
	 * @param string[] $words
	 * @return string[]
	 */
	private function filterStopWords(array $words): array {
		if (!$this->stopWords) {
			return $words;
		}

		$out = [];
		foreach ($words as $w) {
			if (isset($this->stopWords[$w])) {
				continue;
			}
			$out[] = $w;
		}

		return $out;
	}

	private function ensureStopWordsLoaded(string $lang): void {
		if ($this->stopWords) {
			return;
		}

		$file = $this->stopWordDir . '/stopwords.' . $lang . '.ini';
		if (!is_file($file)) {
			$this->log('Stopwords file not found: ' . $file);
			return;
		}

		$data = @parse_ini_file($file, true, INI_SCANNER_TYPED);
		if (!is_array($data)) {
			$this->log('Stopwords ini parse failed: ' . $file);
			return;
		}

		$list = $data['stopwords']['words'] ?? null;
		if (!is_array($list) || !$list) {
			return;
		}

		$map = [];
		foreach ($list as $w) {
			if (!is_string($w)) {
				continue;
			}
			$w = trim(mb_strtolower($w));
			if ($w === '') {
				continue;
			}
			$map[$w] = true;
		}

		$this->stopWords = $map;
	}

	private function getLanguage(AgentContentItem $item): string {
		$lang = $item->metadata[self::META_LANG] ?? null;
		if (is_string($lang) && trim($lang) !== '') {
			return strtolower(trim($lang));
		}
		return 'de';
	}

	private function requireContentUuidHex(AgentContentItem $item): string {
		$uuidHex = $item->metadata[self::META_CONTENT_UUID] ?? null;
		if (!is_string($uuidHex)) {
			$id = (string)($item->id ?? '(no-id)');
			throw new \RuntimeException("Missing metadata '" . self::META_CONTENT_UUID . "' for item {$id}");
		}

		$uuidHex = strtoupper(trim($uuidHex));
		if (!$this->isHex32($uuidHex)) {
			$id = (string)($item->id ?? '(no-id)');
			throw new \RuntimeException("Invalid content_uuid hex for item {$id}");
		}

		return $uuidHex;
	}

	private function findFirstSupportingParser(AgentContentItem $item): ?IAgentContentParser {
		foreach ($this->parsers as $parser) {
			if ($parser->supports($item)) {
				return $parser;
			}
		}
		return null;
	}

	private function ensureTables(): void {
		if ($this->tablesReady) {
			return;
		}

		$this->db->connect();

		$this->ensureSearchIndexTable();
		$this->ensureDirectLinkTable();
		$this->ensureReadRolesTable();

		$this->tablesReady = true;
	}

	private function ensureSearchIndexTable(): void {
		$table = $this->escapeIdent($this->searchTable);

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
				content_id BINARY(16) NOT NULL,
				token_int BIGINT NOT NULL,
				PRIMARY KEY (content_id, token_int),
				KEY idx_token (token_int)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		$this->db->nonQuery($sql);
	}

	private function ensureDirectLinkTable(): void {
		$table = $this->escapeIdent($this->directLinkTable);

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
				content_id BINARY(16) NOT NULL,
				direct_link VARCHAR(512) NOT NULL,
				PRIMARY KEY (content_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		$this->db->nonQuery($sql);
	}

	private function ensureReadRolesTable(): void {
		$table = $this->escapeIdent($this->readRolesTable);

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
				content_id BINARY(16) NOT NULL,
				role_id INT NOT NULL,
				PRIMARY KEY (content_id, role_id),
				KEY idx_role (role_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		$this->db->nonQuery($sql);
	}

	private function isHex32(string $hex): bool {
		return strlen($hex) === 32 && ctype_xdigit($hex);
	}

	private function escapeIdent(string $name): string {
		$clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
		if ($clean === '') {
			$clean = 'base3_content_search_index';
		}
		return '`' . $clean . '`';
	}

	private function esc(string $value): string {
		return (string)$this->db->escape($value);
	}

	private function log(string $msg): void {
		if (!$this->logger) {
			return;
		}
		$this->logger->log('FullTextIndexingParserAgentResource', '[' . $this->getName() . '|' . $this->getId() . '] ' . $msg);
	}
}
