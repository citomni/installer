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
 * Pure SHA-256 checksum helpers for scaffold identity and drift detection.
 *
 * The installer stores two checksums per managed file (see contract §6):
 *   - stub_checksum     = hash of the raw stub (pre-render); detects upstream change.
 *   - rendered_checksum = hash of the exact bytes written to disk; detects local edits.
 *
 * Both are persisted in the canonical "sha256:<64 lowercase hex>" form so the state
 * file stays self-describing and forward-compatible if another algorithm is ever
 * introduced.
 *
 * Behavior:
 * - Hashing is performed on the exact byte string passed in.
 * - This class performs NO line-ending normalization and NO transformation of any
 *   kind. Line-ending policy (LF stubs, .gitattributes pinning) is the caller's
 *   responsibility; passing in transformed bytes would silently change the hash.
 *
 * Notes:
 * - Pure utility: no IO, no App, no state. binary-safe (NUL-safe) input.
 * - Checksums are not secrets; comparison is a plain normalized equality check.
 *   Constant-time comparison would be cargo-culting here and is deliberately omitted.
 */
final class Checksum {

	/** Hash algorithm pinned by the installer state contract. */
	public const ALGO = 'sha256';

	/**
	 * Not instantiable: pure static utility.
	 */
	private function __construct() {
	}


	/**
	 * Compute the canonical checksum for a byte sequence.
	 *
	 * @param  string $bytes  Raw bytes to hash (stub bytes or rendered output bytes).
	 * @return string         "sha256:<64 lowercase hex>".
	 */
	public static function sha256(string $bytes): string {
		return self::ALGO . ':' . \hash(self::ALGO, $bytes);
	}


	/**
	 * Compare two checksum strings for equality.
	 *
	 * Behavior:
	 * - Case-insensitive on the hex digits (PHP emits lowercase, but stored values
	 *   may originate elsewhere). Algorithm prefix must match.
	 * - Malformed inputs simply fail to match; use isValid() to validate format.
	 *
	 * @param  string $a
	 * @param  string $b
	 * @return bool
	 */
	public static function equals(string $a, string $b): bool {
		return \strtolower($a) === \strtolower($b);
	}


	/**
	 * Convenience: hash the given bytes and compare against a stored checksum.
	 *
	 * This is the primitive behind local-edit / stub-drift detection (§7): hash the
	 * current bytes and compare to the stored rendered_checksum or stub_checksum.
	 *
	 * @param  string $bytes             Current bytes (e.g. read from disk by the caller).
	 * @param  string $expectedChecksum  Stored "sha256:<hex>" value to compare against.
	 * @return bool                      True iff the bytes hash to the expected checksum.
	 */
	public static function matches(string $bytes, string $expectedChecksum): bool {
		return self::equals(self::sha256($bytes), $expectedChecksum);
	}


	/**
	 * Validate that a string is a well-formed canonical SHA-256 checksum.
	 *
	 * Intended for state-file validation on read (§5: "validate on read").
	 *
	 * @param  string $checksum
	 * @return bool   True for "sha256:" followed by exactly 64 lowercase hex digits.
	 */
	public static function isValid(string $checksum): bool {
		return (bool)\preg_match('/^sha256:[0-9a-f]{64}$/', $checksum);
	}

}
