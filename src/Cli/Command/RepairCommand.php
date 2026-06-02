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
 * `repair` — recreate missing files from recorded state.
 *
 * Recreates only files that are missing on disk, using the placeholders recorded
 * in the state file, and refreshes their baseline to the current stub. Existing
 * files are never touched and --force has no effect (the repair decision graph
 * ignores it). This is not a restore: no historical bytes are kept. All transport
 * logic lives in AbstractWriteCommand.
 */
final class RepairCommand extends AbstractWriteCommand {

	protected function commandName(): string {
		return 'repair';
	}
}
