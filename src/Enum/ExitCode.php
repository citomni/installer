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

namespace CitOmni\Installer\Enum;

/**
 * CLI process exit codes used by citomni/installer.
 *
 * Notes:
 * - Backed values are part of the public CLI contract.
 * - Keep this enum limited to process exit codes. It is not a junk drawer with a badge.
 */
enum ExitCode: int {
	case OK = 0;
	case GENERAL_ERROR = 1;
	case USAGE_ERROR = 2;
	case DRIFT = 3;
	case CONFLICT = 4;
	case UNSAFE_STATE = 5;
	case IO_ERROR = 6;
}
