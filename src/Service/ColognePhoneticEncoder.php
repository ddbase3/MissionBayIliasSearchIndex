<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Service;

use MissionBayIliasSearchIndex\Api\IPhoneticEncoder;

final class ColognePhoneticEncoder implements IPhoneticEncoder {

	public static function getName(): string {
		return 'colognephoneticencoder';
	}

	public function encode(string $word): string {
		$word = $this->normalize($word);
		if ($word === '') {
			return '';
		}

		$chars = str_split($word);
		$out = [];

		$prevCode = '';
		$prevChar = '';

		$count = count($chars);
		for ($i = 0; $i < $count; $i++) {
			$c = $chars[$i];
			$n = ($i + 1 < $count) ? $chars[$i + 1] : '';
			$p = ($i > 0) ? $chars[$i - 1] : '';

			$code = $this->mapChar($c, $p, $n, $i === 0);

			if ($code === '') {
				$prevChar = $c;
				continue;
			}

			// "X" can map to "48"
			$digits = str_split($code);
			foreach ($digits as $d) {
				if ($d === $prevCode) {
					continue;
				}
				$out[] = $d;
				$prevCode = $d;
			}

			$prevChar = $c;
		}

		if (!$out) {
			return '';
		}

		// Remove all '0' except if it's the first code
		$first = $out[0] ?? '';
		$filtered = [];

		foreach ($out as $idx => $d) {
			if ($d === '0' && $idx !== 0) {
				continue;
			}
			$filtered[] = $d;
		}

		if (!$filtered) {
			return '';
		}

		// Keep initial '0' if present, otherwise already removed
		if ($first !== '0') {
			$filtered = array_values(array_filter($filtered, static fn($d) => $d !== '0'));
		}

		return implode('', $filtered);
	}

	private function normalize(string $word): string {
		$word = trim($word);
		if ($word === '') {
			return '';
		}

		$word = strtoupper($word);

		// German normalization
		$word = strtr($word, [
			'Ä' => 'A',
			'Ö' => 'O',
			'Ü' => 'U',
			'ß' => 'S',
		]);

		// Keep A-Z only
		$word = preg_replace('/[^A-Z]/', '', $word) ?? '';
		return $word;
	}

	private function mapChar(string $c, string $p, string $n, bool $isFirst): string {
		// Vowels
		if ($c === 'A' || $c === 'E' || $c === 'I' || $c === 'J' || $c === 'O' || $c === 'U' || $c === 'Y') {
			return '0';
		}

		// Ignore H
		if ($c === 'H') {
			return '';
		}

		if ($c === 'B') {
			return '1';
		}

		if ($c === 'P') {
			return ($n === 'H') ? '3' : '1';
		}

		if ($c === 'D' || $c === 'T') {
			if ($n === 'C' || $n === 'S' || $n === 'Z') {
				return '8';
			}
			return '2';
		}

		if ($c === 'F' || $c === 'V' || $c === 'W') {
			return '3';
		}

		if ($c === 'G' || $c === 'K' || $c === 'Q') {
			return '4';
		}

		if ($c === 'C') {
			// Start special handling
			if ($isFirst) {
				return $this->isCFollowedByHard($n) ? '4' : '8';
			}

			// After S or Z: always 8
			if ($p === 'S' || $p === 'Z') {
				return '8';
			}

			return $this->isCFollowedByHard($n) ? '4' : '8';
		}

		if ($c === 'X') {
			// If preceded by C/K/Q: treat as 8
			if ($p === 'C' || $p === 'K' || $p === 'Q') {
				return '8';
			}
			return '48';
		}

		if ($c === 'L') {
			return '5';
		}

		if ($c === 'M' || $c === 'N') {
			return '6';
		}

		if ($c === 'R') {
			return '7';
		}

		if ($c === 'S' || $c === 'Z') {
			return '8';
		}

		return '';
	}

	private function isCFollowedByHard(string $next): bool {
		return $next === 'A'
			|| $next === 'H'
			|| $next === 'K'
			|| $next === 'L'
			|| $next === 'O'
			|| $next === 'Q'
			|| $next === 'R'
			|| $next === 'U'
			|| $next === 'X';
	}
}
