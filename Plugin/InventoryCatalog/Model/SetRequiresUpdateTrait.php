<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Plugin\InventoryCatalog\Model;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToRequireUpdateActionInterface;
use Klevu\IndexingProducts\Exception\ConflictingStockStatusesForTargetIdsException;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\IndexingProducts\Service\Provider\TargetIdsToRequireUpdateByStockStatusProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

trait SetRequiresUpdateTrait
{
    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
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
     * @var TargetIdsToRequireUpdateByStockStatusProviderInterface
     */
    private readonly TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider;

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
