<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\Indexing\Exception\IndexingEntitySaveException;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToRequireUpdateActionInterface;
use Klevu\IndexingApi\Service\Provider\TargetParentIdsProviderInterface;
use Klevu\IndexingMsi\Model\Source\AppendReservationsAction;
use Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdateInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProviderInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryReservations\Model\AppendReservations;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class SetRequiresUpdatePlugin
{
    public const XML_PATH_APPEND_RESERVATIONS_ACTION = 'klevu/indexing/append_reservations_action';

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
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
     * @var ProductRepositoryInterface
     */
    private readonly ProductRepositoryInterface $productRepository;
    /**
     * @var ProductStockStatusProviderInterface
     */
    private readonly ProductStockStatusProviderInterface $productStockStatusProvider;
    /**
     * @var SetIndexingEntitiesToRequireUpdateActionInterface
     */
    private readonly SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction;
    /**
     * @var MarkReservationsForUpdateInterface
     */
    private readonly MarkReservationsForUpdateInterface $markReservationsForUpdateAction;
    /**
     * @var TargetParentIdsProviderInterface
     */
    private readonly TargetParentIdsProviderInterface $targetParentIdsProvider;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ApiKeysProviderInterface $apiKeysProvider
     * @param StoresProviderInterface $storesProvider
     * @param StockStatusCriteria $stockStatusCriteria
     * @param ProductRepositoryInterface $productRepository
     * @param ProductStockStatusProviderInterface $productStockStatusProvider
     * @param SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction
     * @param MarkReservationsForUpdateInterface $markReservationsForUpdateAction
     * @param TargetParentIdsProviderInterface $targetParentIdsProvider
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ApiKeysProviderInterface $apiKeysProvider,
        StoresProviderInterface $storesProvider,
        StockStatusCriteria $stockStatusCriteria,
        ProductRepositoryInterface $productRepository,
        ProductStockStatusProviderInterface $productStockStatusProvider,
        SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction,
        MarkReservationsForUpdateInterface $markReservationsForUpdateAction,
        TargetParentIdsProviderInterface $targetParentIdsProvider,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->apiKeysProvider = $apiKeysProvider;
        $this->storesProvider = $storesProvider;
        $this->stockStatusCriteria = $stockStatusCriteria;
        $this->productRepository = $productRepository;
        $this->productStockStatusProvider = $productStockStatusProvider;
        $this->setIndexingEntitiesToRequireUpdateAction = $setIndexingEntitiesToRequireUpdateAction;
        $this->markReservationsForUpdateAction = $markReservationsForUpdateAction;
        $this->targetParentIdsProvider = $targetParentIdsProvider;
    }

    /**
     * @param AppendReservations $subject
     * @param ReservationInterface[] $reservations
     *
     * @return array<ReservationInterface[]>
     */
    public function beforeExecute(
        AppendReservations $subject, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        array $reservations,
    ): array {
        if (AppendReservationsAction::CALCULATE_REQUIRES_UPDATE !== $this->getAppendReservationsAction()) {
            return [$reservations];
        }

        $apiKeys = $this->apiKeysProvider->getForStockIds(
            stockIds: array_map(
                callback: static fn (ReservationInterface $reservation): int => (int)$reservation->getStockId(),
                array: $reservations,
            ),
        );

        if (empty($apiKeys)) {
            return [$reservations];
        }

        foreach ($reservations as $reservation) {
            try {
                $this->processReservation(
                    reservation: $reservation,
                    apiKeys: $apiKeys,
                );
            } catch (\Exception $exception) {
                $this->logger->error(
                    message: 'Failed to set indexing entities to require update on append reservations',
                    context: [
                        'method' => __METHOD__,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                        'stockId' => $reservation->getStockId(),
                        'sku' => $reservation->getSku(),
                        'apiKeys' => $apiKeys,
                    ],
                );
            }
        }

        return [$reservations];
    }

    /**
     * @return AppendReservationsAction
     */
    private function getAppendReservationsAction(): AppendReservationsAction
    {
        try {
            $appendReservationsActionConfigValue = $this->scopeConfig->getValue(
                static::XML_PATH_APPEND_RESERVATIONS_ACTION,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            );
            $appendReservationsAction = AppendReservationsAction::from($appendReservationsActionConfigValue);
        } catch (\ValueError) {
            $appendReservationsAction = AppendReservationsAction::defaultAction();
        }

        return $appendReservationsAction;
    }

    /**
     * @param ReservationInterface $reservation
     * @param array<int, string[]> $apiKeys
     *
     * @return void
     * @throws IndexingEntitySaveException
     * @throws NoSuchEntityException
     */
    private function processReservation(
        ReservationInterface $reservation,
        array $apiKeys,
    ): void {
        $sku = $reservation->getSku();
        $stockId = (int)$reservation->getStockId();
        if (empty($apiKeys[$stockId])) {
            return;
        }

        foreach ($apiKeys[$stockId] as $apiKey) {
            $product = $this->productRepository->get(
                sku: $sku,
            );
            $stores = $this->storesProvider->get(
                apiKey: $apiKey,
            );
            $parentIds = $this->targetParentIdsProvider->get(
                entityType: 'KLEVU_PRODUCT',
                targetId: (int)$product->getId(),
            );

            $targetIdsForUpdateByStore = [];
            foreach ($stores as $store) {
                $parentProducts = array_map(
                    callback: fn (int $parentId): ProductInterface => $this->productRepository->getById(
                        productId: $parentId,
                        storeId: (int)$store->getId(),
                    ),
                    array: $parentIds,
                );

                $targetIdsForUpdateByStore[$store->getId()] = $this->determineTargetIdsToUpdate(
                    product: $product,
                    store: $store,
                    parentProducts: $parentProducts,
                );
            }
            $entityIdsForUpdate = $this->mergeTargetIdsForUpdateByStore($targetIdsForUpdateByStore);

            if ($this->hasConflictingStockStatusesForUpdate($entityIdsForUpdate)) {
                $this->logger->warning(
                    message: 'Conflicting orig stock status for stores; marking record for update',
                    context: [
                        'method' => __METHOD__,
                        'stockId' => $reservation->getStockId(),
                        'sku' => $reservation->getSku(),
                        'apiKeys' => $apiKeys,
                        'entityIdsForUpdate' => $entityIdsForUpdate,
                    ],
                );

                $this->markReservationsForUpdateAction->execute(
                    reservations: [$reservation],
                );
                break;
            }

            foreach ($entityIdsForUpdate as $stockStatus => $targetIds) {
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

    /**
     * @param ProductInterface $product
     * @param StoreInterface $store
     * @param ProductInterface[] $parentProducts
     *
     * @return array<int, array<string, array<int|null>>>
     */
    private function determineTargetIdsToUpdate(
        ProductInterface $product,
        StoreInterface $store,
        array $parentProducts,
    ): array {
        $entityIdsForUpdate = [
            0 => [],
            1 => [],
        ];

        $origStockStatusForStandalone = $this->productStockStatusProvider->get(
            product: $product,
            store: $store,
            parentProduct: null,
        );
        $entityIdsForUpdate[(int)$origStockStatusForStandalone][] = [
            SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_ID => (int)$product->getId(),
            SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_PARENT_ID => null,
        ];

        foreach ($parentProducts as $parentProduct) {
            $origStockStatusForVariant = $this->productStockStatusProvider->get(
                product: $product,
                store: $store,
                parentProduct: $parentProduct,
            );
            $entityIdsForUpdate[(int)$origStockStatusForVariant][] = [
                SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_ID => (int)$product->getId(),
                SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_PARENT_ID => (int)$parentProduct->getId(), // phpcs:ignore Generic.Files.LineLength.TooLong
            ];

            $origStockStatusForParent = $this->productStockStatusProvider->get(
                product: $parentProduct,
                store: $store,
                parentProduct: null,
            );
            $entityIdsForUpdate[(int)$origStockStatusForParent][] = [
                SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_ID => (int)$parentProduct->getId(), // phpcs:ignore Generic.Files.LineLength.TooLong
                SetIndexingEntitiesToRequireUpdateActionInterface::ENTITY_IDS_KEY_TARGET_PARENT_ID => null,
            ];
        }

        return $entityIdsForUpdate;
    }

    /**
     * @param array<int, array<int, array<string, array<int|null>>>> $targetIdsForUpdateByStore
     *
     * @return array<int, array<string, array<int|null>>>
     */
    private function mergeTargetIdsForUpdateByStore(
        array $targetIdsForUpdateByStore,
    ): array {
        $return = [
            0 => [],
            1 => [],
        ];

        foreach ($targetIdsForUpdateByStore as $entityIdsForUpdate) {
            foreach ($entityIdsForUpdate as $stockStatusFlag => $entityIdsItems) {
                foreach ($entityIdsItems as $entityIdsItem) {
                    if (in_array($entityIdsItem, $return[$stockStatusFlag], true)) {
                        continue;
                    }

                    $return[$stockStatusFlag][] = $entityIdsItem;
                }
            }
        }

        return $return;
    }

    /**
     * @param array<int, array<string, array<int|null>>> $entityIdsForUpdate
     *
     * @return bool
     */
    private function hasConflictingStockStatusesForUpdate(
        array $entityIdsForUpdate,
    ): bool {
        if (empty($entityIdsForUpdate[0]) || empty($entityIdsForUpdate[1])) {
            return false;
        }

        $entityIdsCompact = [
            0 => [],
            1 => [],
        ];
        foreach ($entityIdsForUpdate as $stockStatusKey => $entityIdItems) {
            $entityIdsCompact[$stockStatusKey] = array_map(
                callback: static fn (array $entityIdItem): string => implode('::', $entityIdItem),
                array: $entityIdItems,
            );
        }

        return !!array_intersect(
            $entityIdsCompact[0],
            $entityIdsCompact[1],
        );
    }
}
