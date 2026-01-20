<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Plugin\InventoryCatalog\Model\BulkSourceUnassign;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToRequireUpdateActionInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\IndexingMsi\Service\Provider\StockIdsForSourceCodesProviderInterface;
use Klevu\IndexingProducts\Exception\ConflictingStockStatusesForTargetIdsException;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\IndexingProducts\Service\Provider\TargetIdsToRequireUpdateByStockStatusProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalog\Model\BulkSourceUnassign;
use Psr\Log\LoggerInterface;

class SetRequiresUpdatePlugin
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ApiKeysProviderInterface
     */
    private readonly ApiKeysProviderInterface $apiKeysProvider;
    /**
     * @var StoresProviderInterface
     */
    private readonly StoresProviderInterface $storesProvider;
    /**
     * @var StockStatusCriteria
     */
    private readonly StockStatusCriteria $stockStatusCriteria;
    /**
     * @var SetIndexingEntitiesToRequireUpdateActionInterface
     */
    private readonly SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction;
    /**
     * @var StockIdsForSourceCodesProviderInterface
     */
    private readonly StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider;
    /**
     * @var TargetIdsToRequireUpdateByStockStatusProviderInterface
     */
    private readonly TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider;

    /**
     * @param LoggerInterface $logger
     * @param ApiKeysProviderInterface $apiKeysProvider
     * @param StoresProviderInterface $storesProvider
     * @param StockStatusCriteria $stockStatusCriteria
     * @param SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction
     * @param StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider
     * @param TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider
     */
    public function __construct(
        LoggerInterface $logger,
        ApiKeysProviderInterface $apiKeysProvider,
        StoresProviderInterface $storesProvider,
        StockStatusCriteria $stockStatusCriteria,
        SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction,
        StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider,
        TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider,
    ) {
        $this->logger = $logger;
        $this->apiKeysProvider = $apiKeysProvider;
        $this->storesProvider = $storesProvider;
        $this->stockStatusCriteria = $stockStatusCriteria;
        $this->setIndexingEntitiesToRequireUpdateAction = $setIndexingEntitiesToRequireUpdateAction;
        $this->stockIdsForSourceCodesProvider = $stockIdsForSourceCodesProvider;
        $this->targetIdsToRequireUpdateByStockStatusProvider = $targetIdsToRequireUpdateByStockStatusProvider;
    }

    /**
     * @param BulkSourceUnassign $subject
     * @param string[] $skus
     * @param string[] $sourceCodes
     *
     * @return array<string[], string[]>
     */
    public function beforeExecute(
        BulkSourceUnassign $subject,
        array $skus,
        array $sourceCodes,
    ): array {
        $stockIdsForSourceCodes = $this->stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: $sourceCodes,
        );
        $apiKeysForSourceCodes = $this->apiKeysProvider->getForSourceCodes(
            sourceCodes: $sourceCodes,
        );

        if (
            empty($skus)
            || empty($apiKeysForSourceCodes)
            || empty($stockIdsForSourceCodes)
        ) {
            return [$skus, $sourceCodes];
        }

        foreach ($skus as $sku) {
            foreach ($apiKeysForSourceCodes as $sourceCode => $apiKeys) {
                try {
                    $this->processUpdate(
                        sku: $sku,
                        apiKeys: $apiKeys,
                    );
                } catch (\Exception $exception) {
                    $this->logger->error(
                        message: 'Failed to set indexing entities to require update on bulk source assign',
                        context: [
                            'method' => __METHOD__,
                            'exception' => $exception::class,
                            'error' => $exception->getMessage(),
                            'sourceCode' => $sourceCode,
                            'sku' => $sku,
                            'apiKeys' => $apiKeys,
                        ],
                    );
                }
            }
        }

        return [$skus, $sourceCodes];
    }

    /**
     * @param string $sku
     * @param string[] $apiKeys
     *
     * @return void
     * @throws NoSuchEntityException
     */
    private function processUpdate(
        string $sku,
        array $apiKeys,
    ): void {
        foreach ($apiKeys as $apiKey) {
            $stores = $this->storesProvider->get(
                apiKey: $apiKey,
            );

            try {
                $targetIdsToRequireUpdate = $this->targetIdsToRequireUpdateByStockStatusProvider->getBySku(
                    sku: $sku,
                    stores: $stores,
                );
            } catch (ConflictingStockStatusesForTargetIdsException $exception) {
                $this->logger->warning(
                    message: 'Conflicting orig stock status for stores',
                    context: [
                        'method' => __METHOD__,
                        'sku' => $sku,
                        'apiKeys' => $apiKeys,
                        'targetIdsByStockStatus' => $exception->getTargetIdsByStockStatus(),
                    ],
                );

                continue;
            }

            foreach ($targetIdsToRequireUpdate as $stockStatus => $targetIds) {
                if (!$targetIds) {
                    continue;
                }

                $this->setIndexingEntitiesToRequireUpdateAction->execute(
                    entityType: 'KLEVU_PRODUCT',
                    apiKey: $apiKey,
                    targetIds: $targetIds,
                    origValues: [
                        $this->stockStatusCriteria->getCriteriaIdentifier() => (bool)$stockStatus,
                    ],
                );
            }
        }
    }
}
