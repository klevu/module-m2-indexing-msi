<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Provider;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;

class StockIdsForSourceCodesProvider implements StockIdsForSourceCodesProviderInterface
{
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var GetStockSourceLinksInterface
     */
    private readonly GetStockSourceLinksInterface $getStockSourceLinks;
    /**
     * @var array<string, int[]>
     */
    private array $stockIdsForSourceCodes = [];

    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param GetStockSourceLinksInterface $getStockSourceLinks
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        GetStockSourceLinksInterface $getStockSourceLinks,
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->getStockSourceLinks = $getStockSourceLinks;
    }

    /**
     * @param string[] $sourceCodes
     *
     * @return array<string, int[]>
     */
    public function getForSourceCodes(array $sourceCodes): array
    {
        $uncachedSourceCodes = array_filter(
            array: $sourceCodes,
            callback: fn (string $sourceCode): bool => !array_key_exists($sourceCode, $this->stockIdsForSourceCodes),
        );

        if (!empty($uncachedSourceCodes)) {
            $this->stockIdsForSourceCodes = array_merge(
                $this->stockIdsForSourceCodes,
                $this->getStockIdsForSourceCodes($uncachedSourceCodes),
            );
        }

        return array_filter(
            array: $this->stockIdsForSourceCodes,
            callback: static fn (string $sourceCode): bool => in_array(
                needle: $sourceCode,
                haystack: $sourceCodes,
                strict: true,
            ),
            mode: ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return void
     */
    public function clearCache(): void
    {
        $this->stockIdsForSourceCodes = [];
    }

    /**
     * @param string[] $sourceCodes
     *
     * @return array<string, int[]>
     */
    private function getStockIdsForSourceCodes(array $sourceCodes): array
    {
        $this->searchCriteriaBuilder->addFilter(
            field: StockSourceLinkInterface::SOURCE_CODE,
            value: $sourceCodes,
            conditionType: 'in',
        );
        $stockSourceLinksResult = $this->getStockSourceLinks->execute(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $return = array_fill_keys(
            keys: $sourceCodes,
            value: [],
        );
        foreach ($stockSourceLinksResult->getItems() as $stockSourceLink) {
            $return[$stockSourceLink->getSourceCode()][] = (int)$stockSourceLink->getStockId();
        }
        array_walk(
            array: $return,
            callback: static function (array &$stockIds): void {
                $stockIds = array_filter(
                    array: array_unique($stockIds),
                );
            },
        );

        return $return;
    }
}
