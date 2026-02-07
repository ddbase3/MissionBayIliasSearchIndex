<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Service;

use MissionBayIliasSearchIndex\Api\IPhoneticIntConverter;

final class ReversePhoneticStringToIntConverter implements IPhoneticIntConverter {

	public static function getName(): string {
		return 'reversephoneticstringtointconverter';
	}

	public function toInt(string $phoneticCode): int {
		$phoneticCode = trim($phoneticCode);
		if ($phoneticCode === '') {
			return 0;
		}

		$digits = preg_replace('/[^0-9]/', '', $phoneticCode) ?? '';
		if ($digits === '') {
			return 0;
		}

		$rev = strrev($digits);
		return (int)$rev;
	}
}
