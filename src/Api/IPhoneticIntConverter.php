<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Api;

use Base3\Api\IBase;

interface IPhoneticIntConverter extends IBase {

	/**
	 * Convert a phonetic code string into an integer representation.
	 */
	public function toInt(string $phoneticCode): int;
}
