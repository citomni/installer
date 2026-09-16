<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Installer\Util;

/**
 * Compare PHP source while ignoring comments, PHPDoc, and code whitespace.
 *
 * Behavior:
 * - Tokenizes with TOKEN_PARSE so syntactically invalid PHP is rejected by PHP itself.
 * - Ignores T_COMMENT, T_DOC_COMMENT, and T_WHITESPACE only.
 * - Normalizes the ordinary PHP opening tag so formatting immediately after `<?php`
 *   does not create a functional difference.
 * - Preserves every other token byte-for-byte, including strings, literals, identifiers,
 *   operators, inline HTML, and close tags.
 * - Includes the source line for T_LINE (`__LINE__`) because moving that token changes
 *   its runtime value even when the surrounding significant token stream is unchanged.
 * - Treats `__halt_compiler()` / `__COMPILER_HALT_OFFSET__` as source-byte-sensitive.
 *
 * Notes:
 * - This is deliberately conservative. It proves that the significant token stream is
 *   unchanged; it does not attempt broad semantic equivalence.
 * - Callers decide how parse failures are handled. Migration treats an invalid local file
 *   as manual-review material, while an invalid current scaffold is an installer failure.
 *
 * @throws \ParseError When the source is not valid PHP.
 */
final class PhpSourceComparator {

	/** Token IDs that never participate in migration code-equivalence decisions. */
	private const IGNORED_TOKENS = [
		\T_WHITESPACE => true,
		\T_COMMENT => true,
		\T_DOC_COMMENT => true,
	];

	private function __construct() {
	}


	/**
	 * Determine whether two PHP sources have the same significant token stream.
	 *
	 * @param string $left First PHP source.
	 * @param string $right Second PHP source.
	 * @return bool Whether the significant token streams are identical.
	 * @throws \ParseError When either source is invalid PHP.
	 */
	public static function equivalent(string $left, string $right): bool {
		return self::significantTokens($left) === self::significantTokens($right);
	}


	/**
	 * Return the significant PHP token stream for deterministic comparison.
	 *
	 * @param string $source PHP source bytes.
	 * @return array<int,string|array{0:int,1:string}> Significant tokens.
	 * @throws \ParseError When the source is invalid PHP.
	 */
	public static function significantTokens(string $source): array {
		$tokens = \token_get_all($source, \TOKEN_PARSE);
		$out = [];

		foreach ($tokens as $token) {
			if (\is_string($token)) {
				$out[] = $token;
				continue;
			}

			$id = $token[0];
			if (isset(self::IGNORED_TOKENS[$id])) {
				continue;
			}

			if ($id === \T_OPEN_TAG) {
				$out[] = [\T_OPEN_TAG, '<?php'];
				continue;
			}
			if ($id === \T_LINE) {
				$out[] = [\T_LINE, $token[1] . '@' . (string)$token[2]];
				continue;
			}
			if ($id === \T_HALT_COMPILER || ($id === \T_STRING && \strcasecmp($token[1], '__COMPILER_HALT_OFFSET__') === 0)) {
				// __COMPILER_HALT_OFFSET__ depends on byte offsets. Any textual change can
				// therefore be functional even when ordinary significant tokens match.
				$out[] = [$id, $token[1] . '@source:' . \hash('sha256', $source)];
				continue;
			}

			$out[] = [$id, $token[1]];
		}

		return $out;
	}
}
