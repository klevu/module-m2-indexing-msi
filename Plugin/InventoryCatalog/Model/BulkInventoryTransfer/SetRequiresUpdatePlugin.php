<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Plugin\InventoryCatalog\Model\BulkInventoryTransfer;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToRequireUpdateActionInterface;
use Klevu\IndexingMsi\Plugin\InventoryCatalog\Model\SetRequiresUpdateTrait;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\IndexingMsi\Service\Provider\StockIdsForSourceCodesProviderInterface;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\IndexingProducts\Service\Provider\TargetIdsToRequireUpdateByStockStatusProviderInterface;
use Magento\InventoryCatalog\Model\BulkInventoryTransfer;
use Psr\Log\LoggerInterface;

class SetRequiresUpdatePlugin
{
    use SetRequiresUpdateTrait;

    /**
     * @var ApiKeysProviderInterface
     */
    private readonly ApiKeysProviderInterface $apiKeysProvider;
    /**
     * @var StockIdsForSourceCodesProviderInterface
     */
    private readonly StockIdsForSourceCodesProviderInterface $stockIdsForSourceCodesProvider;

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
     * @param BulkInventoryTransfer $subject
     * @param array $skus
     * @param string $originSource
     * @param string $destinationSource
     * @param bool $unassignFromOrigin
     *
     * @return array
     */
    public function beforeExecute(
        BulkInventoryTransfer $subject,
        array $skus,
        string $originSource,
        string $destinationSource,
        bool $unassignFromOrigin,
    ): array {
        $stockIdsForSourceCodes = $this->stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $originSource,
                $destinationSource,
            ],
        );
        $apiKeysForSourceCodes = $this->apiKeysProvider->getForSourceCodes(
            sourceCodes: [
                $originSource,
                $destinationSource,
            ],
        );

        if (
            empty($skus)
            || empty($apiKeysForSourceCodes)
            || empty($stockIdsForSourceCodes)
        ) {
            return [$skus, $originSource, $destinationSource, $unassignFromOrigin];
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

        return [$skus, $originSource, $destinationSource, $unassignFromOrigin];
    }
}
