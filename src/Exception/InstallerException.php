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

namespace CitOmni\Installer\Exception;

/**
 * Single domain exception for the installer package.
 *
 * Thrown on any installer-level failure (invalid manifest path, unsafe resolution,
 * unreadable root, etc.). The CLI command layer is responsible for mapping caught
 * instances to the exit codes in contract §11; this type intentionally carries no
 * exit-code logic of its own.
 *
 * Notes:
 * - Extends \RuntimeException: failures are runtime conditions, not programmer errors.
 * - Not final: the command layer may introduce narrower subtypes later if useful.
 */
class InstallerException extends \RuntimeException {
}
