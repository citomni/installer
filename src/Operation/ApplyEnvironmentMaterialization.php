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

namespace CitOmni\Installer\Operation;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\ComposerRunner;

/**
 * Applies one complete environment materialization.
 *
 * This operation is the correctness boundary shared by initial installation and
 * later environment switching. Scaffold state is deferred until the scaffold and
 * Composer posture both succeeded, then committed exactly once with the environment.
 *
 * Failure model:
 * - Composer availability is checked before any write.
 * - A failed scaffold apply stops before Composer is mutated.
 * - Composer failures do not roll scaffold files back and do not advance state.
 * - Re-running reconciles the forward-only partial state.
 */
final class ApplyEnvironmentMaterialization {

	public function __construct(
		private readonly ApplyScaffoldPlan $scaffold,
		private readonly ComposerRunner $composer,
		private readonly ScaffoldState $state
	) {}


	/**
	 * Apply scaffold, Composer posture, autoload dump, and final state commit.
	 *
	 * @param array<string,mixed> $plan Planner output.
	 * @param Environment $environment Target environment.
	 * @param array<string,mixed> $options Supported: dry_run bool.
	 * @return array<string,mixed> Scaffold apply result plus Composer preview metadata.
	 * @throws InstallerException When Composer is unavailable or a Composer command fails.
	 */
	public function apply(array $plan, Environment $environment, array $options = []): array {
		$dryRun = (bool)($options['dry_run'] ?? false);

		if (!$this->composer->isAvailable()) {
			throw new InstallerException('Composer is required for environment materialization but is not available.');
		}

		$this->composer->assertProjectContext();

		$result = $this->scaffold->apply($plan, [
			'dry_run' => $dryRun,
			'persist_state' => false,
		]);

		$result['environment'] = $environment->value;
		$result['composer'] = [
			'classmap_authoritative' => $environment->classmapAuthoritative(),
			'dump_autoload' => true,
			'applied' => false,
		];

		if (($result['ok'] ?? false) !== true) {
			return $result;
		}

		if ($dryRun) {
			return $result;
		}

		$value = $environment->classmapAuthoritative() ? 'true' : 'false';
		$this->runComposerOrFail(
			['config', 'classmap-authoritative', $value, '--no-interaction'],
			'Failed to configure Composer classmap-authoritative.'
		);
		$this->runComposerOrFail(
			['dump-autoload', '--no-scripts', '--no-interaction'],
			'Failed to regenerate Composer autoload files.'
		);

		$statePackages = $result['_state_packages'] ?? null;
		if (!\is_array($statePackages)) {
			throw new InstallerException('Deferred scaffold apply did not return its internal state package payload.');
		}

		$this->state->writePackagesAndEnvironment($statePackages, $environment);
		$result['state_written'] = true;
		$result['composer']['applied'] = true;

		return $result;
	}


	/**
	 * Run Composer and translate non-zero exit into a domain failure.
	 *
	 * @param array<int,string> $args Composer arguments.
	 * @param string $message Failure prefix.
	 * @return void
	 * @throws InstallerException When Composer exits non-zero.
	 */
	private function runComposerOrFail(array $args, string $message): void {
		$result = $this->composer->run($args);
		if ($result['exit_code'] === 0) {
			return;
		}

		$detail = \trim($result['stderr']);
		if ($detail === '') {
			$detail = \trim($result['stdout']);
		}
		if ($detail === '') {
			$detail = 'Composer exited with code ' . $result['exit_code'] . '.';
		}

		throw new InstallerException($message . ' ' . $detail);
	}
}
