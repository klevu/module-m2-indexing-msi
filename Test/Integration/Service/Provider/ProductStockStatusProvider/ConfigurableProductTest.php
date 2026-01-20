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
class ConfigurableProductTest extends TestCase
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
        $this->fixtureName = 'Klevu Test: Product Stock Status (MSI Configurable)';

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
        if (
            'is_available' === $stockStatusCalculationMethod
            || 'is_salable' === $stockStatusCalculationMethod
        ) {
            // \Magento\ConfigurableProduct\Model\Product\Type\Collection\SalableProcessor::process returns false
            //  where no items exist for the linked products in cataloginventory_stock_status
            // All items in this table have a stock_id = 1 and website_id = 0; we are explicitly testing
            //  outside these defaults
            $this->markTestSkipped(
                message: 'Known issue: Fails at Configurable'
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

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
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 0,
            stockStatus: true,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            stockStatus: false,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

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
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

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
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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
                message: 'Known issue: Fails at Variant 1; Store 2'
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source2'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            stockStatus: false,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertTrue(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

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
            reservations: [
                'quantity' => 1,
                'stockId' => $stockAndSourceFixtures['stock1']->getId(),
            ],
            data: [
                $configurableAttribute->getAttributeCode() => '1',
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
            quantity: 2,
            stockStatus: true,
            reservations: [
                'quantity' => 3,
                'stockId' => $stockAndSourceFixtures['stock1']->getId(),
            ],
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
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

        $this->createAttribute(
            attributeData: [
                'key' => $this->fixtureIdentifier,
                'code' => $this->fixtureIdentifier,
                'attribute_type' => 'configurable',
                'options' => [
                    '1' => 'Option 1',
                    '2' => 'Option 2',
                ],
            ],
        );
        $configurableAttributeFixture = $this->attributeFixturePool->get(
            key: $this->fixtureIdentifier,
        );
        $configurableAttribute = $configurableAttributeFixture->getAttribute();

        $variantProduct1 = $this->createSimpleProductFixture(
            appendIdentifier: 'v1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source2'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '1',
            ],
        );
        $variantProduct2 = $this->createSimpleProductFixture(
            appendIdentifier: 'v2',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
            data: [
                $configurableAttribute->getAttributeCode() => '2',
            ],
        );
        $configurableProduct = $this->createConfigurableProductFixture(
            appendIdentifier: 'c1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            configurableVariants: [
                $variantProduct1,
                $variantProduct2,
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
            message: 'Simple 1; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct1,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 1; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $variantProduct2,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Simple 2; Store 2',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store1'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 1',
        );
        $this->assertFalse(
            condition: $productStockStatusProvider->get(
                product: $configurableProduct,
                store: $websiteAndStoreFixtures['store2'],
                parentProduct: null,
            ),
            message: 'Configurable; Store 2',
        );
    }
}
