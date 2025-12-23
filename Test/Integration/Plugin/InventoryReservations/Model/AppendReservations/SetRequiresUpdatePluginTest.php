<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Plugin\InventoryReservations\Model\AppendReservations;

use Klevu\Indexing\Exception\IndexingEntitySaveException;
use Klevu\Indexing\Model\IndexingEntity;
use Klevu\Indexing\Test\Integration\Traits\IndexingEntitiesTrait;
use Klevu\IndexingApi\Api\Data\IndexingEntityInterface;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingMsi\Model\Source\AppendReservationsAction;
use Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations\SetRequiresUpdatePlugin;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\AttributeInterface;
use Magento\Framework\Exception\NoSuchEntityException;
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
 * @covers \Klevu\IndexingMsi\Plugin\InventoryReservations\Model\AppendReservations\SetRequiresUpdatePlugin::class
 * @method SetRequiresUpdatePlugin instantiateTestObject(?array $arguments = null)
 * @runTestsInSeparateProcesses
 */
class SetRequiresUpdatePluginTest extends TestCase
{
    use AppendReservationsPluginTestTrait {
        AppendReservationsPluginTestTrait::setUp as trait_setUp;
    }
    use AttributeTrait;
    use IndexingEntitiesTrait;
    use ObjectInstantiationTrait;
    use ProductTrait;
    use StoreTrait;
    use WebsiteTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->implementationFqcn = SetRequiresUpdatePlugin::class;

        $this->trait_setUp();
    }

    public function testFqcnResolvesToExpectedImplementation(): object
    {
        $this->markTestSkipped();
    }

    public function testPluginIsAttached(): void
    {
        $this->configWriter->save(
            path: 'klevu/indexing/append_reservations_action',
            value: AppendReservationsAction::CALCULATE_REQUIRES_UPDATE->value,
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
            expected: Actions::NO_ACTION,
            actual: $indexingEntity->getNextAction(),
        );
        $this->assertTrue(
            condition: $indexingEntity->getRequiresUpdate(),
            message: 'Requires update after Append Reservations',
        );
        $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
        $this->assertCount(
            expectedCount: 1,
            haystack: $requiresUpdateOrigValues,
            message: 'Requires update orig values count after Append Reservations',
        );
        $this->assertArrayHasKey(
            key: StockStatus::CRITERIA_IDENTIFIER,
            array: $requiresUpdateOrigValues,
            message: 'Orig values has stock_status',
        );
        $this->assertTrue(
            condition: $requiresUpdateOrigValues[StockStatus::CRITERIA_IDENTIFIER],
            message: 'Stock Status orig value is true',
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
     * @testWith ["calculate_requires_update"]
     *           ["does_not_exist"]
     *           [null]
     */
    public function testBeforeExecute(
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

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                    ],
                ],
            );

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->once())
            ->method('execute')
            ->with(
                'KLEVU_PRODUCT',
                'klevu-1234567890',
                [
                    [
                        'target_id' => (int)$productFixture->getId(),
                        'target_parent_id' => null,
                    ],
                ],
                [
                    'stock_status' => true,
                ],
            );

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    /**
     * @testWith ["mark_for_update"]
     *           ["no_action"]
     */
    public function testBeforeExecute_DisabledByConfig(
        string $appendReservationsActionConfigValue,
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

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->never())
            ->method('getForStockIds');

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->never())
            ->method('execute');

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    public function testBeforeExecute_NoApiKeysMapped(): void
    {
        $loggerMock = $this->getMockLogger();

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn([]);

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->never())
            ->method('execute');

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    public function testBeforeExecute_MultipleApiKeysForStock(): void
    {
        $loggerMock = $this->getMockLogger();

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_1',
                'code' => 'klevu_test_msirequiresupdate_1',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (1)',
                'is_active' => true,
            ],
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_1');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_2',
                'code' => 'klevu_test_msirequiresupdate_2',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (2)',
                'is_active' => false,
            ],
        );
        $storeFixture2 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_2');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-9876543210',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE9876543210',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                        'klevu-9876543210',
                    ],
                ],
            );

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $expectation = $this->exactly(2);
        $setIndexingEntitiesToRequireUpdateActionMock->expects($expectation)
            ->method('execute')
            ->willReturnCallback(
                function (
                    string $entityType,
                    string $apiKey,
                    array $entityIds,
                    array $origValues,
                ) use ($expectation, $productFixture): void {
                    $invocationCount = match (true) {
                        method_exists($expectation, 'getInvocationCount') => $expectation->getInvocationCount(),
                        method_exists($expectation, 'numberOfInvocations') => $expectation->numberOfInvocations(),
                        default => throw new \RuntimeException('Cannot determine invocation count from matcher'),
                    };

                    $this->assertSame(
                        expected: 'KLEVU_PRODUCT',
                        actual: $entityType,
                    );
                    $this->assertSame(
                        expected: match ($invocationCount) {
                            1 => 'klevu-1234567890',
                            2 => 'klevu-9876543210',
                            default => $this->fail('Unexpected invocation count'),
                        },
                        actual: $apiKey,
                    );
                    $this->assertSame(
                        expected: [
                            [
                                'target_id' => (int)$productFixture->getId(),
                                'target_parent_id' => null,
                            ],
                        ],
                        actual: $entityIds,
                    );
                    $this->assertArrayHasKey(
                        key: 'stock_status',
                        array: $origValues,
                    );
                    $this->assertTrue(
                        condition: $origValues['stock_status'],
                    );
                },
            );

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    public function testBeforeExecute_ConflictingStockValues(): void
    {
        $loggerMock = $this->getMockLogger(
            expectedLogLevels: ['warning'],
        );
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Conflicting orig stock status for stores; marking record for update',
                $this->callback(
                    callback: function (array $context): bool {
                        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('stockId', $context);
                        $this->assertSame(
                            expected: 1,
                            actual: $context['stockId'],
                        );
                        $this->assertArrayHasKey('sku', $context);
                        $this->assertSame(
                            expected: 'klevu_test_msirequiresupdate',
                            actual: $context['sku'],
                        );
                        $this->assertArrayHasKey('apiKeys', $context);
                        $this->assertSame(
                            expected: [
                                1 => ['klevu-1234567890'],
                            ],
                            actual: $context['apiKeys'],
                        );
                        $this->assertArrayHasKey('entityIdsForUpdate', $context);
                        $this->assertSame(
                            expected: [
                                0 => [
                                    [
                                        'target_id' => (int)$productFixture->getId(),
                                        'target_parent_id' => null,
                                    ],
                                ],
                                1 => [
                                    [
                                        'target_id' => (int)$productFixture->getId(),
                                        'target_parent_id' => null,
                                    ],
                                ],
                            ],
                            actual: $context['entityIdsForUpdate'],
                        );

                        return true;
                    },
                ),
            );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_1',
                'code' => 'klevu_test_msirequiresupdate_1',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (1)',
                'is_active' => true,
            ],
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_1');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_2',
                'code' => 'klevu_test_msirequiresupdate_2',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (2)',
                'is_active' => false,
            ],
        );
        $storeFixture2 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_2');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                    ],
                ],
            );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $markReservationsForUpdateAction = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateAction->expects($this->once())
            ->method('execute')
            ->with(
                [$reservation],
            );

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->never())
            ->method('execute');

        $productStockStatusProviderMock = $this->getMockProductStockStatusProvider();
        $productStockStatusProviderMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                callback: function (
                    ProductInterface $product,
                    StoreInterface $store,
                    ?ProductInterface $parentProduct,
                ) use ($productFixture, $storeFixture1, $storeFixture2): bool {
                    $this->assertSame(
                        expected: (int)$productFixture->getId(),
                        actual: (int)$product->getId(),
                    );
                    $this->assertTrue(
                        condition: in_array(
                            needle: $store->getCode(),
                            haystack: [
                                $storeFixture1->getCode(),
                                $storeFixture2->getCode(),
                            ],
                            strict: true,
                        ),
                    );
                    $this->assertNull(
                        actual: $parentProduct,
                    );

                    return match ($store->getCode()) {
                        $storeFixture1->getCode() => true,
                        $storeFixture2->getCode() => false,
                        default => $this->fail('Unexpected store code'),
                    };
                },
            );

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'productStockStatusProvider' => $productStockStatusProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateAction,
            ],
        );

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );

    }

    public function testBeforeExecute_ExceptionInProductRepository(): void
    {
        $loggerMock = $this->getMockLogger(
            expectedLogLevels: ['error'],
        );
        $loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to set indexing entities to require update on append reservations',
                $this->callback(
                    callback: function (array $context): bool {
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('exception', $context);
                        $this->assertSame(
                            expected: NoSuchEntityException::class,
                            actual: $context['exception'],
                        );
                        $this->assertArrayHasKey('error', $context);
                        $this->assertNotEmpty($context['error']);
                        $this->assertArrayHasKey('stockId', $context);
                        $this->assertSame(
                            expected: 1,
                            actual: $context['stockId'],
                        );
                        $this->assertArrayHasKey('sku', $context);
                        $this->assertSame(
                            expected: 'klevu_test_sku_not_exists',
                            actual: $context['sku'],
                        );
                        $this->assertArrayHasKey('apiKeys', $context);
                        $this->assertSame(
                            expected: [
                                1 => ['klevu-1234567890'],
                            ],
                            actual: $context['apiKeys'],
                        );

                        return true;
                    },
                ),
            );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate',
                'code' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'is_active' => true,
            ],
        );
        $storeFixture = $this->storeFixturesPool->get('klevu_test_msirequiresupdate');

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

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $this->productFixturePool->get('klevu_test_msirequiresupdate');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                    ],
                ],
            );

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->never())
            ->method('execute');

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_sku_not_exists',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    public function testBeforeExecute_ExceptionInSetIndexingEntitiesToUpdate(): void
    {
        $loggerMock = $this->getMockLogger(
            expectedLogLevels: ['warning', 'error'],
        );
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Conflicting orig stock status for stores; marking record for update',
                $this->callback(
                    callback: function (array $context): bool {
                        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('stockId', $context);
                        $this->assertSame(
                            expected: 1,
                            actual: $context['stockId'],
                        );
                        $this->assertArrayHasKey('sku', $context);
                        $this->assertSame(
                            expected: 'klevu_test_msirequiresupdate',
                            actual: $context['sku'],
                        );
                        $this->assertArrayHasKey('apiKeys', $context);
                        $this->assertSame(
                            expected: [
                                1 => ['klevu-1234567890'],
                            ],
                            actual: $context['apiKeys'],
                        );
                        $this->assertArrayHasKey('entityIdsForUpdate', $context);
                        $this->assertSame(
                            expected: [
                                0 => [
                                    [
                                        'target_id' => (int)$productFixture->getId(),
                                        'target_parent_id' => null,
                                    ],
                                ],
                                1 => [
                                    [
                                        'target_id' => (int)$productFixture->getId(),
                                        'target_parent_id' => null,
                                    ],
                                ],
                            ],
                            actual: $context['entityIdsForUpdate'],
                        );

                        return true;
                    },
                ),
            );
        $loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to set indexing entities to require update on append reservations',
                $this->callback(
                    callback: function (array $context): bool {
                        $this->assertArrayHasKey('method', $context);
                        $this->assertArrayHasKey('exception', $context);
                        $this->assertSame(
                            expected: IndexingEntitySaveException::class,
                            actual: $context['exception'],
                        );
                        $this->assertArrayHasKey('error', $context);
                        $this->assertNotEmpty($context['error']);
                        $this->assertArrayHasKey('stockId', $context);
                        $this->assertSame(
                            expected: 1,
                            actual: $context['stockId'],
                        );
                        $this->assertArrayHasKey('sku', $context);
                        $this->assertSame(
                            expected: 'klevu_test_msirequiresupdate',
                            actual: $context['sku'],
                        );
                        $this->assertArrayHasKey('apiKeys', $context);
                        $this->assertSame(
                            expected: [
                                1 => ['klevu-1234567890'],
                            ],
                            actual: $context['apiKeys'],
                        );

                        return true;
                    },
                ),
            );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_1',
                'code' => 'klevu_test_msirequiresupdate_1',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (1)',
                'is_active' => true,
            ],
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_1');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msirequiresupdate_2',
                'code' => 'klevu_test_msirequiresupdate_2',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (2)',
                'is_active' => false,
            ],
        );
        $storeFixture2 = $this->storeFixturesPool->get('klevu_test_msirequiresupdate_2');

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$storeFixture2->getId(),
        );

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                    ],
                ],
            );

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->once())
            ->method('execute')
            ->willThrowException(
                exception: new IndexingEntitySaveException(
                    phrase: __('PHPUnit Test Exception'),
                ),
            );

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->never())
            ->method('execute');

        $productStockStatusProviderMock = $this->getMockProductStockStatusProvider();
        $productStockStatusProviderMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                callback: function (
                    ProductInterface $product,
                    StoreInterface $store,
                    ?ProductInterface $parentProduct,
                ) use ($productFixture, $storeFixture1, $storeFixture2): bool {
                    $this->assertSame(
                        expected: (int)$productFixture->getId(),
                        actual: (int)$product->getId(),
                    );
                    $this->assertTrue(
                        condition: in_array(
                            needle: $store->getCode(),
                            haystack: [
                                $storeFixture1->getCode(),
                                $storeFixture2->getCode(),
                            ],
                            strict: true,
                        ),
                    );
                    $this->assertNull(
                        actual: $parentProduct,
                    );

                    return match ($store->getCode()) {
                        $storeFixture1->getCode() => true,
                        $storeFixture2->getCode() => false,
                        default => $this->fail('Unexpected store code'),
                    };
                },
            );

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'productStockStatusProvider' => $productStockStatusProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }

    public function testBeforeExecute_ConfigurableVariant(): void
    {
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

        $this->createAttribute(
            attributeData: [
                'key' => 'klevu_test_msirequiresupdate',
                'code' => 'klevu_test_msirequiresupdate',
                'attribute_type' => 'configurable',
            ],
        );
        $attributeFixture = $this->attributeFixturePool->get('klevu_test_msirequiresupdate');
        /** @var AbstractAttribute&AttributeInterface $attribute */
        $attribute = $attributeFixture->getAttribute();
        $attributeSource = $attribute->getSource();

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate',
                'sku' => 'klevu_test_msirequiresupdate',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
                'data' => [
                    $attributeFixture->getAttributeCode() => $attributeSource->getOptionId('Option 1'),
                ],
            ],
        );
        $variantProductFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate');
        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msirequiresupdate_conf',
                'sku' => 'klevu_test_msirequiresupdate_conf',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin (Configurable)',
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'type_id' => Configurable::TYPE_CODE,
                'configurable_attributes' => [
                    $attributeFixture->getAttribute(),
                ],
                'variants' => [
                    $variantProductFixture->getProduct(),
                ],
            ],
        );
        $configurableProductFixture = $this->productFixturePool->get('klevu_test_msirequiresupdate_conf');

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn(
                [
                    1 => [
                        'klevu-1234567890',
                    ],
                ],
            );

        $markReservationsForUpdateActionMock = $this->getMockMarkReservationsForUpdateAction();
        $markReservationsForUpdateActionMock->expects($this->never())
            ->method('execute');

        $setIndexingEntitiesToRequireUpdateActionMock = $this->getMockSetIndexingEntitiesToRequiresUpdateAction();
        $setIndexingEntitiesToRequireUpdateActionMock->expects($this->once())
            ->method('execute')
            ->with(
                'KLEVU_PRODUCT',
                'klevu-1234567890',
                [
                    [
                        'target_id' => (int)$variantProductFixture->getId(),
                        'target_parent_id' => null,
                    ],
                    [
                        'target_id' => (int)$variantProductFixture->getId(),
                        'target_parent_id' => (int)$configurableProductFixture->getId(),
                    ],
                    [
                        'target_id' => (int)$configurableProductFixture->getId(),
                        'target_parent_id' => null,
                    ],
                ],
                [
                    'stock_status' => true,
                ],
            );

        $setRequiresUpdatePlugin = $this->instantiateTestObject(
            arguments: [
                'logger' => $loggerMock,
                'apiKeysProvider' => $apiKeysProviderMock,
                'setIndexingEntitiesToRequireUpdateAction' => $setIndexingEntitiesToRequireUpdateActionMock,
                'markReservationsForUpdateAction' => $markReservationsForUpdateActionMock,
            ],
        );

        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msirequiresupdate',
            'quantity' => -1,
        ]);

        $result = $setRequiresUpdatePlugin->beforeExecute(
            subject: $this->appendReservations,
            reservations: [
                $reservation,
            ],
        );

        $this->assertEquals(
            expected: [
                [
                    $reservation,
                ],
            ],
            actual: $result,
        );
    }
}
