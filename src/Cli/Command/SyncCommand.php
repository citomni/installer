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
 * `sync` — controlled update of managed files.
 *
 * Moves managed files forward to the current stub/placeholders while the file on
 * disk still matches its recorded baseline. Locally modified files are never
 * overwritten: ApplyScaffoldPlan writes a sibling <target>.new instead, unless
 * --force is given (which backs up the existing file first). Accepts an optional
 * positional [target] to limit the run to a single app-relative file. All
 * transport logic lives in AbstractWriteCommand.
 */
final class SyncCommand extends AbstractWriteCommand {

	protected function commandName(): string {
		return 'sync';
	}

	protected function acceptsPositionalTarget(): bool {
		return true;
	}
}
