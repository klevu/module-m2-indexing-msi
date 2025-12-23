<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Service\Action;

use Klevu\Indexing\Exception\IndexingEntitySaveException;
use Klevu\IndexingApi\Api\Data\IndexingEntityInterface;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToUpdateActionInterface;
use Klevu\IndexingApi\Service\Provider\IndexingEntityProviderInterface;
use Klevu\IndexingApi\Service\Provider\TargetParentIdsProviderInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryReservationsApi\Model\ReservationInterface;

class MarkReservationsForUpdate implements MarkReservationsForUpdateInterface
{
    /**
     * @var ApiKeysProviderInterface
     */
    private readonly ApiKeysProviderInterface $apiKeysProvider;
    /**
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;
    /**
     * @var ProductRepositoryInterface
     */
    private readonly ProductRepositoryInterface $productRepository;
    /**
     * @var IndexingEntityProviderInterface
     */
    private readonly IndexingEntityProviderInterface $indexingEntityProvider;
    /**
     * @var SetIndexingEntitiesToUpdateActionInterface
     */
    private readonly SetIndexingEntitiesToUpdateActionInterface $setIndexingEntitiesToUpdateAction;
    /**
     * @var TargetParentIdsProviderInterface
     */
    private readonly TargetParentIdsProviderInterface $targetParentIdsProvider;

    /**
     * @param ApiKeysProviderInterface $apiKeysProvider
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ProductRepositoryInterface $productRepository
     * @param IndexingEntityProviderInterface $indexingEntityProvider
     * @param SetIndexingEntitiesToUpdateActionInterface $setIndexingEntitiesToUpdateAction
     */
    public function __construct(
        ApiKeysProviderInterface $apiKeysProvider,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ProductRepositoryInterface $productRepository,
        IndexingEntityProviderInterface $indexingEntityProvider,
        SetIndexingEntitiesToUpdateActionInterface $setIndexingEntitiesToUpdateAction,
        TargetParentIdsProviderInterface $targetParentIdsProvider,
    ) {
        $this->apiKeysProvider = $apiKeysProvider;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productRepository = $productRepository;
        $this->indexingEntityProvider = $indexingEntityProvider;
        $this->setIndexingEntitiesToUpdateAction = $setIndexingEntitiesToUpdateAction;
        $this->targetParentIdsProvider = $targetParentIdsProvider;
    }

    /**
     * @param ReservationInterface[] $reservations
     *
     * @return void
     * @throws IndexingEntitySaveException
     */
    public function execute(array $reservations): void
    {
        $apiKeys = $this->apiKeysProvider->getForStockIds(
            stockIds: array_map(
                callback: static fn (ReservationInterface $reservation): int => (int)$reservation->getStockId(),
                array: $reservations,
            ),
        );
        if (!$apiKeys) {
            return;
        }

        $productIds = $this->getAllProductIdsFromReservations($reservations);
        if (!$productIds) {
            return;
        }

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $apiKeys,
            entityIds: $productIds,
            isIndexable: true,
        );
        $this->setIndexingEntitiesToUpdateAction->execute(
            entityIds: array_map(
                callback: static fn (IndexingEntityInterface $indexingEntity): int => $indexingEntity->getId(),
                array: $indexingEntities,
            ),
        );
    }

    /**
     * @param ReservationInterface[] $reservations
     *
     * @return int[]
     */
    private function getAllProductIdsFromReservations(array $reservations): array
    {
        $skus = array_unique(
            array: array_map(
                callback: static fn (ReservationInterface $reservation): string => $reservation->getSku(),
                array: $reservations,
            ),
        );
        $this->searchCriteriaBuilder->addFilter(
            field: ProductInterface::SKU,
            value: $skus,
            conditionType: 'in',
        );
        $productsResult = $this->productRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $productIds = array_map(
            callback: static fn (ProductInterface $product): int => (int)$product->getId(),
            array: $productsResult->getItems(),
        );
        $parentIds = array_unique(
            array: array_merge(
                [],
                ...array_map(
                    callback: fn (int $productId): array => $this->targetParentIdsProvider->get(
                        entityType: 'KLEVU_PRODUCT',
                        targetId: $productId,
                    ),
                    array: $productIds,
                ),
            ),
        );

        return array_filter(
            array: array_unique(
                array: array_merge($productIds, $parentIds),
            ),
        );
    }
}
