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

namespace CitOmni\Installer\Tests\Support;

use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\PhpLintRunner;
use PHPUnit\Framework\TestCase;

final class PhpLintRunnerTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = \sys_get_temp_dir() . '/citomni_php_lint_' . \bin2hex(\random_bytes(6));
		\mkdir($this->root . '/src', 0775, true);
		\mkdir($this->root . '/templates', 0775, true);
		\mkdir($this->root . '/vendor/acme/pkg', 0775, true);
		\mkdir($this->root . '/var/backups/old', 0775, true);
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
	}

	public function testRecursivelyLintsAppPhpAndPhpTemplatesButSkipsExcludedTrees(): void {
		\file_put_contents($this->root . '/src/Good.php', "<?php\nreturn true;\n");
		\file_put_contents($this->root . '/templates/Bad.php.tpl', "<?php\nreturn [;\n");
		\file_put_contents($this->root . '/vendor/acme/pkg/Bad.php', "<?php\nreturn [;\n");
		\file_put_contents($this->root . '/var/backups/old/Bad.php', "<?php\nreturn [;\n");

		$result = (new PhpLintRunner(new PathGuard($this->root)))->run();

		$this->assertTrue($result['available']);
		$this->assertSame(2, $result['checked']);
		$this->assertSame(1, $result['passed']);
		$this->assertSame(1, $result['failed']);
		$this->assertSame(['src/Good.php', 'templates/Bad.php.tpl'], \array_column($result['files'], 'path'));
	}

	private function removeTree(string $path): void {
		if (!\is_dir($path)) {
			return;
		}
		foreach (\scandir($path) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$child = $path . '/' . $item;
			\is_dir($child) ? $this->removeTree($child) : @\unlink($child);
		}
		@\rmdir($path);
	}
}
