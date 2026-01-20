<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Provider;

use Klevu\Configuration\Service\Provider\ApiKeysProviderInterface as BaseApiKeysProviderInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySales\Model\ResourceModel\GetAssignedSalesChannelsDataForStock;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ApiKeysProvider implements ApiKeysProviderInterface
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var GetAssignedSalesChannelsDataForStock
     */
    private readonly GetAssignedSalesChannelsDataForStock $getAssignedSalesChannelsDataForStock;
    /**
     * @var StoreManagerInterface
     */
    private readonly StoreManagerInterface $storeManager;
    /**
     * @var BaseApiKeysProviderInterface
     */
    private readonly BaseApiKeysProviderInterface $baseApiKeysProvider;
    /**
     * @var StockIdsForSourceCodesProviderInterface 
     */
    private readonly StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider;
    /**
     * @var array<int, string[]>
     */
    private array $cachedApiKeys = [];

    /**
     * @param LoggerInterface $logger
     * @param GetAssignedSalesChannelsDataForStock $getAssignedSalesChannelsDataForStock
     * @param StoreManagerInterface $storeManager
     * @param BaseApiKeysProviderInterface $baseApiKeysProvider
     * @param StockIdsForSourceCodesProviderInterface|null $stockIdsForSourceCodesProvider
     */
    public function __construct(
        LoggerInterface $logger,
        GetAssignedSalesChannelsDataForStock $getAssignedSalesChannelsDataForStock,
        StoreManagerInterface $storeManager,
        BaseApiKeysProviderInterface $baseApiKeysProvider,
        ?StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider = null,
    ) {
        $this->logger = $logger;
        $this->getAssignedSalesChannelsDataForStock = $getAssignedSalesChannelsDataForStock;
        $this->storeManager = $storeManager;
        $this->baseApiKeysProvider = $baseApiKeysProvider;

        $objectManager = ObjectManager::getInstance();
        $this->stockIdsForSourceCodesProvider = $stockIdsForSourceCodesProvider ?? $objectManager->get(
            type: StockIdsForSourceCodesProviderInterface::class,
        );
    }

    /**
     * @param string[] $sourceCodes
     *
     * @return array<string, string[]>
     */
    public function getForSourceCodes(array $sourceCodes): array
    {
        $stockIdsForSourceCodes = $this->stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: $sourceCodes,
        );
        
        $apiKeysForAllStockIds = $this->getForStockIds(
            stockIds: array_unique(
                array_merge([], ...array_values($stockIdsForSourceCodes)),
            ),
        );

        $return = [];
        foreach ($sourceCodes as $sourceCode) {
            $return[$sourceCode] = [];
            
            $apiKeysForIteration = [];
            foreach ($stockIdsForSourceCodes[$sourceCode] as $stockId) {
                $apiKeysForIteration[] = $apiKeysForAllStockIds[$stockId];
            }
            
            $return[$sourceCode] = array_unique(
                array: array_merge([], ...$apiKeysForIteration),
            );
        }

        return $return;
    }

    /**
     * @param int[] $stockIds
     *
     * @return array<int, string[]>
     */
    public function getForStockIds(array $stockIds): array
    {
        $uncachedStockIds = array_filter(
            array: $stockIds,
            callback: fn (int $stockId): bool => !array_key_exists($stockId, $this->cachedApiKeys),
        );
        foreach ($uncachedStockIds as $stockId) {
            $apiKeys = [];

            $assignedSalesChannels = $this->getAssignedSalesChannelsDataForStock->execute(
                stockId: (int)$stockId,
            );
            foreach ($assignedSalesChannels as $salesChannelData) {
                if ('website' !== ($salesChannelData['type'] ?? '')) {
                    continue;
                }

                $websiteCode = $salesChannelData['code'] ?? '';
                if (!$websiteCode) {
                    continue;
                }

                try {
                    $website = $this->storeManager->getWebsite($websiteCode);
                } catch (LocalizedException $exception) {
                    $this->logger->warning(
                        message: 'Could not retrieve website for sales channel',
                        context: [
                            'method' => __METHOD__,
                            'exception' => $exception::class,
                            'error' => $exception->getMessage(),
                            'websiteCode' => $websiteCode,
                            'stockId' => $stockId,
                        ],
                    );
                    continue;
                }

                $storeIds = array_map(
                    callback: 'intval',
                    array: $website->getStoreIds(),
                );

                $apiKeys[] = $this->baseApiKeysProvider->get($storeIds);
            }

            $this->cachedApiKeys[$stockId] = array_unique(
                array: array_merge([], ...$apiKeys),
            );
        }

        return array_filter(
            array: $this->cachedApiKeys,
            callback: static fn (int $stockId): bool => in_array($stockId, $stockIds, false),
            mode: ARRAY_FILTER_USE_KEY,
        );
    }
}
