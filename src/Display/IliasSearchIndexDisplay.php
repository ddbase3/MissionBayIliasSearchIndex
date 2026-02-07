<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Display;

use Base3\Api\IClassMap;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use MissionBayIliasSearchIndex\Api\IPhoneticEncoder;
use MissionBayIliasSearchIndex\Api\IPhoneticIntConverter;

final class IliasSearchIndexDisplay implements IDisplay {

	private const DEFAULT_ENCODER = 'colognephoneticencoder';
	private const DEFAULT_CONVERTER = 'reversephoneticstringtointconverter';

	private const MIN_QUERY_CHARS = 3;
	private const MAX_RESULTS = 10;
	private const MAX_WORDS = 6;

	private string $searchTable = 'base3_content_search_index';
	private string $directLinkTable = 'base3_content_direct_link';
	private string $readRolesTable = 'base3_content_read_roles';

	public function __construct(
		private readonly IDatabase $db,
		private readonly IClassMap $classMap,
		private readonly IMvcView $view,
		private readonly IRequest $request,
		private readonly IConfiguration $config
	) {}

	public static function getName(): string {
		return 'iliassearchindexdisplay';
	}

	public function getHelp(): string {
		return 'Search UI for base3_content_search_index + base3_content_direct_link (AJAX).';
	}

	public function setData($data) {
		// no-op
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$out = strtolower((string)$out);

		if ($out === 'json') {
			return $this->handleJson();
		}

		return $this->handleHtml();
	}

	// ---------------------------------------------------------------------
	// HTML
	// ---------------------------------------------------------------------

	private function handleHtml(): string {
		$this->view->setPath(DIR_PLUGIN . 'MissionBayIliasSearchIndex');
		$this->view->setTemplate('Display/IliasSearchIndexDisplay.php');

		$endpoint = $this->buildEndpointBase();

		$this->view->assign('endpoint', $endpoint);
		$this->view->assign('minChars', self::MIN_QUERY_CHARS);
		$this->view->assign('maxResults', self::MAX_RESULTS);

		return $this->view->loadTemplate();
	}

	// ---------------------------------------------------------------------
	// JSON
	// ---------------------------------------------------------------------

	private function handleJson(): string {
		$action = strtolower((string)($this->request->get('action') ?? ''));

		try {
			return match ($action) {
				'search' => $this->jsonSuccess([
					'q' => (string)($this->request->get('q') ?? ''),
					'items' => $this->search((string)($this->request->get('q') ?? '')),
				]),
				default => $this->jsonError("Unknown action '{$action}'. Use: search"),
			};
		} catch (\Throwable $e) {
			return $this->jsonError('Exception: ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------------------
	// Core logic
	// ---------------------------------------------------------------------

	private function search(string $q): array {
		$q = $this->normalizeQuery($q);
		if (mb_strlen($q) < self::MIN_QUERY_CHARS) {
			return [];
		}

		$words = $this->splitWords($q);
		if (!$words) {
			return [];
		}

		$encoder = $this->loadPhoneticEncoder();
		$converter = $this->loadPhoneticIntConverter();

		$terms = $this->buildSearchTerms($words, $encoder, $converter);
		if (!$terms) {
			return [];
		}

		$this->db->connect();
		if (!$this->db->connected()) {
			return [];
		}

		$si = $this->escapeIdent($this->searchTable);
		$dl = $this->escapeIdent($this->directLinkTable);

		$whereParts = [];
		$havingParts = [];

		foreach ($terms as $i => $t) {
			$pow = $this->pow10($t['len']);
			$modExpr = "(s.token_int % {$pow})";

			$whereParts[] = "({$modExpr} = {$t['value']})";
			$havingParts[] = "SUM({$modExpr} = {$t['value']}) > 0";
		}

		$where = implode(' OR ', $whereParts);
		$having = implode(' AND ', $havingParts);

		$limit = (int)self::MAX_RESULTS;

		$sql = "SELECT
					HEX(dl.content_id) AS content_id,
					dl.direct_link,
					dl.title,
					dl.description
				FROM (
					SELECT s.content_id
					FROM {$si} s
					WHERE {$where}
					GROUP BY s.content_id
					HAVING {$having}
					LIMIT {$limit}
				) hit
				INNER JOIN {$dl} dl ON dl.content_id = hit.content_id
				ORDER BY dl.title ASC
				LIMIT {$limit}";

		$rows = $this->db->multiQuery($sql) ?: [];

		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'content_id' => (string)($r['content_id'] ?? ''),
				'direct_link' => (string)($r['direct_link'] ?? ''),
				'title' => (string)($r['title'] ?? ''),
				'description' => (string)($r['description'] ?? ''),
			];
		}

		return $out;
	}

	/**
	 * @return array<int,array{value:int,len:int}>
	 */
	private function buildSearchTerms(array $words, IPhoneticEncoder $encoder, IPhoneticIntConverter $converter): array {
		$out = [];

		foreach ($words as $w) {
			if (mb_strlen($w) < 2) {
				continue;
			}

			$code = trim((string)$encoder->encode($w));
			if ($code === '') {
				continue;
			}

			$token = (int)$converter->toInt($code);
			if ($token <= 0) {
				continue;
			}

			$len = strlen((string)$token);
			if ($len <= 0) {
				continue;
			}

			$out[] = ['value' => $token, 'len' => $len];
		}

		if (!$out) {
			return [];
		}

		// de-dup terms (value+len)
		$map = [];
		foreach ($out as $t) {
			$key = $t['value'] . ':' . $t['len'];
			$map[$key] = $t;
		}

		return array_values($map);
	}

	// ---------------------------------------------------------------------
	// Phonetic services
	// ---------------------------------------------------------------------

	private function loadPhoneticEncoder(): IPhoneticEncoder {
		$name = self::DEFAULT_ENCODER;

		$inst = $this->classMap->getInstanceByInterfaceName(IPhoneticEncoder::class, $name);
		if (!$inst || !($inst instanceof IPhoneticEncoder)) {
			throw new \RuntimeException("IliasSearchIndexDisplay: phonetic_encoder '{$name}' not found.");
		}

		return $inst;
	}

	private function loadPhoneticIntConverter(): IPhoneticIntConverter {
		$name = self::DEFAULT_CONVERTER;

		$inst = $this->classMap->getInstanceByInterfaceName(IPhoneticIntConverter::class, $name);
		if (!$inst || !($inst instanceof IPhoneticIntConverter)) {
			throw new \RuntimeException("IliasSearchIndexDisplay: phonetic_int_converter '{$name}' not found.");
		}

		return $inst;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function normalizeQuery(string $q): string {
		$q = trim($q);
		$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
		return $q;
	}

	/**
	 * @return string[]
	 */
	private function splitWords(string $q): array {
		$q = mb_strtolower($q);

		// letters only (same rule as indexing right now)
		$q = preg_replace('/[^\p{L}]+/u', ' ', $q) ?? $q;
		$q = trim($q);

		if ($q === '') {
			return [];
		}

		$parts = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
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
			if (count($out) >= self::MAX_WORDS) {
				break;
			}
		}

		return $out;
	}

	private function pow10(int $len): int {
		$len = max(1, min(18, $len)); // BIGINT-safe range
		$out = 1;
		for ($i = 0; $i < $len; $i++) {
			$out *= 10;
		}
		return $out;
	}

	private function escapeIdent(string $name): string {
		$clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
		if ($clean === '') {
			$clean = 'base3_content_search_index';
		}
		return '`' . $clean . '`';
	}

	private function buildEndpointBase(): string {
		$baseEndpoint = '';
		try {
			$baseEndpoint = (string)($this->config->get('base')['endpoint'] ?? '');
		} catch (\Throwable) {
			$baseEndpoint = '';
		}

		$baseEndpoint = trim($baseEndpoint);
		if ($baseEndpoint === '') {
			$baseEndpoint = 'base3.php';
		}

		$sep = str_contains($baseEndpoint, '?') ? '&' : '?';
		return $baseEndpoint . $sep . 'name=' . rawurlencode(self::getName()) . '&out=json&action=';
	}

	private function jsonSuccess(array $data): string {
		return json_encode([
			'status' => 'ok',
			'timestamp' => gmdate('c'),
			'data' => $data,
		], JSON_UNESCAPED_UNICODE);
	}

	private function jsonError(string $message): string {
		return json_encode([
			'status' => 'error',
			'timestamp' => gmdate('c'),
			'message' => $message,
		], JSON_UNESCAPED_UNICODE);
	}
}
