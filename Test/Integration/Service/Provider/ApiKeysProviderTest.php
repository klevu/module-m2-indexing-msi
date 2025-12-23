<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingMsi\Test\Integration\Service\Provider;

use Klevu\Configuration\Service\Provider\ApiKeysProviderInterface as BaseApiKeysProviderInterface;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProvider;
use Klevu\IndexingMsi\Service\Provider\ApiKeysProviderInterface;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Klevu\TestFixtures\Traits\TestInterfacePreferenceTrait;
use Klevu\TestFixtures\Website\WebsiteFixturesPool;
use Klevu\TestFixtures\Website\WebsiteTrait;
use Magento\Framework\App\Config\Storage\Writer as ConfigWriter;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Validation\ValidationException;
use Magento\InventoryApi\Api\Data\StockInterfaceFactory;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Model\DeleteSalesChannelToStockLinkInterface;
use Magento\InventorySalesApi\Model\ReplaceSalesChannelsForStockInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers \Klevu\IndexingMsi\Service\Provider\ApiKeysProvider::class
 * @method ApiKeysProviderInterface instantiateTestObject(?array $arguments = null)
 * @method ApiKeysProviderInterface instantiateTestObjectFromInterface(?array $arguments = null)
 */
class ApiKeysProviderTest extends TestCase
{
    use ObjectInstantiationTrait;
    use StoreTrait;
    use TestImplementsInterfaceTrait;
    use TestInterfacePreferenceTrait;
    use WebsiteTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line
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
     * @var ConfigWriter|null
     */
    private ?ConfigWriter $configWriter = null; // @phpstan-ignore-line
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

        $this->implementationFqcn = ApiKeysProvider::class;
        $this->interfaceFqcn = ApiKeysProviderInterface::class;

        $this->objectManager = Bootstrap::getObjectManager();
        $this->websiteFixturesPool = $this->objectManager->get(WebsiteFixturesPool::class);
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);

        $this->stockFactory = $this->objectManager->get(StockInterfaceFactory::class);
        $this->stockRepository = $this->objectManager->get(StockRepositoryInterface::class);
        $this->salesChannelFactory = $this->objectManager->get(SalesChannelInterfaceFactory::class);
        $this->replaceSalesChannelsForStock = $this->objectManager->get(ReplaceSalesChannelsForStockInterface::class);
        $this->deleteSalesChannelToStockLink = $this->objectManager->get(DeleteSalesChannelToStockLinkInterface::class);
        $this->configWriter = $this->objectManager->get(ConfigWriter::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteStockFixtures();
        $this->storeFixturesPool->rollback();
        $this->websiteFixturesPool->rollback();
    }

    /**
     * @return mixed[][]
     */
    public static function dataProvider_testGetForStockIds(): array
    {
        return [
            [
                [],
                [],
            ],
            [
                [0],
                [
                    0 => [
                        'klevu-1234567890',
                        'klevu-1111111111',
                    ],
                ],
            ],
            [
                [1],
                [
                    1 => [
                        'klevu-9876543210',
                        'klevu-1234567890',
                    ],
                ],
            ],
            [
                [0, 1],
                [
                    0 => [
                        'klevu-1234567890',
                        'klevu-1111111111',
                    ],
                    1 => [
                        'klevu-9876543210',
                        'klevu-1234567890',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataProvider_testGetForStockIds
     *
     * @param int[] $stockIdKeys
     * @param string[] $expectedResult
     *
     * @return void
     * @throws CouldNotDeleteException
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     *
     * @group wip
     */
    public function testGetForStockIds(
        array $stockIdKeys,
        array $expectedResult,
    ): void {
        $this->createFixtures();
        $stockIds = array_map(
            callback: fn (int $stockIdKey): int => $this->stockIds[$stockIdKey],
            array: $stockIdKeys,
        );

        $apiKeysProvider = $this->instantiateTestObject();

        $mappedExpectedResult = [];
        foreach ($expectedResult as $stockIdKey => $apiKeys) {
            $mappedExpectedResult[$this->stockIds[$stockIdKey]] = $apiKeys;
        }

        $result = $apiKeysProvider->getForStockIds($stockIds);

        $this->assertSame(
            expected: $mappedExpectedResult,
            actual: $result,
        );
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    public function testGetForStockIds_AppliesCaching(): void
    {
        $this->createFixtures();

        $baseApiKeysProviderMock = $this->getMockBaseApiKeysProvider();
        $expectation = $this->exactly(3);
        $baseApiKeysProviderMock->expects($expectation)
            ->method('get')
            ->willReturnCallback(
                callback: static function (array $storeIds) use ($expectation): array { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter,Generic.Files.LineLength.TooLong
                    $invocationCount = match (true) {
                        method_exists($expectation, 'getInvocationCount') => $expectation->getInvocationCount(),
                        method_exists($expectation, 'numberOfInvocations') => $expectation->numberOfInvocations(),
                        default => throw new \RuntimeException('Cannot determine invocation count from matcher'),
                    };

                    switch ($invocationCount) {
                        case 1: // Stock 1 -> Website 1 -> Store 1
                            $return = [
                                'klevu-1234567890',
                            ];
                            break;

                        case 2: // Stock 1 -> Website 2 -> Store 3
                            $return = [
                                'klevu-9876543210',
                            ];
                            break;

                        case 3: // Stock 2 -> Website 3 -> Store 4 & 5
                            $return = [
                                'klevu-1111111111',
                                'klevu-2222222222',
                            ];
                            break;
                    }

                    return $return;
                },
            );

        $apiKeysProvider = $this->instantiateTestObject([
            'baseApiKeysProvider' => $baseApiKeysProviderMock,
        ]);

        $result1 = $apiKeysProvider->getForStockIds(
            stockIds: [$this->stockIds[0]],
        );
        $this->assertSame(
            expected: [
                $this->stockIds[0] => [
                    'klevu-1234567890',
                    'klevu-9876543210',
                ],
            ],
            actual: $result1,
            message: 'Result 1',
        );
        $result1_cached = $apiKeysProvider->getForStockIds(
            stockIds: [$this->stockIds[0]],
        );
        $this->assertSame(
            expected: $result1,
            actual: $result1_cached,
            message: 'Result 1 Cached',
        );

        $result2 = $apiKeysProvider->getForStockIds(
            stockIds: [$this->stockIds[1]],
        );
        $this->assertSame(
            expected: [
                $this->stockIds[1] => [
                    'klevu-1111111111',
                    'klevu-2222222222',
                ],
            ],
            actual: $result2,
            message: 'Result 2',
        );
        $result2_cached = $apiKeysProvider->getForStockIds(
            stockIds: [$this->stockIds[1]],
        );
        $this->assertSame(
            expected: $result2,
            actual: $result2_cached,
            message: 'Result 2 Cached',
        );

        $result3 = $apiKeysProvider->getForStockIds(
            stockIds: [
                $this->stockIds[0],
                $this->stockIds[1],
            ],
        );
        $this->assertSame(
            expected: [
                $this->stockIds[0] => [
                    'klevu-1234567890',
                    'klevu-9876543210',
                ],
                $this->stockIds[1] => [
                    'klevu-1111111111',
                    'klevu-2222222222',
                ],
            ],
            actual: $result3,
            message: 'Result 3',
        );
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    private function createFixtures(): void
    {
        $websiteCodeToId = $this->createWebsiteFixtures();
        $this->createStoreFixtures($websiteCodeToId);
        $this->createStockFixtures();
    }

    /**
     * @return array<string, int>
     * @throws \Exception
     */
    private function createWebsiteFixtures(): array
    {
        $this->createWebsite(
            websiteData: [
                'key' => 'klevu_test_msiapikeys_1',
                'code' => 'klevu_test_msiapikeys_1',
                'name' => 'Klevu Test: MSI Api Keys Provider (1)',
            ],
        );
        $websiteFixture1 = $this->websiteFixturesPool->get('klevu_test_msiapikeys_1');

        $this->createWebsite(
            websiteData: [
                'key' => 'klevu_test_msiapikeys_2',
                'code' => 'klevu_test_msiapikeys_2',
                'name' => 'Klevu Test: MSI Api Keys Provider (2)',
            ],
        );
        $websiteFixture2 = $this->websiteFixturesPool->get('klevu_test_msiapikeys_2');

        $this->createWebsite(
            websiteData: [
                'key' => 'klevu_test_msiapikeys_3',
                'code' => 'klevu_test_msiapikeys_3',
                'name' => 'Klevu Test: MSI Api Keys Provider (3)',
            ],
        );
        $websiteFixture3 = $this->websiteFixturesPool->get('klevu_test_msiapikeys_3');

        return [
            $websiteFixture1->getCode() => (int)$websiteFixture1->getId(),
            $websiteFixture2->getCode() => (int)$websiteFixture2->getId(),
            $websiteFixture3->getCode() => (int)$websiteFixture3->getId(),
        ];
    }

    /**
     * @param array<string, int> $websiteCodeToId
     *
     * @return void
     * @throws \Exception
     */
    private function createStoreFixtures(
        array $websiteCodeToId,
    ): void {
        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msiapikeys_1',
                'code' => 'klevu_test_msiapikeys_1',
                'name' => 'Klevu Test: MSI Api Keys Provider (1)',
                'website_id' => $websiteCodeToId['klevu_test_msiapikeys_1'],
                'is_active' => true,
            ],
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_test_msiapikeys_1',
        );
        $storeFixture1 = $this->storeFixturesPool->get('klevu_test_msiapikeys_1');
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $storeFixture1->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msiapikeys_2',
                'code' => 'klevu_test_msiapikeys_2',
                'name' => 'Klevu Test: MSI Api Keys Provider (2)',
                'website_id' => $websiteCodeToId['klevu_test_msiapikeys_1'],
                'is_active' => true,
            ],
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msiapikeys_3',
                'code' => 'klevu_test_msiapikeys_3',
                'name' => 'Klevu Test: MSI Api Keys Provider (3)',
                'website_id' => $websiteCodeToId['klevu_test_msiapikeys_2'],
                'is_active' => true,
            ],
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1111111111',
            storeCode: 'klevu_test_msiapikeys_3',
        );
        $storeFixture3 = $this->storeFixturesPool->get('klevu_test_msiapikeys_3');
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1111111111',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $storeFixture3->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msiapikeys_4',
                'code' => 'klevu_test_msiapikeys_4',
                'name' => 'Klevu Test: MSI Api Keys Provider (4)',
                'website_id' => $websiteCodeToId['klevu_test_msiapikeys_3'],
                'is_active' => true,
            ],
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-9876543210',
            storeCode: 'klevu_test_msiapikeys_4',
        );
        $storeFixture4 = $this->storeFixturesPool->get('klevu_test_msiapikeys_4');
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-9876543210',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $storeFixture4->getId(),
        );

        $this->createStore(
            storeData: [
                'key' => 'klevu_test_msiapikeys_5',
                'code' => 'klevu_test_msiapikeys_5',
                'name' => 'Klevu Test: MSI Api Keys Provider (5)',
                'website_id' => $websiteCodeToId['klevu_test_msiapikeys_3'],
                'is_active' => true,
            ],
        );
        ConfigFixture::setForStore(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-1234567890',
            storeCode: 'klevu_test_msiapikeys_5',
        );
        $storeFixture5 = $this->storeFixturesPool->get('klevu_test_msiapikeys_5');
        $this->configWriter->save(
            path: 'klevu_configuration/auth_keys/js_api_key',
            value: 'klevu-9876543210',
            scope: ScopeInterface::SCOPE_STORES,
            scopeId: $storeFixture5->getId(),
        );
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     * @throws CouldNotSaveException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    private function createStockFixtures(): void
    {
        $this->deleteStockFixtures();

        $stock1 = $this->stockFactory->create();
        $stock1->setName('Klevu Test: MSI Api Keys Provider (1)');
        $this->stockIds[] = $this->stockRepository->save($stock1);

        $salesChannel1 = $this->salesChannelFactory->create();
        $salesChannel1->setType('website');
        $salesChannel1->setCode('klevu_test_msiapikeys_1');
        $salesChannel2 = $this->salesChannelFactory->create();
        $salesChannel2->setType('website');
        $salesChannel2->setCode('klevu_test_msiapikeys_2');
        $this->replaceSalesChannelsForStock->execute(
            salesChannels: [
                $salesChannel1,
                $salesChannel2,
            ],
            stockId: $this->stockIds[0],
        );

        $stock2 = $this->stockFactory->create();
        $stock2->setName('Klevu Test: MSI Api Keys Provider (2)');
        $this->stockIds[] = $this->stockRepository->save($stock2);

        $salesChannel3 = $this->salesChannelFactory->create();
        $salesChannel3->setType('website');
        $salesChannel3->setCode('klevu_test_msiapikeys_3');
        $this->replaceSalesChannelsForStock->execute(
            salesChannels: [
                $salesChannel3,
            ],
            stockId: $this->stockIds[1],
        );
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    private function deleteStockFixtures(): void
    {
        $this->deleteSalesChannelToStockLink->execute(
            type: 'website',
            code: 'klevu_test_msiapikeys_1',
        );
        $this->deleteSalesChannelToStockLink->execute(
            type: 'website',
            code: 'klevu_test_msiapikeys_2',
        );
        $this->deleteSalesChannelToStockLink->execute(
            type: 'website',
            code: 'klevu_test_msiapikeys_3',
        );
        foreach ($this->stockIds as $stockId) {
            $this->stockRepository->deleteById($stockId);
        }
    }

    /**
     * @return MockObject&BaseApiKeysProviderInterface
     */
    private function getMockBaseApiKeysProvider(): MockObject
    {
        return $this->getMockBuilder(BaseApiKeysProviderInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
