<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Plugin\InventoryCatalog\Model\BulkSourceAssign;

use Klevu\Indexing\Model\IndexingEntity;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingApi\Service\Provider\IndexingEntityProviderInterface;
use Klevu\IndexingMsi\Plugin\InventoryCatalog\Model\BulkSourceAssign\SetRequiresUpdatePlugin;
use Klevu\IndexingMsi\Test\Integration\Plugin\InventoryCatalog\Model\BulkSourceTrait;
use Klevu\IndexingProducts\Service\Determiner\RequiresUpdateCriteria\StockStatus as StockStatusCriteria;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Magento\InventoryCatalogApi\Api\BulkSourceAssignInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Klevu\IndexingMsi\Plugin\InventoryCatalog\Model\BulkSourceAssign\SetRequiresUpdatePlugin::class
 * @method SetRequiresUpdatePlugin instantiateTestObject(?array $arguments = null)
 * @runTestsInSeparateProcesses
 */
class SetRequiresUpdatePluginTest extends TestCase
{
    use BulkSourceTrait;
    use ObjectInstantiationTrait;

    /**
     * @var IndexingEntityProviderInterface|null
     */
    private ?IndexingEntityProviderInterface $indexingEntityProvider = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->implementationFqcn = SetRequiresUpdatePlugin::class;

        $this->fixtureIdentifier = 'klevu_test_msirequiresupdate';
        $this->fixtureName = 'Klevu Test: MSI BulkSourceAssign RequiresUpdate Plugin';
        $this->fixtureApiKeys = [
            1 => 'klevu-1234567890',
            2 => 'klevu-9876543210',
        ];

        $this->setUpProperties();

        $this->indexingEntityProvider = $this->objectManager->get(IndexingEntityProviderInterface::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteFixtures();
    }

    public function testFqcnResolvesToExpectedImplementation(): void
    {
        // Intentionally empty override as test runs elsewhere and trigger error
        //  when @runInSeparateProcesses is active
    }

    public function testPluginIsAttached_SimpleProduct_MultipleStocks(): void
    {
        $this->registry->register('isSecureArea', true);

        $websiteAndStoreFixtures = [
            1 => $this->createWebsiteAndStoreFixtures(1),
            2 => $this->createWebsiteAndStoreFixtures(2),
            3 => $this->createWebsiteAndStoreFixtures(3),
        ];
        $sourceAndStockFixtures = [
            1 => $this->createSourceAndStockFixtures(1),
            2 => $this->createSourceAndStockFixtures(2),
            3 => $this->createSourceAndStockFixtures(3),
        ];
        $productFixture = $this->createSimpleProductFixture(1);
        $this->assignProductToWebsites(
            product: $productFixture,
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $productFixture,
            sources: [
                $sourceAndStockFixtures[1]['source'],
                $sourceAndStockFixtures[3]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[1]['source']->getSourceCode() => [
                    'quantity' => 0,
                    'status' => 0,
                ],
                $sourceAndStockFixtures[3]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);
        }
        $this->createIndexingEntityFixture(
            product: $productFixture,
            parentProduct: null,
            apiKey: $this->fixtureApiKeys[1],
            dataOverrides: [],
        );
        $this->createIndexingEntityFixture(
            product: $productFixture,
            parentProduct: null,
            apiKey: $this->fixtureApiKeys[2],
            dataOverrides: [],
        );

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [(int)$productFixture->getId()],
        );
        $this->assertCount(
            expectedCount: 2,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertContains(
                needle: $indexingEntity->getApiKey(),
                haystack: $this->fixtureApiKeys,
            );
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

        /** @var BulkSourceAssignInterface $bulkSourceAssign */
        $bulkSourceAssign = $this->objectManager->create(BulkSourceAssignInterface::class);
        $bulkSourceAssign->execute(
            skus: [
                $productFixture->getSku(),
            ],
            sourceCodes: [
                $sourceAndStockFixtures[2]['source']->getSourceCode(),
            ],
        );

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [(int)$productFixture->getId()],
        );
        $this->assertCount(
            expectedCount: 2,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertSame(
                expected: Actions::NO_ACTION,
                actual: $indexingEntity->getNextAction(),
            );

            switch ($indexingEntity->getApiKey()) {
                case $this->fixtureApiKeys[1]:
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $this->assertEmpty(
                        actual: $indexingEntity->getRequiresUpdateOrigValues(),
                    );
                    break;

                case $this->fixtureApiKeys[2]:
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertFalse(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                default:
                    $this->fail('Unexpected API Key');
                    break;
            }
        }
    }

    public function testPluginIsAttached_ConfigurableProduct(): void
    {
        $this->registry->register('isSecureArea', true);

        $websiteAndStoreFixtures = [
            1 => $this->createWebsiteAndStoreFixtures(1),
            2 => $this->createWebsiteAndStoreFixtures(2),
            3 => $this->createWebsiteAndStoreFixtures(3),
        ];
        $sourceAndStockFixtures = [
            1 => $this->createSourceAndStockFixtures(1),
            2 => $this->createSourceAndStockFixtures(2),
            3 => $this->createSourceAndStockFixtures(3),
        ];

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

        $variantProductFixtures = [
            1 => $this->createSimpleProductFixture(
                fixtureIndex: 1,
                data: [
                    $configurableAttribute->getAttributeCode() => '1',
                ],
            ),
            2 => $this->createSimpleProductFixture(
                fixtureIndex: 2,
                data: [
                    $configurableAttribute->getAttributeCode() => '2',
                ],
            ),
        ];
        $this->assignProductToWebsites(
            product: $variantProductFixtures[1],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[1],
            sources: [
                $sourceAndStockFixtures[1]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[1]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[2],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[2],
            sources: [
                $sourceAndStockFixtures[2]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[2]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );

        $configurableProductFixture = $this->createConfigurableProductFixture(1);
        $this->assignProductToWebsites(
            product: $configurableProductFixture,
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignConfigurableVariantsToProduct(
            configurableProduct: $configurableProductFixture,
            configurableAttributes: [
                $configurableAttribute->getAttributeCode() => $configurableAttribute,
            ],
            variantProducts: $variantProductFixtures,
        );

        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);

            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[1],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[1],
                parentProduct: $configurableProductFixture,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable_variant',
                ],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[2],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[2],
                parentProduct: $configurableProductFixture,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable_variant',
                ],
            );
            $this->createIndexingEntityFixture(
                product: $configurableProductFixture,
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable',
                ],
            );
        }

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$configurableProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 10,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertContains(
                needle: $indexingEntity->getApiKey(),
                haystack: $this->fixtureApiKeys,
            );
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

        /** @var BulkSourceAssignInterface $bulkSourceAssign */
        $bulkSourceAssign = $this->objectManager->create(BulkSourceAssignInterface::class);
        $bulkSourceAssign->execute(
            skus: [
                $variantProductFixtures[1]->getSku(),
            ],
            sourceCodes: [
                $sourceAndStockFixtures[2]['source']->getSourceCode(),
            ],
        );

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$configurableProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 10,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertSame(
                expected: Actions::NO_ACTION,
                actual: $indexingEntity->getNextAction(),
            );

            switch (true) {
                case (int)$configurableProductFixture->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertTrue(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                case (int)$variantProductFixtures[1]->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertFalse(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                default:
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $this->assertEmpty(
                        actual: $indexingEntity->getRequiresUpdateOrigValues(),
                    );
                    break;
            }
        }
    }

    public function testPluginIsAttached_GroupedProduct(): void
    {
        $this->registry->register('isSecureArea', true);

        $websiteAndStoreFixtures = [
            1 => $this->createWebsiteAndStoreFixtures(1),
            2 => $this->createWebsiteAndStoreFixtures(2),
            3 => $this->createWebsiteAndStoreFixtures(3),
        ];
        $sourceAndStockFixtures = [
            1 => $this->createSourceAndStockFixtures(1),
            2 => $this->createSourceAndStockFixtures(2),
            3 => $this->createSourceAndStockFixtures(3),
        ];
        $variantProductFixtures = [
            1 => $this->createSimpleProductFixture(1),
            2 => $this->createSimpleProductFixture(2),
        ];
        $this->assignProductToWebsites(
            product: $variantProductFixtures[1],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[1],
            sources: [
                $sourceAndStockFixtures[1]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[1]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[2],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[2],
            sources: [
                $sourceAndStockFixtures[2]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[2]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );

        $groupedProductFixture = $this->createGroupedProductFixture(1);
        $this->assignProductToWebsites(
            product: $groupedProductFixture,
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $groupedProductFixture = $this->assignGroupedVariantsToProduct(
            groupedProduct: $groupedProductFixture,
            variantProducts: [
                $variantProductFixtures[1],
                $variantProductFixtures[2],
            ],
        );
        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);

            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[1],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[2],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $groupedProductFixture,
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'grouped',
                ],
            );
        }

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$groupedProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 6,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertContains(
                needle: $indexingEntity->getApiKey(),
                haystack: $this->fixtureApiKeys,
            );
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

        /** @var BulkSourceAssignInterface $bulkSourceAssign */
        $bulkSourceAssign = $this->objectManager->create(BulkSourceAssignInterface::class);
        $bulkSourceAssign->execute(
            skus: [
                $variantProductFixtures[1]->getSku(),
            ],
            sourceCodes: [
                $sourceAndStockFixtures[2]['source']->getSourceCode(),
            ],
        );

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$groupedProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 6,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertSame(
                expected: Actions::NO_ACTION,
                actual: $indexingEntity->getNextAction(),
            );

            switch (true) {
                case (int)$groupedProductFixture->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertTrue(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                case (int)$variantProductFixtures[1]->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertFalse(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                default:
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $this->assertEmpty(
                        actual: $indexingEntity->getRequiresUpdateOrigValues(),
                    );
                    break;
            }
        }
    }

    public function testPluginIsAttached_BundleProduct_RequiredOption(): void
    {
        $this->registry->register('isSecureArea', true);

        $websiteAndStoreFixtures = [
            1 => $this->createWebsiteAndStoreFixtures(1),
            2 => $this->createWebsiteAndStoreFixtures(2),
            3 => $this->createWebsiteAndStoreFixtures(3),
        ];
        $sourceAndStockFixtures = [
            1 => $this->createSourceAndStockFixtures(1),
            2 => $this->createSourceAndStockFixtures(2),
            3 => $this->createSourceAndStockFixtures(3),
        ];
        $variantProductFixtures = [
            1 => $this->createSimpleProductFixture(1),
            2 => $this->createSimpleProductFixture(2),
            3 => $this->createSimpleProductFixture(3),
        ];
        $this->assignProductToWebsites(
            product: $variantProductFixtures[1],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[1],
            sources: [
                $sourceAndStockFixtures[1]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[1]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[2],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[2],
            sources: [
                $sourceAndStockFixtures[2]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[2]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[3],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[3],
            sources: [
                $sourceAndStockFixtures[3]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[3]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );

        $bundleProductFixture = $this->createBundleProductFixture(1);
        $this->assignProductToWebsites(
            product: $bundleProductFixture,
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $bundleProductFixture = $this->assignBundleOptionVariantsToProduct(
            product: $bundleProductFixture,
            bundleOptionVariants: [
                'required' => [
                    $variantProductFixtures[1],
                    $variantProductFixtures[2],
                ],
                'optional' => [
                    $variantProductFixtures[3],
                ],
            ],
        );
        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);

            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[1],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[2],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[3],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $bundleProductFixture,
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'bundle',
                ],
            );
        }

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$variantProductFixtures[3]->getId(),
                (int)$bundleProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 8,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertContains(
                needle: $indexingEntity->getApiKey(),
                haystack: $this->fixtureApiKeys,
            );
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

        /** @var BulkSourceAssignInterface $bulkSourceAssign */
        $bulkSourceAssign = $this->objectManager->create(BulkSourceAssignInterface::class);
        $bulkSourceAssign->execute(
            skus: [
                $variantProductFixtures[1]->getSku(),
            ],
            sourceCodes: [
                $sourceAndStockFixtures[2]['source']->getSourceCode(),
            ],
        );


        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$variantProductFixtures[3]->getId(),
                (int)$bundleProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 8,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertSame(
                expected: Actions::NO_ACTION,
                actual: $indexingEntity->getNextAction(),
            );

            switch (true) {
                case (int)$bundleProductFixture->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertTrue(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                case (int)$variantProductFixtures[1]->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertFalse(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                default:
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $this->assertEmpty(
                        actual: $indexingEntity->getRequiresUpdateOrigValues(),
                    );
                    break;
            }
        }
    }

    /**
     * @group wip
     */
    public function testPluginIsAttached_BundleProduct_OptionalOption(): void
    {
        $this->registry->register('isSecureArea', true);

        $websiteAndStoreFixtures = [
            1 => $this->createWebsiteAndStoreFixtures(1),
            2 => $this->createWebsiteAndStoreFixtures(2),
            3 => $this->createWebsiteAndStoreFixtures(3),
        ];
        $sourceAndStockFixtures = [
            1 => $this->createSourceAndStockFixtures(1),
            2 => $this->createSourceAndStockFixtures(2),
            3 => $this->createSourceAndStockFixtures(3),
        ];
        $variantProductFixtures = [
            1 => $this->createSimpleProductFixture(1),
            2 => $this->createSimpleProductFixture(2),
            3 => $this->createSimpleProductFixture(3),
        ];
        $this->assignProductToWebsites(
            product: $variantProductFixtures[1],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[1],
            sources: [
                $sourceAndStockFixtures[1]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[1]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[2],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[2],
            sources: [
                $sourceAndStockFixtures[2]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[2]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );
        $this->assignProductToWebsites(
            product: $variantProductFixtures[3],
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $this->assignProductToSources(
            product: $variantProductFixtures[3],
            sources: [
                $sourceAndStockFixtures[3]['source'],
            ],
            stockInformation: [
                $sourceAndStockFixtures[3]['source']->getSourceCode() => [
                    'quantity' => 1,
                    'status' => 1,
                ],
            ],
        );

        $bundleProductFixture = $this->createBundleProductFixture(1);
        $this->assignProductToWebsites(
            product: $bundleProductFixture,
            websiteIds: [
                (int)$websiteAndStoreFixtures[1]['website']->getId(),
                (int)$websiteAndStoreFixtures[2]['website']->getId(),
            ],
        );
        $bundleProductFixture = $this->assignBundleOptionVariantsToProduct(
            product: $bundleProductFixture,
            bundleOptionVariants: [
                'required' => [
                    $variantProductFixtures[1],
                    $variantProductFixtures[2],
                ],
                'optional' => [
                    $variantProductFixtures[3],
                ],
            ],
        );
        foreach ($this->fixtureApiKeys as $apiKey) {
            $this->cleanIndexingEntities($apiKey);

            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[1],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[2],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $variantProductFixtures[3],
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [],
            );
            $this->createIndexingEntityFixture(
                product: $bundleProductFixture,
                parentProduct: null,
                apiKey: $apiKey,
                dataOverrides: [
                    IndexingEntity::TARGET_ENTITY_SUBTYPE => 'bundle',
                ],
            );
        }

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$variantProductFixtures[3]->getId(),
                (int)$bundleProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 8,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertContains(
                needle: $indexingEntity->getApiKey(),
                haystack: $this->fixtureApiKeys,
            );
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

        /** @var BulkSourceAssignInterface $bulkSourceAssign */
        $bulkSourceAssign = $this->objectManager->create(BulkSourceAssignInterface::class);
        $bulkSourceAssign->execute(
            skus: [
                $variantProductFixtures[3]->getSku(),
            ],
            sourceCodes: [
                $sourceAndStockFixtures[2]['source']->getSourceCode(),
            ],
        );

        $indexingEntities = $this->indexingEntityProvider->get(
            entityType: 'KLEVU_PRODUCT',
            apiKeys: $this->fixtureApiKeys,
            entityIds: [
                (int)$variantProductFixtures[1]->getId(),
                (int)$variantProductFixtures[2]->getId(),
                (int)$variantProductFixtures[3]->getId(),
                (int)$bundleProductFixture->getId(),
            ],
        );
        $this->assertCount(
            expectedCount: 8,
            haystack: $indexingEntities,
        );
        foreach ($indexingEntities as $indexingEntity) {
            $this->assertSame(
                expected: Actions::NO_ACTION,
                actual: $indexingEntity->getNextAction(),
            );

            switch (true) {
                // Note, ideally we would ignore optional options, but that's a lot of additional
                //  processing for an uncommon scenario
                case (int)$bundleProductFixture->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertTrue(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                case (int)$variantProductFixtures[3]->getId() === $indexingEntity->getTargetId()
                    && $this->fixtureApiKeys[2] === $indexingEntity->getApiKey():
                    $this->assertTrue(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $requiresUpdateOrigValues = $indexingEntity->getRequiresUpdateOrigValues();
                    $this->assertCount(
                        expectedCount: 1,
                        haystack: $requiresUpdateOrigValues,
                    );
                    $this->assertArrayHasKey(
                        key: StockStatusCriteria::CRITERIA_IDENTIFIER,
                        array: $requiresUpdateOrigValues,
                    );
                    $this->assertFalse(
                        condition: $requiresUpdateOrigValues[StockStatusCriteria::CRITERIA_IDENTIFIER],
                    );
                    break;

                default:
                    $this->assertFalse(
                        condition: $indexingEntity->getRequiresUpdate(),
                    );
                    $this->assertEmpty(
                        actual: $indexingEntity->getRequiresUpdateOrigValues(),
                    );
                    break;
            }
        }
    }
}
