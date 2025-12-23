<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Action;

use Klevu\Indexing\Exception\IndexingEntitySaveException;
use Magento\InventoryReservationsApi\Model\ReservationInterface;

interface MarkReservationsForUpdateInterface
{
    /**
     * @param ReservationInterface[] $reservations
     *
     * @return void
     * @throws IndexingEntitySaveException
     */
    public function execute(
        array $reservations,
    ): void;
}
