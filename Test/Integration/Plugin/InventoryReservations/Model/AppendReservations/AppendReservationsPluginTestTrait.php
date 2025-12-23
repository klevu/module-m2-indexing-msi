<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Plugin\InventoryReservations\Model\AppendReservations;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\Indexing\Model\IndexingEntity;
use Klevu\IndexingApi\Api\IndexingEntityRepositoryInterface;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingApi\Service\Action\SetIndexingEntitiesToRequireUpdateActionInterface;
use Klevu\IndexingMsi\Service\Action\MarkReservationsForUpdateInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProviderInterface;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Validation\ValidationException;
use Magento\Inventory\Model\ResourceModel\Source as SourceResource;
use Magento\Inventory\Model\ResourceModel\SourceItem as SourceItemResource;
use Magento\Inventory\Model\ResourceModel\StockSourceLink as StockSourceLinkResource;
use Magento\Inventory\Model\Source;
use Magento\Inventory\Model\Stock;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;
use Magento\InventoryCatalogApi\Api\BulkSourceUnassignInterface;
use Magento\InventoryCatalogApi\Model\SourceItemsProcessorInterface;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Model\DeleteSalesChannelToStockLinkInterface;
use Magento\InventorySalesApi\Model\ReplaceSalesChannelsForStockInterface;
use Magento\Store\Api\GroupRepositoryInterface as StoreGroupRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Group as StoreGroup;
use Magento\Store\Model\GroupFactory as StoreGroupFactory;
use Magento\Store\Model\ResourceModel\Group as StoreGroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Catalog\ProductFixturePool;

trait AppendReservationsPluginTestTrait
{
    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
    /**
     * @var AppendReservationsInterface|null
     */
    private ?AppendReservationsInterface $appendReservations = null;
    /**
     * @var ConfigWriter|null
     */
    private ?ConfigWriter $configWriter = null; // @phpstan-ignore-line
    /**
     * @var ReservationInterfaceFactory|null
     */
    private ?ReservationInterfaceFactory $reservationFactory = null; // @phpstan-ignore-line
    /**
     * @var StockInterfaceFactory|null
     */
    private ?StockInterfaceFactory $stockFactory = null; // @phpstan-ignore-line
    /**
     * @var StockRepositoryInterface|null
     */
    private ?StockRepositoryInterface $stockRepository = null; // @phpstan-ignore-line
    /**
     * @var SalesChannelInterfaceFactory|null
     */
    private ?SalesChannelInterfaceFactory $salesChannelFactory = null; // @phpstan-ignore-line
    /**
     * @var ReplaceSalesChannelsForStockInterface|null
     */
    private ?ReplaceSalesChannelsForStockInterface $replaceSalesChannelsForStock = null; // @phpstan-ignore-line
    /**
     * @var DeleteSalesChannelToStockLinkInterface|null
     */
    private ?DeleteSalesChannelToStockLinkInterface $deleteSalesChannelToStockLink = null; // @phpstan-ignore-line
    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resourceConnection = null;
    /**
     * @var Registry|null
     */
    private ?Registry $registry = null;
    /**
     * @var ScopeConfigInterface|null
     */
    private ?ScopeConfigInterface $scopeConfig = null;
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var WebsiteRepositoryInterface|null
     */
    private ?WebsiteRepositoryInterface $websiteRepository = null;
    /**
     * @var WebsiteResource|null
     */
    private ?WebsiteResource $websiteResource = null;
    /**
     * @var WebsiteFactory|null
     */
    private ?WebsiteFactory $websiteFactory = null;
    /**
     * @var StoreGroupRepositoryInterface|null
     */
    private ?StoreGroupRepositoryInterface $storeGroupRepository = null;
    /**
     * @var StoreGroupResource|null
     */
    private ?StoreGroupResource $storeGroupResource = null;
    /**
     * @var StoreGroupFactory|null
     */
    private ?StoreGroupFactory $storeGroupFactory = null;
    /**
     * @var StoreRepositoryInterface|null
     */
    private ?StoreRepositoryInterface $storeRepository = null;
    /**
     * @var StoreResource|null
     */
    private ?StoreResource $storeResource = null;
    /**
     * @var StoreFactory|null
     */
    private ?StoreFactory $storeFactory = null;
    /**
     * @var SourceRepositoryInterface|null
     */
    private ?SourceRepositoryInterface $sourceRepository = null;
    /**
     * @var SourceResource|null
     */
    private ?SourceResource $sourceResource = null;
    /**
     * @var SourceInterfaceFactory|null
     */
    private ?SourceInterfaceFactory $sourceFactory = null;
    /**
     * @var SourceItemRepositoryInterface|null
     */
    private ?SourceItemRepositoryInterface $sourceItemRepository = null;
    /**
     * @var SourceItemResource|null
     */
    private ?SourceItemResource $sourceItemResource = null;
    /**
     * @var SourceItemInterfaceFactory|null
     */
    private ?SourceItemInterfaceFactory $sourceItemFactory = null;
    /**
     * @var SourceItemsProcessorInterface|null
     */
    private ?SourceItemsProcessorInterface $sourceItemsProcessor = null;
    /**
     * @var StockSourceLinkResource|null
     */
    private ?StockSourceLinkResource $stockSourceLinkResource = null;
    /**
     * @var StockSourceLinkInterfaceFactory|null
     */
    private ?StockSourceLinkInterfaceFactory $stockSourceLinkFactory = null;
    /**
     * @var ProductRepositoryInterface|null
     */
    private ?ProductRepositoryInterface $productRepository = null;
    /**
     * @var ProductInterfaceFactory|null
     */
    private ?ProductInterfaceFactory $productFactory = null;
    /**
     * @var ProductAction|null
     */
    private ?ProductAction $productAction = null;
    /**
     * @var BulkSourceAssignInterface|null
     */
    private ?BulkSourceAssignInterface $bulkSourceAssign = null;
    /**
     * @var BulkSourceUnassignInterface|null
     */
    private ?BulkSourceUnassignInterface $bulkSourceUnassign = null;
    /**
     * @var StoresProviderInterface|null
     */
    private ?StoresProviderInterface $storesProvider = null;
    /**
     * @var ProductStockStatusProviderInterface|null
     */
    private ?ProductStockStatusProviderInterface $productStockStatusProvider = null;
    /**
     * @var IndexingEntityRepositoryInterface|null
     */
    private ?IndexingEntityRepositoryInterface $indexingEntityRepository = null;
    /**
     * @var int[]
     */
    private array $stockIds = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = Bootstrap::getObjectManager();
        $this->attributeFixturePool = $this->objectManager->get(AttributeFixturePool::class);
        $this->productFixturePool = $this->objectManager->get(ProductFixturePool::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);

        $this->appendReservations = $this->objectManager->create(AppendReservationsInterface::class);
        $this->configWriter = $this->objectManager->get(ConfigWriter::class);
        $this->reservationFactory = $this->objectManager->get(ReservationInterfaceFactory::class);
        $this->stockFactory = $this->objectManager->get(StockInterfaceFactory::class);
        $this->stockRepository = $this->objectManager->get(StockRepositoryInterface::class);
        $this->salesChannelFactory = $this->objectManager->get(SalesChannelInterfaceFactory::class);
        $this->replaceSalesChannelsForStock = $this->objectManager->get(ReplaceSalesChannelsForStockInterface::class);
        $this->deleteSalesChannelToStockLink = $this->objectManager->get(DeleteSalesChannelToStockLinkInterface::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteStockFixtures();
        $this->productFixturePool->rollback();
        $this->attributeFixturePool->rollback();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    /**
     * @return void
     */
    private function setUpAdditional(): void
    {
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->registry = $this->objectManager->get(Registry::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);

        $this->websiteRepository = $this->objectManager->get(WebsiteRepositoryInterface::class);
        $this->websiteResource = $this->objectManager->get(WebsiteResource::class);
        $this->websiteFactory = $this->objectManager->get(WebsiteFactory::class);

        $this->storeGroupRepository = $this->objectManager->get(StoreGroupRepositoryInterface::class);
        $this->storeGroupResource = $this->objectManager->get(StoreGroupResource::class);
        $this->storeGroupFactory = $this->objectManager->get(StoreGroupFactory::class);

        $this->storeRepository = $this->objectManager->get(StoreRepositoryInterface::class);
        $this->storeResource = $this->objectManager->get(StoreResource::class);
        $this->storeFactory = $this->objectManager->get(StoreFactory::class);

        $this->sourceRepository = $this->objectManager->get(SourceRepositoryInterface::class);
        $this->sourceResource = $this->objectManager->get(SourceResource::class);
        $this->sourceFactory = $this->objectManager->get(SourceInterfaceFactory::class);

        $this->sourceItemRepository = $this->objectManager->get(SourceItemRepositoryInterface::class);
        $this->sourceItemResource = $this->objectManager->get(SourceItemResource::class);
        $this->sourceItemFactory = $this->objectManager->get(SourceItemInterfaceFactory::class);
        $this->sourceItemsProcessor = $this->objectManager->get(SourceItemsProcessorInterface::class);

        $this->stockSourceLinkResource = $this->objectManager->get(StockSourceLinkResource::class);
        $this->stockSourceLinkFactory = $this->objectManager->get(StockSourceLinkInterfaceFactory::class);

        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->productFactory = $this->objectManager->get(ProductInterfaceFactory::class);
        $this->productAction = $this->objectManager->get(ProductAction::class);

        $this->bulkSourceAssign = $this->objectManager->get(BulkSourceAssignInterface::class);
        $this->bulkSourceUnassign = $this->objectManager->get(BulkSourceUnassignInterface::class);

        $this->storesProvider = $this->objectManager->get(StoresProviderInterface::class);
        $this->productStockStatusProvider = $this->objectManager->get(ProductStockStatusProviderInterface::class);

        $this->indexingEntityRepository = $this->objectManager->get(IndexingEntityRepositoryInterface::class);
    }

    /**
     * @return array<string, object>
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws ValidationException
     */
    private function initPluginIsAttached(): array
    {
        $this->setUpAdditional();
        $this->registry->register('isSecureArea', true);

        /** @var Website $website */
        $website = $this->websiteFactory->create();
        $website->setCode('klevu_test_msirequiresupdate');
        $website->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        try {
            $this->websiteResource->save($website);
        } catch (AlreadyExistsException) {
            $website = $this->websiteRepository->get('klevu_test_msirequiresupdate');
        }

        $storeGroup = $this->storeGroupFactory->create();
        $storeGroup->setWebsite($website);
        $storeGroup->setCode('klevu_test_msirequiresupdate');
        $storeGroup->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        $storeGroup->setRootCategoryId(2);
        try {
            $this->storeGroupResource->save($storeGroup);
        } catch (AlreadyExistsException) {
            $storeGroups = $this->storeGroupRepository->getList();
            foreach ($storeGroups as $existingStoreGroup) {
                if ($existingStoreGroup->getCode() === 'klevu_test_msirequiresupdate') {
                    $storeGroup = $existingStoreGroup;
                    break;
                }
            }
        }

        /** @var Store $store */
        $store = $this->storeFactory->create();
        $store->setWebsite($website);
        $store->setGroup($storeGroup);
        $store->setCode('klevu_test_msirequiresupdate');
        $store->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        try {
            $this->storeResource->save($store);
        } catch (AlreadyExistsException) {
            $store = $this->storeRepository->get('klevu_test_msirequiresupdate');
        }

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$store->getId(),
        );

        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/rest_auth_key',
            value: 'ABCDE1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: (int)$store->getId(),
        );

        $this->scopeConfig->clean();
        $configuredJsApiKey = $this->scopeConfig->getValue(
            'klevu_configuration/auth_keys/js_api_key',
            ScopeInterface::SCOPE_STORES,
            (int)$store->getId(),
        );
        $this->assertSame(
            expected: 'klevu-1234567890',
            actual: $configuredJsApiKey,
        );
        $configuredRestAuthKey = $this->scopeConfig->getValue(
            'klevu_configuration/auth_keys/rest_auth_key',
            ScopeInterface::SCOPE_STORES,
            (int)$store->getId(),
        );
        $this->assertSame(
            expected: 'ABCDE1234567890',
            actual: $configuredRestAuthKey,
        );

        $source = $this->sourceFactory->create();
        $source->setSourceCode('klevu_test_msirequiresupdate');
        $source->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        $source->setEnabled(true);
        $source->setCountryId('GB');
        $source->setPostcode('AB123CD');
        try {
            $this->sourceRepository->save($source);
        } catch (AlreadyExistsException) {
            $source = $this->sourceRepository->get('klevu_test_msirequiresupdate');
        }

        $stock = $this->stockFactory->create();
        $stock->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        try {
            $this->stockIds[] = $this->stockRepository->save($stock);
        } catch (CouldNotSaveException) {
            $this->searchCriteriaBuilder->addFilter(
                field: Stock::NAME,
                value: 'Klevu Test: MSI Reservations RequiresUpdate Plugin',
                conditionType: 'eq',
            );
            $stockResult = $this->stockRepository->getList(
                searchCriteria: $this->searchCriteriaBuilder->create(),
            );

            foreach ($stockResult->getItems() as $stockItem) {
                $this->stockIds[] = (int)$stockItem->getId();
            }
        }
        /** @var StockSourceLinkInterface $stockSourceLink */
        $stockSourceLink = $this->stockSourceLinkFactory->create();
        $stockSourceLink->setSourceCode('klevu_test_msirequiresupdate');
        $stockSourceLink->setStockId($this->stockIds[0]);
        $stockSourceLink->setPriority(0);
        try {
            $this->stockSourceLinkResource->save($stockSourceLink);
        } catch (AlreadyExistsException) {
            // This is fine
        }

        $salesChannel = $this->salesChannelFactory->create();
        $salesChannel->setType('website');
        $salesChannel->setCode('klevu_test_msirequiresupdate');
        $this->replaceSalesChannelsForStock->execute(
            salesChannels: [
                $salesChannel,
            ],
            stockId: $this->stockIds[0],
        );

        /** @var ProductInterface $product */
        $product = $this->productFactory->create();
        $product->setSku('klevu_test_msirequiresupdate');
        $product->setName('Klevu Test: MSI Reservations RequiresUpdate Plugin');
        $product->setPrice(100.00);
        $product->setStatus(ProductStatus::STATUS_ENABLED);
        $product->setVisibility(ProductVisibility::VISIBILITY_BOTH);
        $product->setWeight(1.0);
        $product->setTypeId(ProductType::TYPE_SIMPLE);
        $product->setAttributeSetId(4);
        $product = $this->productRepository->save($product);

        $this->productAction->updateWebsites(
            productIds: [
                (int)$product->getId(),
            ],
            websiteIds: $product->getWebsiteIds(),
            type: 'remove',
        );
        $this->productAction->updateWebsites(
            productIds: [
                (int)$product->getId(),
            ],
            websiteIds: [
                (int)$website->getId(),
            ],
            type: 'add',
        );

        $this->bulkSourceAssign->execute(
            skus: [
                'klevu_test_msirequiresupdate',
            ],
            sourceCodes: [
                'klevu_test_msirequiresupdate',
            ],
        );
        $this->bulkSourceUnassign->execute(
            skus: [
                'klevu_test_msirequiresupdate',
            ],
            sourceCodes: [
                'default',
            ],
        );

        $this->searchCriteriaBuilder->addFilter(
            field: SourceItemInterface::SKU,
            value: 'klevu_test_msirequiresupdate',
            conditionType: 'eq',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SourceItemInterface::SOURCE_CODE,
            value: 'klevu_test_msirequiresupdate',
            conditionType: 'eq',
        );
        $sourceItemsResult = $this->sourceItemRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        switch ($sourceItemsResult->getTotalCount()) {
            case 1:
                $sourceItem = current(
                    array: $sourceItemsResult->getItems(),
                );
                break;
            case 0:
                /** @var SourceItemInterface $sourceItem */
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSku('klevu_test_msirequiresupdate');
                $sourceItem->setSourceCode('klevu_test_msirequiresupdate');
                break;
            default:
                $this->fail('Unexpected total count of items');
                break;
        }
        $sourceItem->setQuantity(1);
        $sourceItem->setStatus(1);
        try {
            $this->sourceItemResource->save($sourceItem);
        } catch (AlreadyExistsException) {
        }

        $this->sourceItemsProcessor->execute(
            sku: $product->getSku(),
            sourceItemsData: [
                [
                    SourceItemInterface::SOURCE_CODE => $sourceItem->getSourceCode(),
                    SourceItemInterface::QUANTITY => $sourceItem->getQuantity(),
                    SourceItemInterface::STATUS => $sourceItem->getStatus(),
                    SourceInterface::NAME => $source->getName(),
                    'source_status' => 'true',
                    'notify_stock_qty' => '1',
                    'notify_stock_qty_use_default' => '1',
                    'initialize' => 'true',
                    'record_id' => $sourceItem->getSourceCode(),
                ],
            ],
        );

        $this->cleanIndexingEntities('klevu-1234567890');
        $indexingEntity = $this->createIndexingEntity(
            data: [
                IndexingEntity::TARGET_ID => (int)$product->getId(),
                IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                IndexingEntity::API_KEY => 'klevu-1234567890',
                IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                IndexingEntity::IS_INDEXABLE => true,
                IndexingEntity::REQUIRES_UPDATE => false,
                IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
            ],
        );

        return [
            'website' => $website,
            'storeGroup' => $storeGroup,
            'store' => $store,
            'source' => $source,
            'sourceItem' => $sourceItem,
            'stock' => $stock,
            'product' => $product,
            'indexingEntity' => $indexingEntity,
        ];
    }

    /**
     * @param Website $website
     * @param StoreGroup $storeGroup
     * @param Store $store
     * @param Source $source
     * @param Product $product
     *
     * @return void
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function cleanUpPluginIsAttached(
        Website $website,
        StoreGroup $storeGroup,
        Store $store,
        Source $source,
        Product $product,
    ): void {
        $this->storeResource->delete($store);
        $this->storeGroupResource->delete($storeGroup);
        $this->websiteResource->delete($website);

        $this->deleteStockFixtures();
        $this->sourceResource->delete($source);

        $this->productRepository->delete($product);

        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            table: $this->resourceConnection->getTableName('inventory_reservation'),
            where: $connection->quoteInto(
                text: sprintf('%s = ?', ReservationInterface::SKU),
                value: $product->getSku(),
            ),
        );

        $connection->delete(
            table: $this->resourceConnection->getTableName('core_config_data'),
            where: 'scope = "default" and path = "klevu/indexing/append_reservations_action"',
        );

        $this->cleanIndexingEntities('klevu-1234567890');
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     */
    private function deleteStockFixtures(): void
    {
        $this->deleteSalesChannelToStockLink->execute(
            type: 'website',
            code: 'klevu_test_msirequiresupdate',
        );
        foreach ($this->stockIds as $stockId) {
            try {
                $this->stockRepository->deleteById($stockId);
            } catch (NoSuchEntityException) {
            }
        }
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

    /**
     * @return MockObject&MarkReservationsForUpdateInterface
     */
    private function getMockMarkReservationsForUpdateAction(): MockObject
    {
        return $this->getMockBuilder(MarkReservationsForUpdateInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return MockObject&SetIndexingEntitiesToRequireUpdateActionInterface
     */
    private function getMockSetIndexingEntitiesToRequiresUpdateAction(): MockObject
    {
        return $this->getMockBuilder(SetIndexingEntitiesToRequireUpdateActionInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
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
     * @return MockObject&ProductStockStatusProviderInterface
     */
    private function getMockProductStockStatusProvider(): MockObject
    {
        return $this->getMockBuilder(ProductStockStatusProviderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
