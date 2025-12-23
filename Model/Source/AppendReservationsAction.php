<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Model\Source;

use Klevu\Configuration\Traits\EnumTrait;

enum AppendReservationsAction: string
{
    use EnumTrait;

    case CALCULATE_REQUIRES_UPDATE = 'calculate_requires_update';
    case MARK_FOR_UPDATE = 'mark_for_update';
    case NO_ACTION = 'no_action';

    /**
     * @return self
     */
    public static function defaultAction(): self
    {
        return self::CALCULATE_REQUIRES_UPDATE;
    }

    /**
     * @return string
     */
    public function label(): string
    {
        return match ($this) //phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
        {
            self::CALCULATE_REQUIRES_UPDATE => 'Calculate Requires Update',
            self::MARK_FOR_UPDATE => 'Mark All For Update',
            self::NO_ACTION => 'No Action',
        };
    }
}
