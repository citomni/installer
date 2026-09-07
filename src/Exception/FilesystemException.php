<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Copyright (c) 2012-present CitOmni.
 * SPDX-License-Identifier: MIT
 */

namespace CitOmni\Installer\Exception;

/** Filesystem failure that the CLI can distinguish from invalid input or a conflict. */
class FilesystemException extends InstallerException {
}
