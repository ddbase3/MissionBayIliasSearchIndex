<?php declare(strict_types=1);

namespace MissionBayIliasSearchIndex\Api;

use Base3\Api\IBase;

interface IPhoneticEncoder extends IBase {

	/**
	 * Encode a single word into a phonetic code string.
	 */
	public function encode(string $word): string;
}
