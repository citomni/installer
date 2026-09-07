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
 * Closed CitOmni application environment set.
 *
 * The enum owns installer-wide environment semantics so bounded environment
 * decisions are not duplicated as string comparisons across commands and operations.
 */
enum Environment: string {
	case DEV = 'dev';
	case STAGE = 'stage';
	case PROD = 'prod';

	/**
	 * Return CitOmni's canonical Composer classmap-authoritative posture.
	 *
	 * @return bool False in dev; true in stage and prod.
	 */
	public function classmapAuthoritative(): bool {
		return match ($this) {
			self::DEV => false,
			self::STAGE, self::PROD => true,
		};
	}

	/**
	 * Return the complete environment value set in declaration order.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return \array_map(
			static fn(self $environment): string => $environment->value,
			self::cases()
		);
	}
}
