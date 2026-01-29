<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Plugin\InventoryReservations\Model\AppendReservations;

use Klevu\Indexing\Model\IndexingEntity;
use Klevu\Indexing\Test\Integration\Traits\IndexingEntitiesTrait;
use Klevu\IndexingApi\Api\Data\IndexingEntityInterface;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingMsi\Model\Source\AppendReservationsAction;
use Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations\MarkForUpdatePlugin;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\SetAreaTrait;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Inventory\Model\Source;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Group as StoreGroup;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers \Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations\MarkForUpdatePlugin::class
 * @method MarkForUpdatePlugin instantiateTestObject(?array $arguments = null)
 * @runTestsInSeparateProcesses
 */
class MarkForUpdatePluginTest extends TestCase
{
    use AppendReservationsPluginTestTrait {
        AppendReservationsPluginTestTrait::setUp as trait_setUp;
    }
    use AttributeTrait;
    use IndexingEntitiesTrait;
    use ObjectInstantiationTrait;
    use ProductTrait;
    use SetAreaTrait;
    use StoreTrait {
        StoreTrait::createStore as trait_createStore;
    }
    use WebsiteTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->implementationFqcn = MarkForUpdatePlugin::class;

        $this->trait_setUp();
    }

    public function testFqcnResolvesToExpectedImplementation(): object
    {
        $this->markTestSkipped();
    }

    /**
     * @group wip
     */
    public function testPluginIsAttached(): void
    {
        $this->configWriter->save(
            path: 'klevu/indexing/append_reservations_action',
            value: AppendReservationsAction::MARK_FOR_UPDATE->value,
        );

        // phpcs:disable SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable
        extract(
            array: $this->initPluginIsAttached(),
        );
        /**
         * @var Website $website
         * @var StoreGroup $storeGroup
         * @var Store $store
         * @var Source $source
         * @var SourceItemInterface $sourceItem
         * @var StockInterface $stock
         * @var Product $product
         * @var IndexingEntityInterface $indexingEntity
         */
        // phpcs:enable SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable

        $this->assertFalse(
            condition: $indexingEntity->getRequiresUpdate(),
            message: 'Indexing Entity Requires Update before',
        );
        $this->assertEmpty(
            actual: $indexingEntity->getRequiresUpdateOrigValues(),
            message: 'Indexing Entity Requires Update Orig Values empty before',
        );

        $this->storesProvider->cleanCache();
        sleep(5); // don't remove this, idk why it's needed. Also, doesn't always work @todo
        $productStockStatusBefore = $this->productStockStatusProvider->get(
            product: $product,
            store: $store,
        );
        $this->assertTrue(
            condition: $productStockStatusBefore,
            message: 'Product Stock Status Before',
        );

        $productInStore = $this->productRepository->get(
            sku: $product->getSku(),
            editMode: false,
            storeId: (int)$store->getId(),
            forceReload: true,
        );
        $this->assertTrue(
            condition: $productInStore->isAvailable(),
            message: 'Is Available Before',
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => $stock->getId(),
            'sku' => $product->getSku(),
            'quantity' => 0 - $sourceItem->getQuantity(),
        ]);

        $this->appendReservations->execute(
            reservations: [
                $reservation,
            ],
        );

        $productInStore = $this->productRepository->get(
            sku: $product->getSku(),
            editMode: false,
            storeId: (int)$store->getId(),
            forceReload: true,
        );
        $this->assertFalse(
            condition: $productInStore->isAvailable(),
            message: 'Is Available After',
        );

        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ID,
            value: (int)$product->getId(),
            conditionType: 'eq',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ENTITY_TYPE,
            value: 'KLEVU_PRODUCT',
            conditionType: 'eq',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::API_KEY,
            value: 'klevu-1234567890',
            conditionType: 'eq',
        );
        $indexingEntityResult = $this->indexingEntityRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $indexingEntityItems = $indexingEntityResult->getItems();
        $this->assertCount(
            expectedCount: 1,
            haystack: $indexingEntityItems,
            message: 'Total matching indexing entities',
        );

        $indexingEntity = current($indexingEntityItems);
        $this->assertSame(
            expected: Actions::UPDATE,
            actual: $indexingEntity->getNextAction(),
        );
        $this->assertFalse(
            condition: $indexingEntity->getRequiresUpdate(),
            message: 'Requires update after Append Reservations',
        );
        $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
        $this->assertCount(
            expectedCount: 0,
            haystack: $requiresUpdateOrigValues,
            message: 'Requires update orig values count after Append Reservations',
        );

        $this->cleanUpPluginIsAttached(
            website: $website,
            storeGroup: $storeGroup,
            store: $store,
            source: $source,
            product: $product,
        );
    }

    /**
     * @testWith ["mark_for_update"]
     */
    public function testAfterExecute(
        ?string $appendReservationsActionConfigValue,
    ): void {
        $loggerMock = $this->getMockLogger();

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate',
                'code' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'is_active' => true,
            ],
        );
        $storeFixture = $this->storeFixturesPool->get('klevu_test_msirequiresupdate');

        ConfigFixture::setGlobal(
            path: 'klevu/indexing/append_reservations_action',
            value: $appendReservationsActionConfigValue,
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture->getId(),
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->once())
            ->method('execute')
            ->with(
                [$reservation],
            );

        $markForUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        $markForUpdatePlugin->afterExecute(
            subject: $this->appendReservations,
            result: null,
            reservations: [
                $reservation,
            ],
        );
    }

    /**
     * @testWith ["calculate_requires_update"]
     *           ["no_action"]
     *           ["does_not_exist"]
     *           [null]
     */
    public function testAfterExecute_DisabledByConfig(
        ?string $appendReservationsActionConfigValue,
    ): void {
        $loggerMock = $this->getMockLogger();

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate',
                'code' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'is_active' => true,
            ],
        );
        $storeFixture = $this->storeFixturesPool->get('klevu_test_msirequiresupdate');

        ConfigFixture::setGlobal(
            path: 'klevu/indexing/append_reservations_action',
            value: $appendReservationsActionConfigValue,
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture->getId(),
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $markForUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        $markForUpdatePlugin->afterExecute(
            subject: $this->appendReservations,
            result: null,
            reservations: [
                $reservation,
            ],
        );
    }

    private function createStore(?array $storeData = []): void
    {
        $storeCode = $storeData['code'] ?? 'klevu_test_store_1';
        try {
            /** @var StoreInterface&AbstractModel $store */
            $store = $this->storeRepository->get($storeCode);

            $this->setArea(Area::AREA_ADMINHTML);
//            $this->registry->register(
//                key: 'isSecureArea',
//                value: true,
//            );
            $this->storeResource->delete($store);
        } catch (NoSuchEntityException) {
            // This is expected
        }

        $this->trait_createStore($storeData);
    }
}
