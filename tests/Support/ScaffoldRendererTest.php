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

use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Exception\InstallerException;
use PHPUnit\Framework\TestCase;

final class ScaffoldRendererTest extends TestCase {

	private ScaffoldRenderer $renderer;


	protected function setUp(): void {
		$this->renderer = new ScaffoldRenderer();
	}


	private function values(): array {
		return [
			'APP_NAMESPACE'       => 'App',
			'APP_NAME'            => 'My App',
			'CITOMNI_ENVIRONMENT' => 'prod',
			'PACKAGE_VERSION'     => '1.2.0',
		];
	}


	// ----------------------------------------------------------------
	// Happy path
	// ----------------------------------------------------------------

	public function testReplacesAllCanonicalTokens(): void {
		$stub = "ns={{APP_NAMESPACE}} name={{APP_NAME}} env={{CITOMNI_ENVIRONMENT}} ver={{PACKAGE_VERSION}}";
		$this->assertSame(
			'ns=App name=My App env=prod ver=1.2.0',
			$this->renderer->render($stub, $this->values())
		);
	}


	public function testReplacesRepeatedToken(): void {
		$this->assertSame(
			'App/App/App',
			$this->renderer->render('{{APP_NAMESPACE}}/{{APP_NAMESPACE}}/{{APP_NAMESPACE}}', $this->values())
		);
	}


	public function testInsertsValuesVerbatimIncludingBackslashesAndDollar(): void {
		$values = ['APP_NAMESPACE' => 'App\\Domain\\Sub', 'APP_NAME' => 'Cost is $5 \\1'];
		$this->assertSame(
			'namespace App\\Domain\\Sub; // Cost is $5 \\1',
			$this->renderer->render('namespace {{APP_NAMESPACE}}; // {{APP_NAME}}', $values)
		);
	}


	public function testEmptyValueRemovesToken(): void {
		$this->assertSame('a=', $this->renderer->render('a={{APP_NAME}}', ['APP_NAME' => '']));
	}


	public function testUnusedValuesAreIgnored(): void {
		$this->assertSame('App', $this->renderer->render('{{APP_NAMESPACE}}', $this->values()));
	}


	// ----------------------------------------------------------------
	// Single pass (no recursion / no template engine)
	// ----------------------------------------------------------------

	public function testDoesNotReSubstituteValueContent(): void {
		$values = ['APP_NAME' => '{{CITOMNI_ENVIRONMENT}}', 'CITOMNI_ENVIRONMENT' => 'prod'];
		// APP_NAME resolves to a literal token string; it must NOT be expanded again.
		$this->assertSame('{{CITOMNI_ENVIRONMENT}}', $this->renderer->render('{{APP_NAME}}', $values));
	}


	// ----------------------------------------------------------------
	// Unknown placeholder must fail
	// ----------------------------------------------------------------

	public function testUnknownPlaceholderFails(): void {
		$this->expectException(InstallerException::class);
		$this->expectExceptionMessage('Unknown placeholder: {{NOT_A_TOKEN}}');
		$this->renderer->render('x={{NOT_A_TOKEN}}', $this->values());
	}


	public function testUnknownPlaceholderFailsEvenWhenOthersResolve(): void {
		$this->expectException(InstallerException::class);
		$this->renderer->render('{{APP_NAME}} {{MISSING}}', $this->values());
	}


	// ----------------------------------------------------------------
	// Malformed tokens must fail
	// ----------------------------------------------------------------

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function malformedStubs(): array {
		return [
			'surrounding spaces' => ['{{ APP_NAME }}'],
			'lowercase'          => ['{{app_name}}'],
			'leading digit'      => ['{{1APP}}'],
			'hyphen'             => ['{{APP-NAME}}'],
			'empty'              => ['{{}}'],
			'newline inside'     => ["{{APP_\nNAME}}"],
			'dot'                => ['{{APP.NAME}}'],
		];
	}


	/**
	 * @dataProvider malformedStubs
	 */
	public function testMalformedTokenFails(string $stub): void {
		$this->expectException(InstallerException::class);
		$this->renderer->render($stub, $this->values());
	}


	public function testNonStringValueFails(): void {
		$this->expectException(InstallerException::class);
		$this->expectExceptionMessage('must resolve to a string');
		$this->renderer->render('{{PACKAGE_VERSION}}', ['PACKAGE_VERSION' => 120]);
	}


	// ----------------------------------------------------------------
	// Non-placeholder brace content is left untouched
	// ----------------------------------------------------------------

	public function testLeavesNonPlaceholderBracesUntouched(): void {
		// Single-brace interpolation, array close, and arrow fns: none are "{{...}}".
		$stub = 'echo "{$user->name}"; $a = [[1],[2]]; $f = fn() => 1;';
		$this->assertSame($stub, $this->renderer->render($stub, $this->values()));
	}


	// ----------------------------------------------------------------
	// Line endings / bytes preserved (no normalization)
	// ----------------------------------------------------------------

	public function testPreservesCrlfAndDoesNotAddTrailingNewline(): void {
		$stub     = "<?php\r\n// {{APP_NAME}}\r\nreturn 1;";
		$expected = "<?php\r\n// My App\r\nreturn 1;";
		$rendered = $this->renderer->render($stub, $this->values());

		$this->assertSame($expected, $rendered);
		$this->assertStringNotContainsString("\r\n\n", $rendered);
		$this->assertSame($expected, $rendered); // byte-exact, no trailing "\n" appended
	}


	// ----------------------------------------------------------------
	// placeholdersIn()
	// ----------------------------------------------------------------

	public function testPlaceholdersInReturnsSortedUnique(): void {
		$stub = '{{PACKAGE_VERSION}} {{APP_NAME}} {{APP_NAME}} {{APP_NAMESPACE}}';
		$this->assertSame(
			['APP_NAME', 'APP_NAMESPACE', 'PACKAGE_VERSION'],
			$this->renderer->placeholdersIn($stub)
		);
	}


	public function testPlaceholdersInEmptyForNone(): void {
		$this->assertSame([], $this->renderer->placeholdersIn('no tokens here {$x}'));
	}


	public function testPlaceholdersInRejectsMalformed(): void {
		$this->expectException(InstallerException::class);
		$this->renderer->placeholdersIn('{{ bad token }}');
	}


	// ----------------------------------------------------------------
	// readStub()
	// ----------------------------------------------------------------

	public function testReadStubReturnsRawBytes(): void {
		$path = \tempnam(\sys_get_temp_dir(), 'citomni-stub-');
		$raw  = "<?php\r\nnamespace {{APP_NAMESPACE}};\r\n";
		\file_put_contents($path, $raw);

		try {
			$this->assertSame($raw, $this->renderer->readStub($path));
		} finally {
			\unlink($path);
		}
	}


	public function testReadStubFailsWhenMissing(): void {
		$this->expectException(InstallerException::class);
		$this->renderer->readStub(\sys_get_temp_dir() . '/citomni-does-not-exist-' . \uniqid());
	}

}
