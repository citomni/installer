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

namespace CitOmni\Installer\Support;

use CitOmni\Installer\Exception\InstallerException;

/**
 * Renders scaffold stubs by substituting {{PLACEHOLDER}} tokens with resolved values.
 *
 * This is NOT a template engine: there is no logic, no expressions, no eval, and no
 * arbitrary PHP (contract §7). Substitution is a single pass over the raw stub bytes;
 * a value that itself contains "{{...}}" is written verbatim and never re-scanned.
 *
 * Placeholder grammar (exact):
 * - A placeholder is any "{{" ... "}}" sequence in the stub.
 * - The inner text MUST match [A-Z][A-Z0-9_]* exactly (no surrounding whitespace,
 *   no lowercase, no punctuation). Anything else is a MALFORMED token and fails.
 * - A well-formed token whose key is absent from the supplied values is an UNKNOWN
 *   placeholder and fails.
 * - Consequence: a stub MUST NOT contain a literal "{{...}}" that is not a real
 *   placeholder. This is deliberate — it guarantees no unresolved/typo'd token can
 *   silently ship into a written file.
 *
 * Value handling:
 * - The supplied map IS the set of known placeholders; resolution priority (CLI ->
 *   config -> composer.json -> CitOmni config -> defaults, §7) lives in the caller,
 *   not here. Extra/unused keys in the map are ignored.
 * - Values are inserted verbatim (no escaping, no quoting). Backslashes and "$" in
 *   values are NOT interpreted (substitution uses a callback, not backreferences).
 *
 * Line endings:
 * - The renderer performs NO line-ending normalization (§6). Stubs ship LF; whatever
 *   bytes are read are what get substituted. readStub() returns raw bytes unchanged.
 *
 * Notes:
 * - Support-layer IO helper, instantiated explicitly. Not a service. No App dependency.
 * - render()/placeholdersIn() are pure (string in, string/array out). readStub() is the
 *   only method that touches the filesystem.
 */
final class ScaffoldRenderer {

	/** Matches any "{{ ... }}" block; capture group 1 is the raw inner text. */
	private const TOKEN_PATTERN = '/\{\{(.*?)\}\}/s';

	/** Canonical placeholder key: uppercase, digits, underscores; must start with a letter. */
	private const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';


	/**
	 * Render a stub by substituting every placeholder with its resolved value.
	 *
	 * Behavior:
	 * - Single pass; replacement text is never re-scanned for further placeholders.
	 * - Malformed token (inner not matching the key grammar) -> InstallerException.
	 * - Well-formed token with no value in $values -> InstallerException.
	 * - A used value that is not a string -> InstallerException.
	 *
	 * @param  string                $stub    Raw stub bytes.
	 * @param  array<string,mixed>   $values  Resolved placeholders (key => value).
	 * @return string                         Rendered bytes.
	 * @throws InstallerException             On malformed/unknown placeholder or PCRE failure.
	 */
	public function render(string $stub, array $values): string {
		$result = \preg_replace_callback(
			self::TOKEN_PATTERN,
			function (array $m) use ($values): string {
				$token = $m[0];
				$key   = $m[1];

				if (\preg_match(self::KEY_PATTERN, $key) !== 1) {
					throw new InstallerException(\sprintf('Malformed placeholder token: %s', $token));
				}

				if (!\array_key_exists($key, $values)) {
					throw new InstallerException(\sprintf('Unknown placeholder: {{%s}}', $key));
				}

				$value = $values[$key];
				if (!\is_string($value)) {
					throw new InstallerException(\sprintf(
						'Placeholder {{%s}} must resolve to a string, got %s',
						$key,
						\get_debug_type($value)
					));
				}

				return $value;
			},
			$stub
		);

		if ($result === null) {
			throw new InstallerException(\sprintf('Failed to render stub (PCRE error: %s)', \preg_last_error_msg()));
		}

		return $result;
	}


	/**
	 * Return the distinct, sorted placeholder keys referenced by a stub.
	 *
	 * Validates token grammar (malformed -> error) but does NOT check whether the keys
	 * are resolvable; that is render()'s job. Useful for building the placeholder
	 * snapshot stored in state (§5) and for diagnostics.
	 *
	 * @param  string $stub  Raw stub bytes.
	 * @return list<string>  Sorted unique placeholder keys.
	 * @throws InstallerException  On a malformed token or PCRE failure.
	 */
	public function placeholdersIn(string $stub): array {
		if (\preg_match_all(self::TOKEN_PATTERN, $stub, $matches) === false) {
			throw new InstallerException(\sprintf('Failed to scan stub (PCRE error: %s)', \preg_last_error_msg()));
		}

		$found = [];
		foreach ($matches[1] as $i => $key) {
			if (\preg_match(self::KEY_PATTERN, $key) !== 1) {
				throw new InstallerException(\sprintf('Malformed placeholder token: %s', $matches[0][$i]));
			}
			$found[$key] = true;
		}

		$keys = \array_keys($found);
		\sort($keys);

		return $keys;
	}


	/**
	 * Read a scaffold stub from disk as raw bytes.
	 *
	 * Performs NO transformation: line endings and trailing bytes are preserved exactly,
	 * so the caller can hash the result as the authoritative stub_checksum (§6).
	 *
	 * A missing or unreadable source surfaces here as the "source missing" failure (§9);
	 * path safety is the caller's responsibility (resolve via PathGuard first).
	 *
	 * @param  string $path  Absolute path to the stub (already resolved/validated).
	 * @return string        Raw stub bytes.
	 * @throws InstallerException  When the stub is missing or unreadable.
	 */
	public function readStub(string $path): string {
		if (!\is_file($path)) {
			throw new InstallerException(\sprintf('Scaffold source stub not found: %s', $path));
		}

		$bytes = \file_get_contents($path);
		if ($bytes === false) {
			throw new InstallerException(\sprintf('Scaffold source stub is not readable: %s', $path));
		}

		return $bytes;
	}

}
