<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Resource;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use MissionBay\Agent\AgentNodeDock;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentVectorStore;
use MissionBay\Dto\AgentEmbeddingChunk;
use MissionBay\Resource\AbstractAgentResource;

final class SearchIndexVectorStoreProxyAgentResource extends AbstractAgentResource implements IAgentVectorStore {

	private const DOCK_STORE = 'store';
	private const DOCK_LOGGER = 'logger';

	private const META_CONTENT_UUID = 'content_uuid';

	private ?IAgentVectorStore $store = null;
	private ?ILogger $logger = null;

	private string $searchTable = 'base3_content_search_index';
	private string $directLinkTable = 'base3_content_direct_link';
	private string $readRolesTable = 'base3_content_read_roles';

	private bool $tablesReady = false;

	public function __construct(
		private readonly IDatabase $db,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'searchindexvectorstoreproxyagentresource';
	}

	public function getDescription(): string {
		return 'Vector store proxy that deletes the DB-backed content search index on deleteByFilter(content_uuid).';
	}

	public function getDockDefinitions(): array {
		return [
			new AgentNodeDock(
				name: self::DOCK_STORE,
				description: 'The real vector store behind this proxy.',
				interface: IAgentVectorStore::class,
				maxConnections: 1,
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

		$st = $config['search_table'] ?? null;
		if (is_string($st) && trim($st) !== '') {
			$this->searchTable = trim($st);
		}

		$dl = $config['direct_link_table'] ?? null;
		if (is_string($dl) && trim($dl) !== '') {
			$this->directLinkTable = trim($dl);
		}

		$rr = $config['read_roles_table'] ?? null;
		if (is_string($rr) && trim($rr) !== '') {
			$this->readRolesTable = trim($rr);
		}
	}

	public function init(array $resources, IAgentContext $context): void {
		$this->store = $resources[self::DOCK_STORE][0] ?? null;
		$this->logger = $resources[self::DOCK_LOGGER][0] ?? null;

		if (!$this->store) {
			throw new \RuntimeException('SearchIndexVectorStoreProxyAgentResource: Missing dock "store".');
		}

		$this->ensureTables();
		$this->log('Initialized (store=' . get_class($this->store) . ')');
	}

	// ---------------------------------------------------------
	// IAgentVectorStore (proxy)
	// ---------------------------------------------------------

	public function upsert(AgentEmbeddingChunk $chunk): void {
		$this->requireStore()->upsert($chunk);
	}

	public function existsByHash(string $collectionKey, string $hash): bool {
		return $this->requireStore()->existsByHash($collectionKey, $hash);
	}

	public function existsByFilter(string $collectionKey, array $filter): bool {
		return $this->requireStore()->existsByFilter($collectionKey, $filter);
	}

	public function deleteByFilter(string $collectionKey, array $filter): int {
		$this->ensureTables();

		$uuids = $this->extractContentUuidsFromFilter($filter);
		foreach ($uuids as $uuidHex) {
			$this->deleteIndexByContentUuidHex($uuidHex);
		}

		return $this->requireStore()->deleteByFilter($collectionKey, $filter);
	}

	public function search(string $collectionKey, array $vector, int $limit = 3, ?float $minScore = null, ?array $filterSpec = null): array {
		return $this->requireStore()->search($collectionKey, $vector, $limit, $minScore, $filterSpec);
	}

	public function createCollection(string $collectionKey): void {
		$this->requireStore()->createCollection($collectionKey);
	}

	public function deleteCollection(string $collectionKey): void {
		$this->requireStore()->deleteCollection($collectionKey);
	}

	public function getInfo(string $collectionKey): array {
		return $this->requireStore()->getInfo($collectionKey);
	}

	// ---------------------------------------------------------
	// Internals
	// ---------------------------------------------------------

	private function requireStore(): IAgentVectorStore {
		if (!$this->store) {
			throw new \RuntimeException('SearchIndexVectorStoreProxyAgentResource: Store not initialized.');
		}
		return $this->store;
	}

	/**
	 * @return string[] List of UUID hex strings (32 chars).
	 */
	private function extractContentUuidsFromFilter(array $filter): array {
		$value = $filter[self::META_CONTENT_UUID] ?? null;

		if (is_string($value)) {
			$hex = strtoupper(trim($value));
			return $this->isHex32($hex) ? [$hex] : [];
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $v) {
				if (!is_string($v)) {
					continue;
				}
				$hex = strtoupper(trim($v));
				if ($this->isHex32($hex)) {
					$out[] = $hex;
				}
			}
			return array_values(array_unique($out));
		}

		return [];
	}

	private function deleteIndexByContentUuidHex(string $uuidHex): void {
		if (!$this->isHex32($uuidHex)) {
			return;
		}

		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

		$contentIdSql = "UNHEX('" . $this->esc($uuidHex) . "')";

		$search = $this->escapeIdent($this->searchTable);
		$link = $this->escapeIdent($this->directLinkTable);
		$roles = $this->escapeIdent($this->readRolesTable);

		try {
			$this->db->nonQuery("DELETE FROM {$search} WHERE content_id = {$contentIdSql}");
			$this->db->nonQuery("DELETE FROM {$link} WHERE content_id = {$contentIdSql}");
			$this->db->nonQuery("DELETE FROM {$roles} WHERE content_id = {$contentIdSql}");

			$this->log('Index delete ok content_uuid=' . $uuidHex);
		} catch (\Throwable $e) {
			$this->log('Index delete ERROR content_uuid=' . $uuidHex . ' ' . $e->getMessage());
		}
	}

	private function ensureTables(): void {
		if ($this->tablesReady) {
			return;
		}

		$this->db->connect();
		if (!$this->db->connected()) {
			return;
		}

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
		$this->logger->log('SearchIndexVectorStoreProxyAgentResource', '[' . $this->getName() . '|' . $this->getId() . '] ' . $msg);
	}
}
