<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Action;

use Klevu\Indexing\Model\IndexingEntity;
use Klevu\Indexing\Test\Integration\Traits\IndexingEntitiesTrait;
use Klevu\IndexingApi\Api\IndexingEntityRepositoryInterface;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdate;
use Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdateInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterfaceFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Catalog\ProductFixturePool;

/**
 * @covers \Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdate::class
 * @method MarkReservationsForUpdate instantiateTestObject(?array $arguments = null)
 */
class MarkReservationsForUpdateTest extends TestCase
{
    use AttributeTrait;
    use IndexingEntitiesTrait;
    use ObjectInstantiationTrait;
    use ProductTrait;
    use StoreTrait;
    use WebsiteTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
    /**
     * @var ReservationInterfaceFactory|null
     */
    private ?ReservationInterfaceFactory $reservationFactory = null; // @phpstan-ignore-line
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null; // @phpstan-ignore-line
    /**
     * @var IndexingEntityRepositoryInterface|null
     */
    private ?IndexingEntityRepositoryInterface $indexingEntityRepository = null; // @phpstan-ignore-line

    protected function setUp(): void
    {
        parent::setUp();

        $this->implementationFqcn = MarkReservationsForUpdate::class;
        $this->interfaceFqcn = MarkReservationsForUpdateInterface::class;

        $this->objectManager = Bootstrap::getObjectManager();
        $this->attributeFixturePool = $this->objectManager->get(AttributeFixturePool::class);
        $this->productFixturePool = $this->objectManager->get(ProductFixturePool::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);

        $this->reservationFactory = $this->objectManager->get(ReservationInterfaceFactory::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->indexingEntityRepository = $this->objectManager->get(IndexingEntityRepositoryInterface::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->cleanIndexingEntities('klevu-1234567890');
        $this->cleanIndexingEntities('klevu-9876543210');

        $this->productFixturePool->rollback();
        $this->attributeFixturePool->rollback();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    public function testExecute_NoMappedApiKeys(): void
    {
        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msimarkres',
            'quantity' => -1,
        ]);

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn([]);

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msimarkres',
                'sku' => 'klevu_test_msimarkres',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msimarkres');

        $this->cleanIndexingEntities('klevu-1234567890');
        $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$productFixture->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-1234567890',
                IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                IndexingEntity::IS_INDEXABLE => true,
                IndexingEntity::REQUIRES_UPDATE => false,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );

        $markReservationsForUpdateAction = $this->instantiateTestObject([
            'apiKeysProvider' => $apiKeysProviderMock,
        ]);

        $markReservationsForUpdateAction->execute(
            reservations: [$reservation],
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
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ID,
            value: (int)$productFixture->getId(),
            conditionType: 'eq',
        );
        $indexingEntityResult = $this->indexingEntityRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $indexingEntities = $indexingEntityResult->getItems();
        $this->assertCount(
            expectedCount: 1,
            haystack: $indexingEntities,
        );
        /** @var IndexingEntity $indexingEntity */
        $indexingEntity = current($indexingEntities);
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntity->getNextAction(),
        );
        $this->assertFalse(
            condition: $indexingEntity->getRequiresUpdate(),
        );
        $this->assertEmpty(
            actual: $indexingEntity->getRequiresUpdateOrigValues(),
        );
    }

    public function testExecute_NoMatchingProducts(): void
    {
        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msimarkres_notexists',
            'quantity' => -1,
        ]);

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn([
                'klevu-1234567890',
            ]);

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msimarkres',
                'sku' => 'klevu_test_msimarkres',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msimarkres');

        $this->cleanIndexingEntities('klevu-1234567890');
        $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$productFixture->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-1234567890',
                IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                IndexingEntity::IS_INDEXABLE => true,
                IndexingEntity::REQUIRES_UPDATE => false,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );

        $markReservationsForUpdateAction = $this->instantiateTestObject([
            'apiKeysProvider' => $apiKeysProviderMock,
        ]);

        $markReservationsForUpdateAction->execute(
            reservations: [$reservation],
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
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ID,
            value: (int)$productFixture->getId(),
            conditionType: 'eq',
        );
        $indexingEntityResult = $this->indexingEntityRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $indexingEntities = $indexingEntityResult->getItems();
        $this->assertCount(
            expectedCount: 1,
            haystack: $indexingEntities,
        );
        /** @var IndexingEntity $indexingEntity */
        $indexingEntity = current($indexingEntities);
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntity->getNextAction(),
        );
        $this->assertFalse(
            condition: $indexingEntity->getRequiresUpdate(),
        );
        $this->assertEmpty(
            actual: $indexingEntity->getRequiresUpdateOrigValues(),
        );
    }

    public function testExecute_NoIndexingEntities(): void
    {
        /** @var ReservationInterface $reservation */
        $reservation = $this->reservationFactory->create([
            'reservationId' => null,
            'stockId' => 1,
            'sku' => 'klevu_test_msimarkres',
            'quantity' => -1,
        ]);

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1])
            ->willReturn([
                'klevu-1234567890',
            ]);

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msimarkres',
                'sku' => 'klevu_test_msimarkres',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture = $this->productFixturePool->get('klevu_test_msimarkres');

        $this->cleanIndexingEntities('klevu-1234567890');

        $markReservationsForUpdateAction = $this->instantiateTestObject([
            'apiKeysProvider' => $apiKeysProviderMock,
        ]);

        $markReservationsForUpdateAction->execute(
            reservations: [$reservation],
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
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ID,
            value: (int)$productFixture->getId(),
            conditionType: 'eq',
        );
        $indexingEntityResult = $this->indexingEntityRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $indexingEntities = $indexingEntityResult->getItems();
        $this->assertCount(
            expectedCount: 0,
            haystack: $indexingEntities,
        );
    }

    public function testExecute(): void
    {
        /** @var ReservationInterface[] $reservations */
        $reservations = [
            $this->reservationFactory->create([
                'reservationId' => null,
                'stockId' => 1,
                'sku' => 'klevu_test_msimarkres_1',
                'quantity' => -1,
            ]),
            $this->reservationFactory->create([
                'reservationId' => null,
                'stockId' => 2,
                'sku' => 'klevu_test_msimarkres_2',
                'quantity' => -2,
            ]),
        ];

        $apiKeysProviderMock = $this->getMockApiKeysProvider();
        $apiKeysProviderMock->expects($this->once())
            ->method('getForStockIds')
            ->with([1, 2])
            ->willReturn([
                'klevu-1234567890',
                'klevu-9876543210',
            ]);

        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msimarkres_1',
                'sku' => 'klevu_test_msimarkres_1',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture1 = $this->productFixturePool->get('klevu_test_msimarkres_1');
        $this->createProduct(
            productData: [
                'key' => 'klevu_test_msimarkres_2',
                'sku' => 'klevu_test_msimarkres_2',
                'name' => 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                'price' => 100.00,
                'status' => ProductStatus::STATUS_ENABLED,
                'visibility' => ProductVisibility::VISIBILITY_BOTH,
                'qty' => 1.0,
                'weight' => 1.0,
                'type_id' => ProductType::TYPE_SIMPLE,
            ],
        );
        $productFixture2 = $this->productFixturePool->get('klevu_test_msimarkres_2');

        $this->cleanIndexingEntities('klevu-1234567890');
        $this->cleanIndexingEntities('klevu-9876543210');
        $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$productFixture1->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-1234567890',
                IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                IndexingEntity::IS_INDEXABLE => true,
                IndexingEntity::REQUIRES_UPDATE => false,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );
        $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$productFixture1->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-9876543210',
                IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                IndexingEntity::IS_INDEXABLE => false,
                IndexingEntity::REQUIRES_UPDATE => true,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );
        $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$productFixture2->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-9876543210',
                IndexingEntity::NEXT_ACTION => Actions::ADD,
                IndexingEntity::IS_INDEXABLE => true,
                IndexingEntity::REQUIRES_UPDATE => true,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );

        $markReservationsForUpdateAction = $this->instantiateTestObject([
            'apiKeysProvider' => $apiKeysProviderMock,
        ]);

        $markReservationsForUpdateAction->execute(
            reservations: $reservations,
        );

        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ENTITY_TYPE,
            value: 'KLEVU_PRODUCT',
            conditionType: 'eq',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::API_KEY,
            value: [
                'klevu-1234567890',
                'klevu-9876543210',
            ],
            conditionType: 'in',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: IndexingEntity::TARGET_ID,
            value: [
                (int)$productFixture1->getId(),
                (int)$productFixture2->getId(),
            ],
            conditionType: 'in',
        );
        $indexingEntityResult = $this->indexingEntityRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );

        $indexingEntities = $indexingEntityResult->getItems();
        $this->assertCount(
            expectedCount: 3,
            haystack: $indexingEntities,
        );

        foreach ($indexingEntities as $indexingEntity) {
            switch (true) {
                case 'klevu-1234567890' === $indexingEntity->getApiKey()
                    && (int)$productFixture1->getId() === $indexingEntity->getTargetId():
                    $this->assertSame(
                        expected: Actions::UPDATE,
                        actual: $indexingEntity->getNextAction(),
                    );
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    break;

                case 'klevu-9876543210' === $indexingEntity->getApiKey()
                    && (int)$productFixture1->getId() === $indexingEntity->getTargetId():
                    $this->assertSame(
                        expected: Actions::NO_ACTION,
                        actual: $indexingEntity->getNextAction(),
                    );
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    break;

                case 'klevu-9876543210' === $indexingEntity->getApiKey()
                    && (int)$productFixture2->getId() === $indexingEntity->getTargetId():
                    $this->assertSame(
                        expected: Actions::ADD,
                        actual: $indexingEntity->getNextAction(),
                    );
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    break;

                default:
                    $this->fail(
                        message: 'Unexpected indexing entity discovered',
                    );
                    break;
            }
        }
    }

    /**
     * @param string[] $expectedLogLevels
     *
     * @return MockObject&LoggerInterface
     */
    private function getMockLogger(array $expectedLogLevels = []): MockObject
    {
        $mockLogger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $notExpectedLogLevels = array_diff(
            [
                'emergency',
                'alert',
                'critical',
                'error',
                'warning',
                'notice',
                'info',
                'debug',
            ],
            $expectedLogLevels,
        );
        foreach ($notExpectedLogLevels as $notExpectedLogLevel) {
            $mockLogger->expects($this->never())
                ->method($notExpectedLogLevel);
        }

        return $mockLogger;
    }

    /**
     * @return MockObject&ApiKeysProviderInterface
     */
    private function getMockApiKeysProvider(): MockObject
    {
        return $this->getMockBuilder(ApiKeysProviderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
