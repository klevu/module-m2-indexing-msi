<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Provider;

use Klevu\IndexingMsi\Service\Provider\StockIdsForSourceCodesProvider;
use Klevu\IndexingMsi\Service\Provider\StockIdsForSourceCodesProviderInterface;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Validation\ValidationException;
use Magento\Inventory\Model\ResourceModel\Source as SourceResource;
use Magento\Inventory\Model\ResourceModel\StockSourceLink as StockSourceLinkResource;
use Magento\Inventory\Model\Stock;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Klevu\IndexingMsi\Service\Provider\StockIdsForSourceCodesProvider::class
 * @method StockIdsForSourceCodesProvider instantiateTestObject(?array $arguments = null)
 * @method StockIdsForSourceCodesProviderInterface instantiateTestObjectFromInterface(?array $arguments = null)
 */
class StockIdsForSourceCodeProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
    /**
     * @var SearchCriteriaBuilder|null
     */
    private ?SearchCriteriaBuilder $searchCriteriaBuilder = null;
    /**
     * @var StockInterfaceFactory|null
     */
    private ?StockInterfaceFactory $stockFactory = null; // @phpstan-ignore-line
    /**
     * @var StockRepositoryInterface|null
     */
    private ?StockRepositoryInterface $stockRepository = null; // @phpstan-ignore-line
    /**
     * @var StockRegistryInterface|null
     */
    private ?StockRegistryInterface $stockRegistry = null; // @phpstan-ignore-line
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
     * @var StockSourceLinkResource|null
     */
    private ?StockSourceLinkResource $stockSourceLinkResource = null; // @phpstan-ignore-line
    /**
     * @var StockSourceLinkInterfaceFactory|null
     */
    private ?StockSourceLinkInterfaceFactory $stockSourceLinkFactory = null; // @phpstan-ignore-line
    /**
     * @var SourceInterface[]
     */
    private array $sources = [];
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

        $this->implementationFqcn = StockIdsForSourceCodesProvider::class;
        $this->interfaceFqcn = StockIdsForSourceCodesProviderInterface::class;

        $this->objectManager = Bootstrap::getObjectManager();
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);

        $this->stockFactory = $this->objectManager->get(StockInterfaceFactory::class);
        $this->stockRepository = $this->objectManager->get(StockRepositoryInterface::class);
        $this->stockRegistry = $this->objectManager->get(StockRegistryInterface::class);

        $this->sourceRepository = $this->objectManager->get(SourceRepositoryInterface::class);
        $this->sourceResource = $this->objectManager->get(SourceResource::class);
        $this->sourceFactory = $this->objectManager->get(SourceInterfaceFactory::class);

        $this->stockSourceLinkResource = $this->objectManager->get(StockSourceLinkResource::class);
        $this->stockSourceLinkFactory = $this->objectManager->get(StockSourceLinkInterfaceFactory::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->deleteStockAndSourceFixtures();
    }

    public function testGetForSourceCodes(): void
    {
        $this->createStockAndSourceFixtures(
            sourcesCount: 2,
            stocksCount: 4,
        );
        $this->createStockSourceLink(
            sourceCode: $this->sources[1]->getSourceCode(),
            stockId: $this->stockIds[1],
            priority: 0,
        );
        $this->createStockSourceLink(
            sourceCode: $this->sources[1]->getSourceCode(),
            stockId: $this->stockIds[3],
            priority: 1,
        );
        $this->createStockSourceLink(
            sourceCode: $this->sources[2]->getSourceCode(),
            stockId: $this->stockIds[2],
            priority: 0,
        );

        $stockIdsForSourceCodesProvider = $this->instantiateTestObject();

        $result1 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
            ],
            actual: $result1,
            message: 'Source 1; initial',
        );

        $result2 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                ],
            ],
            actual: $result2,
            message: 'Source 2; initial',
        );

        $resultAll = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                ],
            ],
            actual: $resultAll,
            message: 'Source 1 + 2; initial',
        );

        $this->createStockSourceLink(
            sourceCode: $this->sources[2]->getSourceCode(),
            stockId: $this->stockIds[4],
            priority: 1,
        );

        $result1 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
            ],
            actual: $result1,
            message: 'Source 1; after change, cached',
        );

        $result2 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                ],
            ],
            actual: $result2,
            message: 'Source 2; after change, cached',
        );

        $resultAll = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                ],
            ],
            actual: $resultAll,
            message: 'Source 1 + 2; after change, cached',
        );

        $stockIdsForSourceCodesProvider->clearCache();

        $result1 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
            ],
            actual: $result1,
            message: 'Source 1; after change, uncached',
        );

        $result2 = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                    $this->stockIds[4],
                ],
            ],
            actual: $result2,
            message: 'Source 2; after change, uncached',
        );

        $resultAll = $stockIdsForSourceCodesProvider->getForSourceCodes(
            sourceCodes: [
                $this->sources[1]->getSourceCode(),
                $this->sources[2]->getSourceCode(),
            ],
        );
        $this->assertSame(
            expected: [
                $this->sources[1]->getSourceCode() => [
                    $this->stockIds[1],
                    $this->stockIds[3],
                ],
                $this->sources[2]->getSourceCode() => [
                    $this->stockIds[2],
                    $this->stockIds[4],
                ],
            ],
            actual: $resultAll,
            message: 'Source 1 + 2; after change, uncached',
        );
    }

    /**
     * @param int $sourcesCount
     * @param int $stocksCount
     *
     * @return void
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    private function createStockAndSourceFixtures(
        int $sourcesCount,
        int $stocksCount,
    ): void {
        for ($i = 1; $i <= $sourcesCount; $i++) {
            $source = $this->sourceFactory->create();
            $source->setSourceCode('klevu_test_msistockidsprov_' . $i);
            $source->setName('Klevu Test: MSI Reservations StockIdsProvider ' . $i);
            $source->setEnabled(true);
            $source->setCountryId('GB');
            $source->setPostcode('AB' . $i . '23CD');
            try {
                $this->sourceRepository->save($source);
            } catch (AlreadyExistsException) {
                $source = $this->sourceRepository->get('klevu_test_msistockidsprov_' . $i);
            }

            $this->sources[$i] = $source;
        }

        for ($i = 1; $i <= $stocksCount; $i++) {
            $stock = $this->stockFactory->create();
            $stock->setName('Klevu Test: MSI Reservations StockIdsProvider ' . $i);
            try {
                $this->stockIds[$i] = $this->stockRepository->save($stock);
            } catch (CouldNotSaveException) {
                $this->searchCriteriaBuilder->addFilter(
                    field: Stock::NAME,
                    value: 'Klevu Test: MSI Reservations StockIdsProvider ' . $i,
                    conditionType: 'eq',
                );
                $stockResult = $this->stockRepository->getList(
                    searchCriteria: $this->searchCriteriaBuilder->create(),
                );

                foreach ($stockResult->getItems() as $stockItem) {
                    $this->stockIds[$i] = (int)$stockItem->getStockId();
                }
            }
        }
    }

    /**
     * @param string $sourceCode
     * @param int $stockId
     * @param int $priority
     *
     * @return void
     * @throws \Exception
     */
    private function createStockSourceLink(
        string $sourceCode,
        int $stockId,
        int $priority,
    ): void {
        /** @var StockSourceLinkInterface $stockSourceLink */
        $stockSourceLink = $this->stockSourceLinkFactory->create();
        $stockSourceLink->setSourceCode(
            sourceCode: $sourceCode,
        );
        $stockSourceLink->setStockId(
            stockId: $stockId,
        );
        $stockSourceLink->setPriority($priority);
        try {
            $this->stockSourceLinkResource->save($stockSourceLink);
        } catch (AlreadyExistsException) {
            // This is fine
        }
    }

    private function deleteStockAndSourceFixtures(): void
    {
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
}
