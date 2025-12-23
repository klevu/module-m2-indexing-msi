<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations;

use Klevu\IndexingMsi\Model\Source\AppendReservationsAction;
use Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\InventoryReservations\Model\AppendReservations;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Psr\Log\LoggerInterface;

class MarkForUpdatePlugin
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
     * @var MarkReservationsForUpdateInterface
     */
    private readonly MarkReservationsForUpdateInterface $markReservationsForUpdate;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param MarkReservationsForUpdateInterface $markReservationsForUpdateAction
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        MarkReservationsForUpdateInterface $markReservationsForUpdateAction,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->markReservationsForUpdate = $markReservationsForUpdateAction;
    }

    /**
     * @param AppendReservations $subject
     * @param null $result
     * @param ReservationInterface[] $reservations
     *
     * @return void
     */
    public function afterExecute(
        AppendReservations $subject, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        mixed $result, // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        array $reservations,
    ): void {
        if (AppendReservationsAction::MARK_FOR_UPDATE !== $this->getAppendReservationsAction()) {
            return;
        }

        try {
            $this->markReservationsForUpdate->execute(
                reservations: $reservations,
            );
        } catch (\Exception $exception) {
            $this->logger->error(
                message: 'Failed to set indexing entities to update on append reservations',
                context: [
                    'method' => __METHOD__,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                    'skus' => array_map(
                        callback: static fn (ReservationInterface $reservation): string => $reservation->getSku(),
                        array: $reservations,
                    ),
                    'parentIds' => $parentIds ?? null,
                ],
            );
        }
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
}
