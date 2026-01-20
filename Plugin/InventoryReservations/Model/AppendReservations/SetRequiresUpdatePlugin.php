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
use Klevu\IndexingProducts\Exception\ConflictingStockStatusesForTargetIdsException;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProviderInterface;
use Klevu\IndexingProducts\Service\Provider\TargetIdsToRequireUpdateByStockStatusProviderInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryReservations\Model\AppendReservations;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
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
     * @var SetIndexingEntitiesToRequireUpdateActionInterface
     */
    private readonly SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction;
    /**
     * @var MarkReservationsForUpdateInterface
     */
    private readonly MarkReservationsForUpdateInterface $markReservationsForUpdateAction;
    /**
     * @var TargetIdsToRequireUpdateByStockStatusProviderInterface
     */
    private readonly TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider; // phpcs:ignore Generic.Files.LineLength.TooLong

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
     * @param TargetIdsToRequireUpdateByStockStatusProviderInterface|null $targetIdsToRequireUpdateByStockStatusProvider
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ApiKeysProviderInterface $apiKeysProvider,
        StoresProviderInterface $storesProvider,
        StockStatusCriteria $stockStatusCriteria,
        ProductRepositoryInterface $productRepository, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
        ProductStockStatusProviderInterface $productStockStatusProvider, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
        SetIndexingEntitiesToRequireUpdateActionInterface $setIndexingEntitiesToRequireUpdateAction,
        MarkReservationsForUpdateInterface $markReservationsForUpdateAction,
        TargetParentIdsProviderInterface $targetParentIdsProvider, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter, Generic.Files.LineLength.TooLong
        ?TargetIdsToRequireUpdateByStockStatusProviderInterface $targetIdsToRequireUpdateByStockStatusProvider = null,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->apiKeysProvider = $apiKeysProvider;
        $this->storesProvider = $storesProvider;
        $this->stockStatusCriteria = $stockStatusCriteria;
        $this->setIndexingEntitiesToRequireUpdateAction = $setIndexingEntitiesToRequireUpdateAction;
        $this->markReservationsForUpdateAction = $markReservationsForUpdateAction;

        $objectManager = ObjectManager::getInstance();
        $this->targetIdsToRequireUpdateByStockStatusProvider = $targetIdsToRequireUpdateByStockStatusProvider
            ?? $objectManager->get(TargetIdsToRequireUpdateByStockStatusProviderInterface::class);
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
                    message: 'Conflicting orig stock status for stores; marking record for update',
                    context: [
                        'method' => __METHOD__,
                        'stockId' => $reservation->getStockId(),
                        'sku' => $reservation->getSku(),
                        'apiKeys' => $apiKeys,
                        'targetIdsByStockStatus' => $exception->getTargetIdsByStockStatus(),
                    ],
                );

                $this->markReservationsForUpdateAction->execute(
                    reservations: [$reservation],
                );

                continue;
            }

            foreach ($targetIdsToRequireUpdate as $stockStatus => $targetIdItems) {
                if (!$targetIdItems) {
                    continue;
                }

                $this->setIndexingEntitiesToRequireUpdateAction->execute(
                    entityType: 'KLEVU_PRODUCT',
                    apiKey: $apiKey,
                    targetIds: $targetIdItems,
                    origValues: [
                        $this->stockStatusCriteria->getCriteriaIdentifier() => (bool)$stockStatus,
                    ],
                );
            }
        }
    }
}
