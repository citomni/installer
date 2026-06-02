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

namespace CitOmni\Installer\Cli\Command;

/**
 * `install` — first materialization for a package.
 *
 * Creates missing managed and create-only files from the current stubs and seeds
 * the state file (stub + rendered checksums, policy, placeholder snapshot,
 * metadata). Existing files are not overwritten unless --force is given, in which
 * case ApplyScaffoldPlan backs up the previous file first. Shares the plan engine
 * with `sync`; all transport logic lives in AbstractWriteCommand.
 */
final class InstallCommand extends AbstractWriteCommand {

	protected function commandName(): string {
		return 'install';
	}
}
