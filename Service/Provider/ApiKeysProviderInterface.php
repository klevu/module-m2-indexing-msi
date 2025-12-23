<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Provider;

interface ApiKeysProviderInterface
{
    /**
     * @param int[] $stockIds
     *
     * @return array<int, string[]>
     */
    public function getForStockIds(array $stockIds): array;
}
