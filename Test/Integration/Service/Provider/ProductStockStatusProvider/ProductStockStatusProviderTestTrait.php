<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Provider\ProductStockStatusProvider;

use Magento\Bundle\Api\Data\LinkInterfaceFactory as BundleLinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory as BundleOptionInterfaceFactory;
use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Validation\ValidationException;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Indexer\Model\IndexerFactory;
use Magento\Inventory\Model\ResourceModel\Source as SourceResource;
use Magento\Inventory\Model\ResourceModel\SourceItem as SourceItemResource;
use Magento\Inventory\Model\ResourceModel\StockSourceLink as StockSourceLinkResource;
use Magento\Inventory\Model\Stock;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
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
use Magento\Store\Model\GroupFactory as StoreGroupFactory;
use Magento\Store\Model\ResourceModel\Group as StoreGroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;

trait ProductStockStatusProviderTestTrait
{
    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resourceConnection = null; // @phpstan-ignore-line
    /**
     * @var ConfigWriter|null
     */
    private ?ConfigWriter $configWriter = null; // @phpstan-ignore-line
    /**
     * @var Registry|null
     */
    private ?Registry $registry = null; // @phpstan-ignore-line
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
     * @var StockInterfaceFactory|null
     */
    private ?StockInterfaceFactory $stockFactory = null; // @phpstan-ignore-line
    /**
     * @var StockRepositoryInterface|null
     */
    private ?StockRepositoryInterface $stockRepository = null; // @phpstan-ignore-line
    /**
     * @var StockSourceLinkResource|null
     */
    private ?StockSourceLinkResource $stockSourceLinkResource = null;
    /**
     * @var StockSourceLinkInterfaceFactory|null
     */
    private ?StockSourceLinkInterfaceFactory $stockSourceLinkFactory = null;
    /**
     * @var BulkSourceAssignInterface|null
     */
    private ?BulkSourceAssignInterface $bulkSourceAssign = null;
    /**
     * @var BulkSourceUnassignInterface|null
     */
    private ?BulkSourceUnassignInterface $bulkSourceUnassign = null;
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
     * @var ProductRepositoryInterface|null
     */
    private ?ProductRepositoryInterface $productRepository = null; // @phpstan-ignore-line
    /**
     * @var ProductInterfaceFactory|null
     */
    private ?ProductInterfaceFactory $productFactory = null; // @phpstan-ignore-line
    /**
     * @var ProductAction|null
     */
    private ?ProductAction $productAction = null; // @phpstan-ignore-line
    /**
     * @var ConfigurableOptionsFactory
     */
    private ConfigurableOptionsFactory $configurableOptionsFactory; // @phpstan-ignore-line
    /**
     * @var BundleOptionInterfaceFactory
     */
    private BundleOptionInterfaceFactory $bundleOptionFactory; // @phpstan-ignore-line
    /**
     * @var BundleLinkInterfaceFactory
     */
    private BundleLinkInterfaceFactory $bundleLinkFactory; // @phpstan-ignore-line
    /**
     * @var ProductLinkInterfaceFactory|null
     */
    private ?ProductLinkInterfaceFactory $productLinkFactory = null; // @phpstan-ignore-line
    /**
     * @var StockItemInterfaceFactory
     */
    private StockItemInterfaceFactory $stockItemFactory; // @phpstan-ignore-line
    /**
     * @var ReservationInterfaceFactory|null
     */
    private ?ReservationInterfaceFactory $reservationFactory = null; // @phpstan-ignore-line
    /**
     * @var AppendReservationsInterface|null
     */
    private ?AppendReservationsInterface $appendReservations = null;
    /**
     * @var IndexerFactory|null
     */
    private ?IndexerFactory $indexerFactory = null; // @phpstan-ignore-line
    /**
     * @var string
     */
    private string $fixtureIdentifier = '';
    /**
     * @var string
     */
    private string $fixtureName = '';
    /**
     * @var mixed[]
     */
    private array $fixtureIdentifiers = [
        'stockIds' => [],
        'sourceCodes' => [],
        'productSkus' => [],
        'storeIds' => [],
        'storeGroupIds' => [],
        'websiteIds' => [],
    ];

    /**
     * @return void
     */
    private function setUpProperties(): void
    {
        if (!(($this->objectManager ?? null) instanceof \Magento\Framework\ObjectManagerInterface)) {
            throw new \LogicException('ObjectManager is not defined');
        }

        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->configWriter = $this->objectManager->get(ConfigWriter::class);
        $this->registry = $this->objectManager->get(Registry::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);

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
        $this->stockFactory = $this->objectManager->get(StockInterfaceFactory::class);
        $this->stockRepository = $this->objectManager->get(StockRepositoryInterface::class);
        $this->stockSourceLinkResource = $this->objectManager->get(StockSourceLinkResource::class);
        $this->stockSourceLinkFactory = $this->objectManager->get(StockSourceLinkInterfaceFactory::class);
        $this->bulkSourceAssign = $this->objectManager->get(BulkSourceAssignInterface::class);
        $this->bulkSourceUnassign = $this->objectManager->get(BulkSourceUnassignInterface::class);

        $this->salesChannelFactory = $this->objectManager->get(SalesChannelInterfaceFactory::class);
        $this->replaceSalesChannelsForStock = $this->objectManager->get(ReplaceSalesChannelsForStockInterface::class);
        $this->deleteSalesChannelToStockLink = $this->objectManager->get(DeleteSalesChannelToStockLinkInterface::class);

        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->productFactory = $this->objectManager->get(ProductInterfaceFactory::class);
        $this->productAction = $this->objectManager->get(ProductAction::class);
        $this->configurableOptionsFactory = $this->objectManager->get(ConfigurableOptionsFactory::class);
        $this->bundleOptionFactory = $this->objectManager->get(BundleOptionInterfaceFactory::class);
        $this->bundleLinkFactory = $this->objectManager->get(BundleLinkInterfaceFactory::class);
        $this->productLinkFactory = $this->objectManager->get(ProductLinkInterfaceFactory::class);
        $this->stockItemFactory = $this->objectManager->get(StockItemInterfaceFactory::class);

        $this->reservationFactory = $this->objectManager->get(ReservationInterfaceFactory::class);
        $this->appendReservations = $this->objectManager->get(AppendReservationsInterface::class);

        $this->indexerFactory = $this->objectManager->get(IndexerFactory::class);
    }

    /**
     * @param string $appendIdentifier
     *
     * @return array<string, object>
     * @throws NoSuchEntityException
     */
    private function createWebsiteAndStoreFixtures(
        string $appendIdentifier,
    ): array {
        $connection = $this->websiteResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        $website = $this->websiteFactory->create();
        $website->setCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $website->setName($this->fixtureName . ' : ' . $appendIdentifier);
        try {
            $this->websiteResource->save($website);
        } catch (AlreadyExistsException) {
            $website = $this->websiteRepository->get($this->fixtureIdentifier . '_' . $appendIdentifier);
        }
        $this->fixtureIdentifiers['websiteIds'][] = $website->getId();

        $storeGroup = $this->storeGroupFactory->create();
        $storeGroup->setWebsite($website);
        $storeGroup->setCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $storeGroup->setName($this->fixtureName . ' : ' . $appendIdentifier);
        $storeGroup->setRootCategoryId(2);
        try {
            $this->storeGroupResource->save($storeGroup);
        } catch (AlreadyExistsException) {
            $storeGroups = $this->storeGroupRepository->getList();
            foreach ($storeGroups as $existingStoreGroup) {
                if ($existingStoreGroup->getCode() === $this->fixtureIdentifier . '_' . $appendIdentifier) {
                    $storeGroup = $existingStoreGroup;
                    break;
                }
            }
        }
        $this->fixtureIdentifiers['storeGroupIds'][] = $storeGroup->getId();

        $store = $this->storeFactory->create();
        $store->setWebsite($website);
        $store->setGroup($storeGroup);
        $store->setCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $store->setName($this->fixtureName . ' : ' . $appendIdentifier);
        $store->setIsActive(1);
        try {
            $this->storeResource->save($store);

            $storeGroup->setDefaultStoreId(
                defaultStoreId: (int)$store->getId(),
            );
            $this->storeGroupResource->save($storeGroup);
        } catch (AlreadyExistsException) {
            $store = $this->storeRepository->get($this->fixtureIdentifier . '_' . $appendIdentifier);
        }
        $this->fixtureIdentifiers['storeIds'][] = $store->getId();

        $this->configWriter->save(
            path: 'general/locale/timezone',
            value: 'America/Los_Angeles',
            scope: 'stores',
            scopeId: (int)$store->getId(),
        );

        return [
            'website' . $appendIdentifier => $website,
            'storeGroup' . $appendIdentifier => $storeGroup,
            'store' . $appendIdentifier => $store,
        ];
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function deleteWebsiteAndStoreFixtures(): void
    {
        $connection = $this->websiteResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        foreach ($this->fixtureIdentifiers['storeIds'] as $storeId) {
            try {
                $this->storeResource->delete(
                    object: $this->storeRepository->getById($storeId),
                );
            } catch (NoSuchEntityException) {
                // This is fine
            }
        }

        foreach ($this->fixtureIdentifiers['storeGroupIds'] as $storeGroupId) {
            try {
                $this->storeGroupResource->delete(
                    object: $this->storeGroupRepository->get($storeGroupId),
                );
            } catch (NoSuchEntityException) {
                // This is fine
            }
        }

        foreach ($this->fixtureIdentifiers['websiteIds'] as $websiteId) {
            try {
                $this->websiteResource->delete(
                    object: $this->websiteRepository->getById($websiteId),
                );
            } catch (NoSuchEntityException) {
                // This is fine
            }
        }
    }

    /**
     * @param string $appendIdentifier
     *
     * @return array<string, object>
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    private function createStockAndSourceFixtures(
        string $appendIdentifier,
    ): array {
        $connection = $this->sourceResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        $source = $this->sourceFactory->create();
        $source->setSourceCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $source->setName($this->fixtureName . ' : ' . $appendIdentifier);
        $source->setEnabled(true);
        $source->setCountryId('GB');
        $source->setPostCode('AB' . rand(100, 999) . 'CD');
        try {
            $this->sourceRepository->save($source);
        } catch (AlreadyExistsException) {
            $source = $this->sourceRepository->get($this->fixtureIdentifier . '_' . $appendIdentifier);
        }
        $this->fixtureIdentifiers['sourceCodes'][] = $this->fixtureIdentifier . '_' . $appendIdentifier;

        $this->fixtureIdentifiers['stockIds'][$appendIdentifier] ??= [];

        $stock = $this->stockFactory->create();
        $stock->setName($this->fixtureName . ' : ' . $appendIdentifier);
        try {
            $this->fixtureIdentifiers['stockIds'][$appendIdentifier][] = $this->stockRepository->save($stock);
        } catch (CouldNotSaveException) {
            $this->searchCriteriaBuilder->addFilter(
                field: Stock::NAME,
                value: $this->fixtureName . ' : ' . $appendIdentifier,
                conditionType: 'eq',
            );
            $stockResult = $this->stockRepository->getList(
                searchCriteria: $this->searchCriteriaBuilder->create(),
            );

            foreach ($stockResult->getItems() as $stockItem) {
                $this->fixtureIdentifiers['stockIds'][$appendIdentifier][] = (int)$stockItem->getId();
            }
        }

        $stockSourceLink = $this->stockSourceLinkFactory->create();
        $stockSourceLink->setSourceCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $stockSourceLink->setStockId($this->fixtureIdentifiers['stockIds'][$appendIdentifier][0]);
        $stockSourceLink->setPriority(0);
        try {
            $this->stockSourceLinkResource->save($stockSourceLink);
        } catch (AlreadyExistsException) {
            // This is fine
        }

        $salesChannel = $this->salesChannelFactory->create();
        $salesChannel->setType('website');
        $salesChannel->setCode($this->fixtureIdentifier . '_' . $appendIdentifier);
        $this->replaceSalesChannelsForStock->execute(
            salesChannels: [
                $salesChannel,
            ],
            stockId: $this->fixtureIdentifiers['stockIds'][$appendIdentifier][0],
        );

        return [
            'source' . $appendIdentifier => $source,
            'stock' . $appendIdentifier => $stock,
            'salesChannel' . $appendIdentifier => $salesChannel,
        ];
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     */
    private function deleteStockAndSourceFixtures(): void
    {
        $connection = $this->sourceResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        foreach ($this->fixtureIdentifiers['stockIds'] as $appendIdentifier => $stockIds) {
            $this->deleteSalesChannelToStockLink->execute(
                type: 'website',
                code: $this->fixtureIdentifier . '_' . $appendIdentifier,
            );

            foreach ($stockIds as $stockId) {
                try {
                    $this->stockRepository->deleteById($stockId);
                } catch (NoSuchEntityException) {
                    // This is fine
                }
            }
        }

        foreach ($this->fixtureIdentifiers['sourceCodes'] as $sourceCode) {
            try {
                $source = $this->sourceRepository->get($sourceCode);
                $this->sourceResource->delete($source);
            } catch (NoSuchEntityException) {
                // this is fine
            }
        }
    }

    private function createSimpleProductFixture(
        string $appendIdentifier,
        array $websiteIds,
        array $sources,
        int $quantity,
        bool $stockStatus,
        ?array $reservations,
        int $status = ProductStatus::STATUS_ENABLED,
        int $visibility = ProductVisibility::VISIBILITY_BOTH,
        array $data = [],
    ): ProductInterface {
        return $this->createProductFixture(
            productTypeId: ProductType::TYPE_SIMPLE,
            appendIdentifier: $appendIdentifier,
            websiteIds: $websiteIds,
            sources: $sources,
            quantity: $quantity,
            stockStatus: $stockStatus,
            reservations: $reservations,
            status: $status,
            visibility: $visibility,
            data: $data,
        );
    }

    /**
     * @param string $appendIdentifier
     * @param int[] $websiteIds
     * @param AttributeInterface[] $configurableAttributes
     * @param ProductInterface[] $configurableVariants
     * @param int $status
     * @param int $visibility
     * @param array $data
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws ValidationException
     */
    private function createConfigurableProductFixture(
        string $appendIdentifier,
        array $websiteIds,
        array $configurableAttributes,
        array $configurableVariants,
        ?bool $stockStatus,
        int $status = ProductStatus::STATUS_ENABLED,
        int $visibility = ProductVisibility::VISIBILITY_BOTH,
        array $data = [],
    ): ProductInterface {
        $configurableProduct = $this->createProductFixture(
            productTypeId: Configurable::TYPE_CODE,
            appendIdentifier: $appendIdentifier,
            websiteIds: $websiteIds,
            sources: [],
            quantity: null,
            stockStatus: null,
            reservations: null,
            status: $status,
            visibility: $visibility,
            data: $data,
        );

        $extensionAttributes = $configurableProduct->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $configurableProduct->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => (int)$stockStatus,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock($stockStatus);

        $extensionAttributes->setStockItem($stockItem);
        $configurableProduct->setExtensionAttributes($extensionAttributes);

        if (!$configurableAttributes || !$configurableVariants) {
            return $this->productRepository->save($configurableProduct);
        }

        $attributeValues = [];
        foreach ($configurableAttributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $attributeValues[$attributeCode] = [];

            foreach ($configurableVariants as $variantProduct) {
                $attributeValues[$attributeCode][] = [
                    'label' => $attribute->getDataUsingMethod('store_label'),
                    'attribute_id' => $attribute->getId(),
                    'value_index' => $variantProduct->getData($attributeCode),
                ];
            }
        }

        $configurableAttributesData = [];
        $position = 0;
        foreach ($attributeValues as $attributeCode => $values) {
            $attribute = $configurableAttributes[$attributeCode];

            $configurableAttributesData[] = [
                'attribute_id' => $attribute->getId(),
                'code' => $attribute->getAttributeCode(),
                'label' => $attribute->getDataUsingMethod('store_label'),
                'position' => $position++,
                'values' => $values,
            ];
        }

        $configurableOptions = $this->configurableOptionsFactory->create($configurableAttributesData);
        $extensionAttributes->setConfigurableProductOptions($configurableOptions);
        $extensionAttributes->setConfigurableProductLinks(
            configurableProductLinks: array_map(
                callback: static fn (ProductInterface $variantProduct): int => (int)$variantProduct->getId(),
                array: $configurableVariants,
            ),
        );

        $configurableProduct->setExtensionAttributes($extensionAttributes);

        return $this->productRepository->save($configurableProduct);
    }

    /**
     * @param string $appendIdentifier
     * @param int[] $websiteIds
     * @param ProductInterface[] $groupedVariants
     * @param bool|null $stockStatus
     * @param int $status
     * @param int $visibility
     * @param array $data
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws ValidationException
     */
    private function createGroupedProductFixture(
        string $appendIdentifier,
        array $websiteIds,
        array $groupedVariants,
        ?bool $stockStatus,
        int $status = ProductStatus::STATUS_ENABLED,
        int $visibility = ProductVisibility::VISIBILITY_BOTH,
        array $data = [],
    ): ProductInterface {
        $groupedProduct = $this->createProductFixture(
            productTypeId: Grouped::TYPE_CODE,
            appendIdentifier: $appendIdentifier,
            websiteIds: $websiteIds,
            sources: [],
            quantity: null,
            stockStatus: null,
            reservations: null,
            status: $status,
            visibility: $visibility,
            data: $data,
        );

        $extensionAttributes = $groupedProduct->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $groupedProduct->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => (int)$stockStatus,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock($stockStatus);

        $extensionAttributes->setStockItem($stockItem);
        $groupedProduct->setExtensionAttributes($extensionAttributes);

        if (!$groupedVariants) {
            return $this->productRepository->save($groupedProduct);
        }

        $productLinks = [];
        $position = 1;
        foreach ($groupedVariants as $variantProduct) {
            $productLink = $this->productLinkFactory->create();
            $productLink->setSku($groupedProduct->getSku());
            $productLink->setLinkType('associated');
            $productLink->setLinkedProductSku($variantProduct->getSku());
            $productLink->setPosition($position++);

            $productLinkExtensionAttributes = $productLink->getExtensionAttributes();
            $productLinkExtensionAttributes->setQty(1);

            $productLinks[] = $productLink;
        }
        $groupedProduct->setProductLinks($productLinks);

        $this->productRepository->save($groupedProduct);

        $this->reindex([
            'cataloginventory_stock',
            'inventory',
        ]);

        return $this->productRepository->get(
            sku: $groupedProduct->getSku(),
            forceReload: true,
        );
    }

    private function createBundleProductFixture(
        string $appendIdentifier,
        array $websiteIds,
        array $bundleOptionVariants,
        ?bool $stockStatus,
        int $status = ProductStatus::STATUS_ENABLED,
        int $visibility = ProductVisibility::VISIBILITY_BOTH,
        array $data = [],
    ): ProductInterface {
        /** @var Product $bundleProduct */
        $bundleProduct = $this->createProductFixture(
            productTypeId: Type::TYPE_CODE,
            appendIdentifier: $appendIdentifier,
            websiteIds: $websiteIds,
            sources: [],
            quantity: null,
            stockStatus: null,
            reservations: null,
            status: $status,
            visibility: $visibility,
            data: array_merge(
                [
                    'price_type' => 0,
                    'price_view' => 0,
                    'shipment_type' => 0,
                ],
                $data,
            ),
        );

        $extensionAttributes = $bundleProduct->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $bundleProduct->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => (int)$stockStatus,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock($stockStatus);

        $extensionAttributes->setStockItem($stockItem);
        $bundleProduct->setExtensionAttributes($extensionAttributes);

        if (!$bundleOptionVariants) {
            return $this->productRepository->save($bundleProduct);
        }

        $bundleOptionsData = [];
        $bundleSelectionsData = [];
        if (isset($bundleOptionVariants['required'])) {
            $bundleOptionsData[] = [
                'title' => 'Required Option',
                'default_title' => 'Required Option',
                'type' => 'select',
                'required' => 1,
                'delete' => '',
            ];

            $bundleSelectionsData[] = array_map(
                callback: static fn (ProductInterface $variantProduct): array => [
                    'product_id' => (int)$variantProduct->getId(),
                    'selection_qty' => 1,
                    'selection_can_change_qty' => 1,
                    'delete' => '',
                ],
                array: $bundleOptionVariants['required'],
            );
        }
        if (isset($bundleOptionVariants['optional'])) {
            $bundleOptionsData[] = [
                'title' => 'Optional Option',
                'default_title' => 'Optional Option',
                'type' => 'select',
                'required' => 0,
                'delete' => '',
            ];
            $bundleSelectionsData[] = array_map(
                callback: static fn (ProductInterface $variantProduct): array => [
                    'product_id' => (int)$variantProduct->getId(),
                    'selection_qty' => 1,
                    'selection_can_change_qty' => 1,
                    'delete' => '',
                ],
                array: $bundleOptionVariants['optional'],
            );
        }

        $bundleProduct->setDataUsingMethod(
            key: 'bundle_options_data',
            args: $bundleOptionsData,
        );
        $bundleOptionsData = $bundleProduct->getDataUsingMethod(
            key: 'bundle_options_data',
        );

        $bundleProduct->setDataUsingMethod(
            key: 'bundle_selections_data',
            args: $bundleSelectionsData,
        );
        $bundleSelectionsData = $bundleProduct->getDataUsingMethod(
            key: 'bundle_selections_data',
        );

        $bundleOptions = [];
        foreach ($bundleOptionsData as $key => $optionData) {
            $bundleOption = $this->bundleOptionFactory->create();
            $bundleOption->setData($optionData);
            $bundleOption->setSku(
                sku: $bundleProduct->getSku(),
            );

            $bundleLinks = [];
            foreach ($bundleSelectionsData[$key] ?? [] as $bundleSelectionData) {
                $bundleLink = $this->bundleLinkFactory->create();
                $bundleLink->setData($bundleSelectionData);

                $bundleLinkProduct = $this->productRepository->getById(
                    productId: (int)$bundleSelectionData['product_id'],
                );
                $bundleLink->setSku(
                    sku: $bundleLinkProduct->getSku(),
                );
                $bundleLink->setQty(
                    qty: $bundleSelectionData['selection_qty'] ?? 1,
                );
                $bundleLink->setCanChangeQuantity(
                    canChangeQuantity: (int)($bundleSelectionData['selection_can_change_qty'] ?? 0),
                );

                $bundleLinks[] = $bundleLink;
            }
            $bundleOption->setProductLinks($bundleLinks);
            $bundleOptions[] = $bundleOption;
        }

        /** @var ProductExtensionInterface $bundleProductExtensionAttributes */
        $bundleProductExtensionAttributes = $bundleProduct->getExtensionAttributes();
        $bundleProductExtensionAttributes->setBundleProductOptions($bundleOptions);
        $bundleProduct->setExtensionAttributes($bundleProductExtensionAttributes);

        $this->productRepository->save($bundleProduct);

        $this->reindex([
            'cataloginventory_stock',
            'inventory',
        ]);

        return $this->productRepository->get(
            sku: $bundleProduct->getSku(),
            forceReload: true,
        );
    }

    /**
     * @param string $productTypeId
     * @param string $appendIdentifier
     * @param int[] $websiteIds
     * @param SourceInterface[] $sources
     * @param int|null $quantity
     * @param bool|null $stockStatus
     * @param array<string, mixed> $reservations
     * @param int $status
     * @param int $visibility
     * @param array<string, mixed> $data
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws ValidationException
     */
    private function createProductFixture(
        string $productTypeId,
        string $appendIdentifier,
        array $websiteIds,
        array $sources,
        ?int $quantity,
        ?bool $stockStatus,
        ?array $reservations,
        int $status,
        int $visibility,
        array $data,
    ): ProductInterface {
        $connection = $this->sourceResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        $product = $this->productFactory->create();
        $product->setSku($this->fixtureIdentifier . '_' . $appendIdentifier);
        $product->setName($this->fixtureName . ' : ' . $appendIdentifier);
        $product->setPrice(100.00);
        $product->setStatus($status);
        $product->setVisibility($visibility);
        $product->setWeight(1.0);
        $product->setTypeId($productTypeId);
        $product->setAttributeSetId(4);
        if ($product instanceof DataObject && $data) {
            array_walk(
                array: $data,
                callback: static function (mixed $value, string $key) use ($product): void {
                    $product->setDataUsingMethod(
                        key: $key,
                        args: $value,
                    );
                }
            );
        }

        $product = $this->productRepository->save($product);
        $this->fixtureIdentifiers['productSkus'][] = $product->getSku();

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
            websiteIds: $websiteIds,
            type: 'add',
        );
        $product = $this->productRepository->get(
            sku: $product->getSku(),
            forceReload: true,
        );

        $this->bulkSourceUnassign->execute(
            skus: [
                $product->getSku(),
            ],
            sourceCodes: [
                'default',
            ],
        );
        $this->bulkSourceAssign->execute(
            skus: [
                $product->getSku(),
            ],
            sourceCodes: array_map(
                callback: static fn (SourceInterface $source): string => $source->getSourceCode(),
                array: $sources,
            ),
        );

        if ($sources && null !== $quantity && null !== $stockStatus) {
            $sourceItemsData = [];

            foreach ($sources as $source) {
                $this->searchCriteriaBuilder->addFilter(
                    field: SourceItemInterface::SKU,
                    value: $this->fixtureIdentifier . '_' . $appendIdentifier,
                    conditionType: 'eq',
                );
                $this->searchCriteriaBuilder->addFilter(
                    field: SourceItemInterface::SOURCE_CODE,
                    value: $source->getSourceCode(),
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
                        $sourceItem = $this->sourceItemFactory->create();
                        $sourceItem->setSku($this->fixtureIdentifier . '_' . $appendIdentifier);
                        $sourceItem->setSourceCode($source->getSourceCode());
                        break;
                    default:
                        $this->fail('Unexpected total count of items');
                        break;
                }
                $sourceItem->setQuantity($quantity);
                $sourceItem->setStatus((int)$stockStatus);
                try {
                    $this->sourceItemResource->save($sourceItem);
                } catch (AlreadyExistsException) {
                    // This is fine
                }

                $sourceItemsData[] = [
                    SourceItemInterface::SOURCE_CODE => $sourceItem->getSourceCode(),
                    SourceItemInterface::QUANTITY => $sourceItem->getQuantity(),
                    SourceItemInterface::STATUS => $sourceItem->getStatus(),
                    SourceInterface::NAME => $source->getName(),
                    'source_status' => 'true',
                    'notify_stock_qty' => '1',
                    'notify_stock_qty_use_default' => '1',
                    'initialize' => 'true',
                    'record_id' => $sourceItem->getSourceCode(),
                ];
            }

            $this->sourceItemsProcessor->execute(
                sku: $product->getSku(),
                sourceItemsData: $sourceItemsData,
            );
        }

        $reservationsQuantity = $reservations['quantity'] ?? 0;
        $reservationsStockId = $reservations['stockId'] ?? null;
        if ($reservationsQuantity && $reservationsStockId) {
            $this->appendReservations->execute(
                reservations: [
                    $this->reservationFactory->create([
                        'reservationId' => null,
                        'stockId' => $reservationsStockId,
                        'sku' => $product->getSku(),
                        'quantity' => 0 - $reservationsQuantity,
                    ]),
                ],
            );
        }

        return $product;
    }

    /**
     * @return void
     * @throws StateException
     */
    private function deleteProductFixtures(): void
    {
        $connection = $this->sourceResource->getConnection();
        if ($connection->getTransactionLevel() > 0) {
            $connection->commit();
        }

        foreach ($this->fixtureIdentifiers['productSkus'] as $sku) {
            try {
                $this->productRepository->deleteById($sku);
            } catch (NoSuchEntityException) {
                // This is fine
            }

            $connection = $this->resourceConnection->getConnection();
            $connection->delete(
                table: $this->resourceConnection->getTableName('inventory_reservation'),
                where: $connection->quoteInto(
                    text: sprintf('%s = ?', ReservationInterface::SKU),
                    value: $sku,
                ),
            );
        }
    }

    /**
     * @param string[] $indexers
     *
     * @return void
     * @throws \Throwable
     */
    private function reindex(
        array $indexers,
    ): void {
        foreach ($indexers as $indexerName) {
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerName);

            $indexer->reindexAll();
        }
    }
}
