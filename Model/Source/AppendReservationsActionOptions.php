<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AppendReservationsActionOptions implements OptionSourceInterface
{
    /**
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => AppendReservationsAction::CALCULATE_REQUIRES_UPDATE->value,
                'label' => __(AppendReservationsAction::CALCULATE_REQUIRES_UPDATE->label()),
            ],
            [
                'value' => AppendReservationsAction::MARK_FOR_UPDATE->value,
                'label' => __(AppendReservationsAction::MARK_FOR_UPDATE->label()),
            ],
            [
                'value' => AppendReservationsAction::NO_ACTION->value,
                'label' => __(AppendReservationsAction::NO_ACTION->label()),
            ],
        ];
    }
}
