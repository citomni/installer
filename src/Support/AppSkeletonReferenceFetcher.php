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

use CitOmni\Installer\Exception\RemoteFetchException;

/**
 * Fetch the current app-skeleton root reference files from one pinned main commit.
 *
 * Behavior:
 * - Resolves citomni/app-skeleton main to a commit SHA first.
 * - Fetches .gitignore, .gitattributes, and composer.json from that exact SHA.
 * - Accepts HTTPS only, verifies TLS, follows no redirects, and bounds response size.
 * - Returns raw bytes so migration can make byte-identical decisions.
 *
 * Notes:
 * - Network IO is deliberately private to this migration-only helper. CitOmni
 *   Infrastructure's Curl service is App-aware, while the installer CLI must remain
 *   bootstrap-independent and usable before a CitOmni App can be booted safely.
 * - ext-curl is optional; callers treat RemoteFetchException as an advisory audit failure.
 */
final class AppSkeletonReferenceFetcher {

	private const COMMIT_API = 'https://api.github.com/repos/citomni/app-skeleton/commits/main';
	private const RAW_BASE = 'https://raw.githubusercontent.com/citomni/app-skeleton/%s/';
	private const FILES = ['.gitignore', '.gitattributes', 'composer.json'];
	private const USER_AGENT = 'CitOmni Installer Migration';
	private const MAX_RESPONSE_BYTES = 1048576;


	/**
	 * Fetch the current reference set.
	 *
	 * @return array{sha:string,files:array<string,array{url:string,bytes:string}>}
	 * @throws RemoteFetchException When GitHub cannot be queried or returned data is invalid.
	 */
	public function fetchCurrent(): array {
		$json = $this->fetch(self::COMMIT_API, [
			'Accept: application/vnd.github+json',
			'X-GitHub-Api-Version: 2022-11-28',
		]);

		try {
			$data = \json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RemoteFetchException('GitHub returned invalid JSON while resolving citomni/app-skeleton main.', 0, $e);
		}

		$sha = \is_array($data) && \is_string($data['sha'] ?? null) ? $data['sha'] : '';
		if (\preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
			throw new RemoteFetchException('GitHub did not return a valid citomni/app-skeleton main commit SHA.');
		}

		$files = [];
		foreach (self::FILES as $path) {
			$url = \sprintf(self::RAW_BASE, $sha) . $this->encodePath($path);
			$files[$path] = [
				'url' => $url,
				'bytes' => $this->fetch($url),
			];
		}

		return ['sha' => $sha, 'files' => $files];
	}


	/**
	 * Fetch one bounded HTTPS text resource.
	 *
	 * @param string $url Absolute HTTPS URL.
	 * @param list<string> $headers Optional request headers.
	 * @return string Response body.
	 * @throws RemoteFetchException On invalid URL, cURL failure, oversized response, or non-2xx response.
	 */
	private function fetch(string $url, array $headers = []): string {
		if (!\function_exists('curl_init')) {
			throw new RemoteFetchException('ext-curl is unavailable; citomni/app-skeleton migration audit cannot be fetched.');
		}

		$parts = \parse_url($url);
		if (!\is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !\is_string($parts['host'] ?? null) || $parts['host'] === '') {
			throw new RemoteFetchException('App-skeleton reference URL must be absolute HTTPS: ' . $url);
		}

		$handle = \curl_init();
		if ($handle === false) {
			throw new RemoteFetchException('Unable to initialize cURL for app-skeleton migration audit.');
		}

		$options = [
			\CURLOPT_URL => $url,
			\CURLOPT_RETURNTRANSFER => true,
			\CURLOPT_FOLLOWLOCATION => false,
			\CURLOPT_CONNECTTIMEOUT => 5,
			\CURLOPT_TIMEOUT => 15,
			\CURLOPT_USERAGENT => self::USER_AGENT,
			\CURLOPT_SSL_VERIFYPEER => true,
			\CURLOPT_SSL_VERIFYHOST => 2,
			\CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
		];
		if ($headers !== []) {
			$options[\CURLOPT_HTTPHEADER] = $headers;
		}

		if (!\curl_setopt_array($handle, $options)) {
			throw new RemoteFetchException('Unable to configure cURL for app-skeleton migration audit: ' . $url);
		}

		$body = \curl_exec($handle);
		if ($body === false) {
			throw new RemoteFetchException(\sprintf(
				'App-skeleton reference request failed for %s: [%d] %s',
				$url,
				\curl_errno($handle),
				\curl_error($handle)
			));
		}

		$status = (int)\curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
		if ($status < 200 || $status >= 300) {
			throw new RemoteFetchException(\sprintf('App-skeleton reference returned HTTP %d: %s', $status, $url));
		}
		if (\strlen((string)$body) > self::MAX_RESPONSE_BYTES) {
			throw new RemoteFetchException('App-skeleton reference exceeded the maximum allowed response size: ' . $url);
		}

		return (string)$body;
	}


	private function encodePath(string $path): string {
		return \implode('/', \array_map('rawurlencode', \explode('/', $path)));
	}
}
