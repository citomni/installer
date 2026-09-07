<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Copyright (c) 2012-present CitOmni.
 * SPDX-License-Identifier: MIT
 */

namespace CitOmni\Installer\Exception;

/** A competing writer or changed precondition requires a fresh invocation. */
class ConflictException extends InstallerException {
}
