<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Provider\ProductStockStatusProvider;

use Klevu\IndexingProducts\Service\Provider\ProductStockStatusProvider;
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
class SimpleProductTest extends TestCase
{
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
        $this->fixtureName = 'Klevu Test: Product Stock Status (MSI Simple)';

        $this->objectManager = Bootstrap::getObjectManager();

        $this->setUpProperties();
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
    public function testGet_InStock(
        string $stockStatusCalculationMethod,
    ): void {
        if ('stock_item' === $stockStatusCalculationMethod) {
            $this->markTestSkipped(
                message: 'Known issue'
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

        $product = $this->createSimpleProductFixture(
            appendIdentifier: '1',
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

        $productStockStatusProvider = $this->instantiateTestObject();

        $result = $productStockStatusProvider->get(
            product: $product,
            store: $websiteAndStoreFixtures['store1'],
            parentProduct: null,
        );

        $this->assertTrue($result);
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_OutOfStock(
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

        $product = $this->createSimpleProductFixture(
            appendIdentifier: '1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website1']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 0,
            stockStatus: false,
            reservations: null,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $result = $productStockStatusProvider->get(
            product: $product,
            store: $websiteAndStoreFixtures['store1'],
            parentProduct: null,
        );

        $this->assertFalse($result);
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_OutOfStock_ViaReservations(
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

        $product = $this->createSimpleProductFixture(
            appendIdentifier: '1',
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
                'stockId' => (int)$stockAndSourceFixtures['stock1']->getId(),
            ],
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $result = $productStockStatusProvider->get(
            product: $product,
            store: $websiteAndStoreFixtures['store1'],
            parentProduct: null,
        );

        $this->assertFalse($result);
    }

    /**
     * @testWith ["stock_item"]
     *           ["stock_registry"]
     *           ["is_available"]
     *           ["is_salable"]
     */
    public function testGet_OutOfStock_ViaSourceNotAssignedWebsite(
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

        $product = $this->createSimpleProductFixture(
            appendIdentifier: '1',
            websiteIds: [
                (int)$websiteAndStoreFixtures['website2']->getId(),
            ],
            sources: [
                $stockAndSourceFixtures['source1'],
            ],
            quantity: 1,
            stockStatus: true,
            reservations: null,
        );

        $productStockStatusProvider = $this->instantiateTestObject();

        $result = $productStockStatusProvider->get(
            product: $product,
            store: $websiteAndStoreFixtures['store1'],
            parentProduct: null,
        );

        $this->assertFalse($result);
    }
}
