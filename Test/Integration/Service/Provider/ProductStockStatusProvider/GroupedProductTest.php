<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Provider\ProductStockStatusProvider;

use Klevu\IndexingProducts\Model\Source\StockStatusCalculationMethod;
use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProvider;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\SetAreaTrait;
use Magento\Framework\App\Area;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers \Klevu\IndexingProducts\Service\Provider\ProductStockStatusProvider::class
 * @method ProductStockStatusProvider instantiateTestObject(?array $arguments = null)
 * @method ProductStockStatusProvider instantiateTestObjectFromInterface(?array $arguments = null)
 * @runTestsInSeparateProcesses
 * @todo Backorders
 */
class GroupedProductTest extends TestCase
{
    use AttributeTrait;
    use ObjectInstantiationTrait;
    use ProductStockStatusProviderTestTrait;
    use SetAreaTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->implementationFqcn = ProductStockStatusProvider::class;
        $this->fixtureIdentifier = 'klevu_test_productstockstatus';
        $this->fixtureName = 'Klevu Test: Product Stock Status (MSI Grouped)';

        $this->objectManager = Bootstrap::getObjectManager();

        $this->setUpProperties();
        $this->attributeFixturePool = $this->objectManager->get(AttributeFixturePool::class);

        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: StockStatusCalculationMethod::STOCK_REGISTRY->value,
        );
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteStockAndSourceFixtures();
        $this->deleteProductFixtures();
        $this->attributeFixturePool->rollback();
        $this->deleteWebsiteAndStoreFixtures();
    }

    public function testFqcnResolvesToExpectedImplementation(): void
    {
        // Intentionally empty override as test runs elsewhere and trigger error
        //  when @runInSeparateProcesses is active
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenInStock(
        string $stockStatusCalculationMethod,
    ): void {
        if ('stock_item' === $stockStatusCalculationMethod) {
            $this->markTestSkipped(
                message: 'Known issue: Fails at Variant 1; Store 1'
            );
        }

        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 2,
            stockStatus: true,
            reservations: [
                'quantity' => 1,
                'stockId' => $stockAndSourceFixtures['stock1']->getId(),
            ],
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 0,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenOutOfStock(
        string $stockStatusCalculationMethod,
    ): void {
        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: false,
            reservations: null,
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 0,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_OutOfStock_ChildrenInStock(
        string $stockStatusCalculationMethod,
    ): void {
        if ('stock_item' === $stockStatusCalculationMethod) {
            $this->markTestSkipped(
                message: 'Known issue: Fails at Variant 1; Store 1'
            );
        }

        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
                $stockAndSourceFixtures['source2'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: false,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenInStock_NotAssignedWebsite(
        string $stockStatusCalculationMethod,
    ): void {
        if ('stock_item' === $stockStatusCalculationMethod) {
            $this->markTestSkipped(
                message: 'Known issue: Fails at Variant 1; Store 1'
            );
        }

        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenInStock_ChildrenNotAssignedWebsiteOrOos(
        string $stockStatusCalculationMethod,
    ): void {
        if ('stock_item' === $stockStatusCalculationMethod) {
            $this->markTestSkipped(
                message: 'Known issue: Fails at Variant 2; Store 2'
            );
        }

        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: false,
            reservations: null,
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
                $stockAndSourceFixtures['source2'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenOutOfStock_ViaReservations(
        string $stockStatusCalculationMethod,
    ): void {
        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 2,
            stockStatus: true,
            reservations: [
                'quantity' => 3,
                'stockId' => $stockAndSourceFixtures['stock1']->getId(),
            ],
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: [
                'quantity' => 1,
                'stockId' => $stockAndSourceFixtures['stock1']->getId(),
            ],
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_InStock_ChildrenOutOfStock_ViaSourceNotAssignedWebsite(
        string $stockStatusCalculationMethod,
    ): void {
        $this->setArea(Area::AREA_ADMINHTML);
        $this->registry->register(
            key: 'isSecureArea',
            value: true,
        );
        ConfigFixture::setGlobal(
            path: ProductStockStatusProvider::XML_PATH_STOCK_STATUS_CALCULATION_METHOD,
            value: $stockStatusCalculationMethod,
        );

        $websiteAndStoreFixtures = array_merge(
            $this->createWebsiteAndStoreFixtures('1'),
            $this->createWebsiteAndStoreFixtures('2'),
        );
        $stockAndSourceFixtures = array_merge(
            $this->createStockAndSourceFixtures('1'),
            $this->createStockAndSourceFixtures('2'),
        );

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 2,
            stockStatus: true,
            reservations: null,
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source2'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );
        $groupedProduct = $this->createGroupedProductFixture(
            appendIdentifier: 'g1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            groupedVariants: [
                $variantProduct1,
                $variantProduct2
            ],
            stockStatus: true,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Variant 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $groupedProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Grouped; Store 2',
        );
    }
}