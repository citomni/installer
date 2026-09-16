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

use CitOmni\Installer\Util\PhpSourceComparator;
use PHPUnit\Framework\TestCase;

final class PhpSourceComparatorTest extends TestCase {

	public function testCommentsPhpDocLicenseAndWhitespaceAreIgnored(): void {
		$old = "<?php\n/** Old doc. */\nreturn [ 'enabled' => true ];\n";
		$new = "<?php\n/* New license. */\n\n/** New doc. */\nreturn [\n\t'enabled' => true\n];\n";

		$this->assertTrue(PhpSourceComparator::equivalent($old, $new));
	}

	public function testRealCodeDifferenceIsDetected(): void {
		$old = "<?php\nreturn ['enabled' => true];\n";
		$new = "<?php\nreturn ['enabled' => false];\n";

		$this->assertFalse(PhpSourceComparator::equivalent($old, $new));
	}

	public function testCommentLikeTextInsideStringRemainsSignificant(): void {
		$old = "<?php\nreturn '/* old */';\n";
		$new = "<?php\nreturn '/* new */';\n";

		$this->assertFalse(PhpSourceComparator::equivalent($old, $new));
	}

	public function testLineMagicConstantRemainsLineSensitive(): void {
		$old = "<?php\nreturn __LINE__;\n";
		$new = "<?php\n/* inserted line */\nreturn __LINE__;\n";

		$this->assertFalse(PhpSourceComparator::equivalent($old, $new));
	}

	public function testCompilerHaltOffsetRemainsByteSensitive(): void {
		$old = "<?php\n/* old */\necho __COMPILER_HALT_OFFSET__; __halt_compiler();DATA";
		$new = "<?php\n/* new comment */\necho __COMPILER_HALT_OFFSET__; __halt_compiler();DATA";

		$this->assertFalse(PhpSourceComparator::equivalent($old, $new));
	}


	public function testInvalidPhpFailsClosed(): void {
		$this->expectException(\ParseError::class);
		PhpSourceComparator::significantTokens("<?php\nreturn [;\n");
	}
}
