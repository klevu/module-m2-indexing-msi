<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Provider;

interface StockIdsForSourceCodesProviderInterface
{
    /**
     * @param string[] $sourceCodes
     *
     * @return int[]
     */
    public function getForSourceCodes(array $sourceCodes): array;

    /**
     * @return void
     */
    public function clearCache(): void;
}
