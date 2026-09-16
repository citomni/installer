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

namespace CitOmni\Installer\Tests\Util;

use CitOmni\Installer\Util\UnifiedDiff;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffTest extends TestCase {

	public function testIdenticalInputProducesNoDiff(): void {
		$this->assertSame('', UnifiedDiff::create("a\nb\n", "a\nb\n", 'old', 'new'));
	}

	public function testByteOnlyLineEndingDifferenceIsExplicit(): void {
		$diff = UnifiedDiff::create("one\r\ntwo\r\n", "one\ntwo\n", 'old', 'new');

		$this->assertStringContainsString('byte-level difference', $diff);
		$this->assertStringContainsString('line endings', $diff);
	}


	public function testChangedLineProducesReadableUnifiedDiff(): void {
		$diff = UnifiedDiff::create("a\nb\nc\n", "a\nx\nc\n", 'old', 'new');

		$this->assertStringContainsString("--- old\n+++ new\n", $diff);
		$this->assertStringContainsString("-b\n+x\n", $diff);
	}
}
