<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Plugin\InventoryCatalog\Model;

use Klevu\Configuration\Service\Provider\StoresProviderInterface;
use Klevu\Indexing\Model\IndexingEntity;
use Klevu\Indexing\Test\Integration\Traits\IndexingEntitiesTrait;
use Klevu\IndexingApi\Api\Data\IndexingEntityInterface;
use Klevu\IndexingApi\Api\IndexingEntityRepositoryInterface;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProviderInterface;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Bundle\Api\Data\LinkInterfaceFactory as BundleLinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory as BundleOptionInterfaceFactory;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
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
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
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
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;
use Magento\InventoryCatalogApi\Api\BulkSourceUnassignInterface;
use Magento\InventoryCatalogApi\Model\SourceItemsProcessorInterface;
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
use TddWizard\Fixtures\Catalog\ProductFixturePool;

trait BulkSourceTrait
{
    use AttributeTrait;
    use IndexingEntitiesTrait;
    use ProductTrait;
    use StoreTrait;
    use WebsiteTrait;

    /**
     * @var string
     */
    private string $fixtureIdentifier = '';
    /**
     * @var string
     */
    private string $fixtureName = '';
    /**
     * @var string[]
     */
    private array $fixtureApiKeys = [];
    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
    /**
     * @var ConfigWriter|null
     */
    private ?ConfigWriter $configWriter = null; // @phpstan-ignore-line
    /**
     * @var StockInterfaceFactory|null
     */
    private ?StockInterfaceFactory $stockFactory = null; // @phpstan-ignore-line
    /**
     * @var StockItemInterfaceFactory|null
     */
    private ?StockItemInterfaceFactory $stockItemFactory = null;
    /**
     * @var StockRepositoryInterface|null
     */
    private ?StockRepositoryInterface $stockRepository = null; // @phpstan-ignore-line
    /**
     * @var StockRegistryInterface|null
     */
    private ?StockRegistryInterface $stockRegistry = null; // @phpstan-ignore-line
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
    private ?ResourceConnection $resourceConnection = null; // @phpstan-ignore-line
    /**
     * @var Registry|null
     */
    private ?Registry $registry = null; // @phpstan-ignore-line
    /**
     * @var ScopeConfigInterface|null
     */
    private ?ScopeConfigInterface $scopeConfig = null; // @phpstan-ignore-line
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null; // @phpstan-ignore-line
    /**
     * @var WebsiteRepositoryInterface|null
     */
    private ?WebsiteRepositoryInterface $websiteRepository = null; // @phpstan-ignore-line
    /**
     * @var WebsiteResource|null
     */
    private ?WebsiteResource $websiteResource = null; // @phpstan-ignore-line
    /**
     * @var WebsiteFactory|null
     */
    private ?WebsiteFactory $websiteFactory = null; // @phpstan-ignore-line
    /**
     * @var StoreGroupRepositoryInterface|null
     */
    private ?StoreGroupRepositoryInterface $storeGroupRepository = null; // @phpstan-ignore-line
    /**
     * @var StoreGroupResource|null
     */
    private ?StoreGroupResource $storeGroupResource = null; // @phpstan-ignore-line
    /**
     * @var StoreGroupFactory|null
     */
    private ?StoreGroupFactory $storeGroupFactory = null; // @phpstan-ignore-line
    /**
     * @var StoreRepositoryInterface|null
     */
    private ?StoreRepositoryInterface $storeRepository = null; // @phpstan-ignore-line
    /**
     * @var StoreResource|null
     */
    private ?StoreResource $storeResource = null; // @phpstan-ignore-line
    /**
     * @var StoreFactory|null
     */
    private ?StoreFactory $storeFactory = null; // @phpstan-ignore-line
    /**
     * @var SourceRepositoryInterface|null
     */
    private ?SourceRepositoryInterface $sourceRepository = null; // @phpstan-ignore-line
    /**
     * @var SourceResource|null
     */
    private ?SourceResource $sourceResource = null; // @phpstan-ignore-line
    /**
     * @var SourceInterfaceFactory|null
     */
    private ?SourceInterfaceFactory $sourceFactory = null; // @phpstan-ignore-line
    /**
     * @var SourceItemRepositoryInterface|null
     */
    private ?SourceItemRepositoryInterface $sourceItemRepository = null; // @phpstan-ignore-line
    /**
     * @var SourceItemResource|null
     */
    private ?SourceItemResource $sourceItemResource = null; // @phpstan-ignore-line
    /**
     * @var SourceItemInterfaceFactory|null
     */
    private ?SourceItemInterfaceFactory $sourceItemFactory = null; // @phpstan-ignore-line
    /**
     * @var SourceItemsProcessorInterface|null
     */
    private ?SourceItemsProcessorInterface $sourceItemsProcessor = null; // @phpstan-ignore-line
    /**
     * @var StockSourceLinkResource|null
     */
    private ?StockSourceLinkResource $stockSourceLinkResource = null; // @phpstan-ignore-line
    /**
     * @var StockSourceLinkInterfaceFactory|null
     */
    private ?StockSourceLinkInterfaceFactory $stockSourceLinkFactory = null; // @phpstan-ignore-line
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
     * @var IndexerFactory|null
     */
    private ?IndexerFactory $indexerFactory = null; // @phpstan-ignore-line
    /**
     * @var BulkSourceAssignInterface|null
     */
    private ?BulkSourceAssignInterface $bulkSourceAssign = null; // @phpstan-ignore-line
    /**
     * @var BulkSourceUnassignInterface|null
     */
    private ?BulkSourceUnassignInterface $bulkSourceUnassign = null; // @phpstan-ignore-line
    /**
     * @var StoresProviderInterface|null
     */
    private ?StoresProviderInterface $storesProvider = null; // @phpstan-ignore-line
    /**
     * @var ProductStockStatusProviderInterface|null
     */
    private ?ProductStockStatusProviderInterface $productStockStatusProvider = null; // @phpstan-ignore-line
    /**
     * @var IndexingEntityRepositoryInterface|null
     */
    private ?IndexingEntityRepositoryInterface $indexingEntityRepository = null; // @phpstan-ignore-line
    /**
     * @var string[]
     */
    private array $websiteCodes = [];
    /**
     * @var int[]
     */
    private array $stockIds = [];
    /**
     * @var SourceInterface[]
     */
    private array $sources = [];
    /**
     * @var string[]
     */
    private array $productSkus = [];

    /**
     * @return void
     */
    private function setUpProperties(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();

        $this->attributeFixturePool = $this->objectManager->get(AttributeFixturePool::class);
        $this->productFixturePool = $this->objectManager->get(ProductFixturePool::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);

        $this->configWriter = $this->objectManager->get(ConfigWriter::class);
        $this->stockItemFactory = $this->objectManager->get(StockItemInterfaceFactory::class);
        $this->stockFactory = $this->objectManager->get(StockInterfaceFactory::class);
        $this->stockRepository = $this->objectManager->get(StockRepositoryInterface::class);
        $this->stockRegistry = $this->objectManager->get(StockRegistryInterface::class);
        $this->salesChannelFactory = $this->objectManager->get(SalesChannelInterfaceFactory::class);
        $this->replaceSalesChannelsForStock = $this->objectManager->get(ReplaceSalesChannelsForStockInterface::class);
        $this->deleteSalesChannelToStockLink = $this->objectManager->get(DeleteSalesChannelToStockLinkInterface::class);

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
        $this->configurableOptionsFactory = $this->objectManager->get(ConfigurableOptionsFactory::class);
        $this->bundleOptionFactory = $this->objectManager->get(BundleOptionInterfaceFactory::class);
        $this->bundleLinkFactory = $this->objectManager->get(BundleLinkInterfaceFactory::class);
        $this->productLinkFactory = $this->objectManager->get(ProductLinkInterfaceFactory::class);

        $this->indexerFactory = $this->objectManager->get(IndexerFactory::class);

        $this->bulkSourceAssign = $this->objectManager->get(BulkSourceAssignInterface::class);
        $this->bulkSourceUnassign = $this->objectManager->get(BulkSourceUnassignInterface::class);

        $this->storesProvider = $this->objectManager->get(StoresProviderInterface::class);
        $this->productStockStatusProvider = $this->objectManager->get(ProductStockStatusProviderInterface::class);

        $this->indexingEntityRepository = $this->objectManager->get(IndexingEntityRepositoryInterface::class);
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws CouldNotDeleteException
     */
    private function deleteFixtures(): void
    {
        $this->deleteProductFixtures();
        $this->deleteStockAndSourceFixtures();
        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);
        }
        $this->deleteWebsiteAndStoreFixtures();

        $this->productFixturePool->rollback();
        $this->attributeFixturePool->rollback();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    /**
     * @return array<string, object>
     * @throws NoSuchEntityException
     */
    private function createWebsiteAndStoreFixtures(int $fixtureIndex): array
    {
        $website = $this->websiteFactory->create();
        $website->setCode($this->fixtureIdentifier . '_' . $fixtureIndex);
        $website->setName($this->fixtureName . ' ' . $fixtureIndex);
        try {
            $this->websiteResource->save($website);
        } catch (AlreadyExistsException) {
            $website = $this->websiteRepository->get($this->fixtureIdentifier . '_' . $fixtureIndex);
        }
        $this->websiteCodes[$fixtureIndex] = $website->getCode();

        $storeGroup = $this->storeGroupFactory->create();
        $storeGroup->setWebsite($website);
        $storeGroup->setCode($this->fixtureIdentifier . '_' . $fixtureIndex);
        $storeGroup->setName($this->fixtureName . ' ' . $fixtureIndex);
        $storeGroup->setRootCategoryId(2);
        try {
            $this->storeGroupResource->save($storeGroup);
        } catch (AlreadyExistsException) {
            $storeGroups = $this->storeGroupRepository->getList();
            foreach ($storeGroups as $existingStoreGroup) {
                if ($existingStoreGroup->getCode() === $this->fixtureIdentifier . '_' . $fixtureIndex) {
                    $storeGroup = $existingStoreGroup;
                    break;
                }
            }
        }

        $store = $this->storeFactory->create();
        $store->setWebsite($website);
        $store->setGroup($storeGroup);
        $store->setCode($this->fixtureIdentifier . '_' . $fixtureIndex);
        $store->setName($this->fixtureName . ' ' . $fixtureIndex);
        try {
            $this->storeResource->save($store);
        } catch (AlreadyExistsException) {
            $store = $this->storeRepository->get($this->fixtureIdentifier . '_' . $fixtureIndex);
        }

        if (isset($this->fixtureApiKeys[$fixtureIndex])) {
            $this->configWriter->save(
                path: 'klevu_configuration/auth_keys/js_api_key',
                value: $this->fixtureApiKeys[$fixtureIndex],
                scope: ScopeInterface::SCOPE_STORES,
                scopeId: (int)$store->getId(),
            );
            $this->configWriter->save(
                path: 'klevu_configuration/auth_keys/rest_auth_key',
                value: 'ABCDE1234567890',
                scope: ScopeInterface::SCOPE_STORES,
                scopeId: (int)$store->getId(),
            );
        }

        $this->scopeConfig->clean();
        $configuredJsApiKey = $this->scopeConfig->getValue(
            'klevu_configuration/auth_keys/js_api_key',
            ScopeInterface::SCOPE_STORES,
            (int)$store->getId(),
        );
        $configuredRestAuthKey = $this->scopeConfig->getValue(
            'klevu_configuration/auth_keys/rest_auth_key',
            ScopeInterface::SCOPE_STORES,
            (int)$store->getId(),
        );
        if (isset($this->fixtureApiKeys[$fixtureIndex])) {
            $this->assertSame(
                expected: $this->fixtureApiKeys[$fixtureIndex],
                actual: $configuredJsApiKey,
            );
            $this->assertSame(
                expected: 'ABCDE1234567890',
                actual: $configuredRestAuthKey,
            );
        } else {
            $this->assertNull($configuredJsApiKey);
            $this->assertNull($configuredRestAuthKey);
        }

        return [
            'website' => $website,
            'storeGroup' => $storeGroup,
            'store' => $store,
        ];
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     */
    private function deleteWebsiteAndStoreFixtures(): void
    {
        foreach ($this->websiteCodes as $websiteCode) {
            /** @var Website $website */
            $website = $this->websiteRepository->get($websiteCode);
            /** @var Store $store */
            foreach ($website->getStores() as $store) {
                /** @var StoreGroup $storeGroup */
                $storeGroup = $this->storeGroupRepository->get(
                    id: $store->getStoreGroupId(),
                );

                try {
                    $this->storeResource->delete($store);
                    $this->storeGroupResource->delete($storeGroup);
                } catch (NoSuchEntityException) {
                }
            }
            try {
                $this->websiteResource->delete($website);
            } catch (NoSuchEntityException) {
            }
        }
    }

    /**
     * @param int $fixtureIndex
     *
     * @return array
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    private function createSourceAndStockFixtures(
        int $fixtureIndex,
    ): array {
        $source = $this->sourceFactory->create();
        $source->setSourceCode($this->fixtureIdentifier . '_' . $fixtureIndex);
        $source->setName($this->fixtureName . ' ' . $fixtureIndex);
        $source->setEnabled(true);
        $source->setCountryId('GB');
        $source->setPostcode('AB' . $fixtureIndex . '23CD');
        try {
            $this->sourceRepository->save($source);
        } catch (AlreadyExistsException) {
            $source = $this->sourceRepository->get($this->fixtureIdentifier . '_' . $fixtureIndex);
        }
        $this->sources[$fixtureIndex] = $source;

        $stock = $this->stockFactory->create();
        $stock->setName($this->fixtureName . ' ' . $fixtureIndex);
        try {
            $this->stockIds[$fixtureIndex] = $this->stockRepository->save($stock);
        } catch (CouldNotSaveException) {
            $this->searchCriteriaBuilder->addFilter(
                field: Stock::NAME,
                value: $this->fixtureName . ' ' . $fixtureIndex,
                conditionType: 'eq',
            );
            $stockResult = $this->stockRepository->getList(
                searchCriteria: $this->searchCriteriaBuilder->create(),
            );

            foreach ($stockResult->getItems() as $stockItem) {
                $this->stockIds[$fixtureIndex] = (int)$stockItem->getId();
            }
        }
        /** @var StockSourceLinkInterface $stockSourceLink */
        $stockSourceLink = $this->stockSourceLinkFactory->create();
        $stockSourceLink->setSourceCode($this->fixtureIdentifier . '_' . $fixtureIndex);
        $stockSourceLink->setStockId($this->stockIds[$fixtureIndex]);
        $stockSourceLink->setPriority(0);
        try {
            $this->stockSourceLinkResource->save($stockSourceLink);
        } catch (AlreadyExistsException) {
            // This is fine
        }

        $salesChannel = $this->salesChannelFactory->create();
        $salesChannel->setType('website');
        $salesChannel->setCode($this->websiteCodes[$fixtureIndex]);
        $this->replaceSalesChannelsForStock->execute(
            salesChannels: [
                $salesChannel,
            ],
            stockId: $this->stockIds[$fixtureIndex],
        );

        return [
            'stock' => $stock,
            'source' => $source,
        ];
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     */
    private function deleteStockAndSourceFixtures(): void
    {
        foreach ($this->websiteCodes as $websiteCode) {
            $this->deleteSalesChannelToStockLink->execute(
                type: 'website',
                code: $websiteCode,
            );
        }

        foreach ($this->stockIds as $stockId) {
            try {
                $this->stockRepository->deleteById($stockId);
            } catch (NoSuchEntityException) {
            }
        }

        foreach ($this->sources as $source) {
            try {
                $this->sourceResource->delete($source);
            } catch (NoSuchEntityException) {
            }
        }
    }

    /**
     * @param int $fixtureIndex
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function createSimpleProductFixture(
        int $fixtureIndex,
        array $data = [],
    ): ProductInterface {
        $product = $this->productFactory->create();
        $product->setSku($this->fixtureIdentifier . '_s' . $fixtureIndex);
        $product->setName($this->fixtureName . ' (Simple ' . $fixtureIndex . ')');
        $product->setPrice(100.00);
        $product->setStatus(ProductStatus::STATUS_ENABLED);
        $product->setVisibility(ProductVisibility::VISIBILITY_BOTH);
        $product->setWeight(1.0);
        $product->setTypeId(ProductType::TYPE_SIMPLE);
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
        $this->productSkus[] = $product->getSku();

        return $product;
    }

    /**
     * @param int $fixtureIndex
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function createConfigurableProductFixture(int $fixtureIndex): ProductInterface
    {
        $product = $this->productFactory->create();
        $product->setSku($this->fixtureIdentifier . '_c' . $fixtureIndex);
        $product->setName($this->fixtureName . ' (Configurable ' . $fixtureIndex . ')');
        $product->setPrice(100.00);
        $product->setStatus(ProductStatus::STATUS_ENABLED);
        $product->setVisibility(ProductVisibility::VISIBILITY_BOTH);
        $product->setWeight(1.0);
        $product->setTypeId(ConfigurableType::TYPE_CODE);
        $product->setAttributeSetId(4);

        $product = $this->productRepository->save($product);
        $this->productSkus[] = $product->getSku();

        $extensionAttributes = $product->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $product->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => 1,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock(true);

        $extensionAttributes->setStockItem($stockItem);
        $product->setExtensionAttributes($extensionAttributes);

        return $this->productRepository->save($product);
    }

    /**
     * @param ProductInterface $configurableProduct
     * @param AttributeInterface[] $configurableAttributes
     * @param ProductInterface[] $variantProducts
     *
     * @return void
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function assignConfigurableVariantsToProduct(
        ProductInterface $configurableProduct,
        array $configurableAttributes,
        array $variantProducts,
    ): void {
        $extensionAttributes = $configurableProduct->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $attributeValues = [];
        foreach ($configurableAttributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $attributeValues[$attributeCode] = [];

            foreach ($variantProducts as $variantProduct) {
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
                array: $variantProducts,
            ),
        );

        $configurableProduct->setExtensionAttributes($extensionAttributes);

        $this->productRepository->save($configurableProduct);
    }

    /**
     * @param int $fixtureIndex
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function createGroupedProductFixture(int $fixtureIndex): ProductInterface
    {
        $product = $this->productFactory->create();
        $product->setSku($this->fixtureIdentifier . '_g' . $fixtureIndex);
        $product->setName($this->fixtureName . ' (Grouped ' . $fixtureIndex . ')');
        $product->setPrice(100.00);
        $product->setStatus(ProductStatus::STATUS_ENABLED);
        $product->setVisibility(ProductVisibility::VISIBILITY_BOTH);
        $product->setWeight(1.0);
        $product->setTypeId(GroupedType::TYPE_CODE);
        $product->setAttributeSetId(4);

        $product = $this->productRepository->save($product);
        $this->productSkus[] = $product->getSku();

        $extensionAttributes = $product->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $product->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => 1,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock(true);

        $extensionAttributes->setStockItem($stockItem);
        $product->setExtensionAttributes($extensionAttributes);

        return $this->productRepository->save($product);
    }

    /**
     * @param ProductInterface $groupedProduct
     * @param array $variantProducts
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws \Throwable
     */
    private function assignGroupedVariantsToProduct(
        ProductInterface $groupedProduct,
        array $variantProducts,
    ): ProductInterface {
        $productLinks = [];
        $position = 1;
        foreach ($variantProducts as $variantProduct) {
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

    /**
     * @param ProductInterface $product
     * @param array $websiteIds
     *
     * @return void
     */
    private function assignProductToWebsites(
        ProductInterface $product,
        array $websiteIds,
    ): void {
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
    }

    /**
     * @param int $fixtureIndex
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function createBundleProductFixture(int $fixtureIndex): ProductInterface
    {
        /** @var ProductInterface&DataObject $product */
        $product = $this->productFactory->create();
        $product->setSku($this->fixtureIdentifier . '_b' . $fixtureIndex);
        $product->setName($this->fixtureName . ' (Bundle ' . $fixtureIndex . ')');
        $product->setPrice(100.00);
        $product->setStatus(ProductStatus::STATUS_ENABLED);
        $product->setVisibility(ProductVisibility::VISIBILITY_BOTH);
        $product->setWeight(1.0);
        $product->setTypeId(BundleType::TYPE_CODE);
        $product->setAttributeSetId(4);
        $product->setDataUsingMethod(
            key: 'price_type',
            args: 0,
        );
        $product->setDataUsingMethod(
            key: 'price_view',
            args: 0,
        );
        $product->setDataUsingMethod(
            key: 'shipment_type',
            args: 1,
        );

        $product = $this->productRepository->save($product);
        $this->productSkus[] = $product->getSku();


        $extensionAttributes = $product->getExtensionAttributes();
        if (!$extensionAttributes) {
            $extensionAttributes = $this->objectManager->create(ProductExtensionInterface::class);
        }

        $product->setStockData(
            stockData: [
                'manage_stock' => 1,
                'is_in_stock' => 1,
            ],
        );
        $stockItem = $this->stockItemFactory->create();
        $stockItem->setManageStock(true);
        $stockItem->setQty(100);
        $stockItem->setIsQtyDecimal(false);
        $stockItem->setIsInStock(true);

        $extensionAttributes->setStockItem($stockItem);
        $product->setExtensionAttributes($extensionAttributes);

        return $this->productRepository->save($product);
    }

    /**
     * @param ProductInterface&DataObject $product
     * @param array $bundleOptionVariants
     *
     * @return ProductInterface
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws \Throwable
     */
    private function assignBundleOptionVariantsToProduct(
        ProductInterface $product,
        array $bundleOptionVariants,
    ): ProductInterface {
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

        $product->setDataUsingMethod(
            key: 'bundle_options_data',
            args: $bundleOptionsData,
        );
        $bundleOptionsData = $product->getDataUsingMethod(
            key: 'bundle_options_data',
        );

        $product->setDataUsingMethod(
            key: 'bundle_selections_data',
            args: $bundleSelectionsData,
        );
        $bundleSelectionsData = $product->getDataUsingMethod(
            key: 'bundle_selections_data',
        );

        $bundleOptions = [];
        foreach ($bundleOptionsData as $key => $optionData) {
            $bundleOption = $this->bundleOptionFactory->create();
            $bundleOption->setData($optionData);
            $bundleOption->setSku(
                sku: $product->getSku(),
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
        $bundleProductExtensionAttributes = $product->getExtensionAttributes();
        $bundleProductExtensionAttributes->setBundleProductOptions($bundleOptions);
        $product->setExtensionAttributes($bundleProductExtensionAttributes);

        $this->productRepository->save($product);

        $this->reindex([
            'cataloginventory_stock',
            'inventory',
        ]);

        return $this->productRepository->get(
            sku: $product->getSku(),
            forceReload: true,
        );
    }


    /**
     * @param ProductInterface $product
     * @param array $sources
     *
     * @return void
     * @throws InputException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    private function assignProductToSources(
        ProductInterface $product,
        array $sources,
        array $stockInformation,
    ): void {
        $sourceCodes = array_map(
            callback: static fn (SourceInterface $source): string => $source->getSourceCode(),
            array: $sources,
        );

        $this->bulkSourceAssign->execute(
            skus: [
                $product->getSku(),
            ],
            sourceCodes: $sourceCodes,
        );
        $this->bulkSourceUnassign->execute(
            skus: [
                $product->getSku(),
            ],
            sourceCodes: [
                'default',
            ],
        );

        $this->searchCriteriaBuilder->addFilter(
            field: SourceItemInterface::SKU,
            value: $product->getSku(),
            conditionType: 'eq',
        );
        $this->searchCriteriaBuilder->addFilter(
            field: SourceItemInterface::SOURCE_CODE,
            value: array_map(
                callback: static fn (SourceInterface $source): string => $source->getSourceCode(),
                array: $sources,
            ),
            conditionType: 'in',
        );
        $sourceItemsResult = $this->sourceItemRepository->getList(
            searchCriteria: $this->searchCriteriaBuilder->create(),
        );
        $sourceItems = $sourceItemsResult->getItems();
        $unsavedSourceItemCodes = array_diff(
            $sourceCodes,
            array_map(
                callback: static fn (SourceItemInterface $sourceItem): string => $sourceItem->getSourceCode(),
                array: $sourceItems,
            ),
        );
        foreach ($unsavedSourceItemCodes as $sourceItemCode) {
            /** @var SourceItemInterface $sourceItem */
            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSku($product->getSku());
            $sourceItem->setSourceCode($sourceItemCode);
            $sourceItems[] = $sourceItem;
        }

        $sourceItemsData = [];

        foreach ($sourceItems as $sourceItem) {
            $sourceItem->setQuantity(
                quantity: $stockInformation[$sourceItem->getSourceCode()]['quantity'],
            );
            $sourceItem->setStatus(
                status: $stockInformation[$sourceItem->getSourceCode()]['status'],
            );

            try {
                $this->sourceItemResource->save($sourceItem);
            } catch (AlreadyExistsException) {
            }

            $source = current(
                array_filter(
                    array: $sources,
                    callback: static fn (SourceInterface $source): bool => (
                        $source->getSourceCode() === $sourceItem->getSourceCode()
                    ),
                ),
            );
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

    /**
     * @return void
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function deleteProductFixtures(): void
    {
        foreach ($this->productSkus as $sku) {
            $this->productRepository->deleteById($sku);
        }
    }

    /**
     * @param ProductInterface $product
     * @param ProductInterface|null $parentProduct
     * @param string $apiKey
     * @param array<string, mixed> $dataOverrides
     *
     * @return IndexingEntityInterface
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     */
    private function createIndexingEntityFixture(
        ProductInterface $product,
        ?ProductInterface $parentProduct,
        string $apiKey,
        array $dataOverrides,
    ): IndexingEntityInterface {
        return $this->createIndexingEntity(
            data: array_merge(
                [
                    IndexingEntity::TARGET_ID => (int)$product->getId(),
                    IndexingEntity::TARGET_PARENT_ID => ($parentProduct)
                        ? (int)$parentProduct->getId()
                        : null,
                    IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
                    IndexingEntity::API_KEY => $apiKey,
                    IndexingEntity::NEXT_ACTION => Actions::NO_ACTION,
                    IndexingEntity::IS_INDEXABLE => true,
                    IndexingEntity::REQUIRES_UPDATE => false,
                    IndexingEntity::REQUIRES_UPDATE_ORIG_VALUES => [],
                ],
                $dataOverrides,
            ),
        );
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
