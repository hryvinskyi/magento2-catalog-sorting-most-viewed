<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\ViewModel;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Hryvinskyi\CatalogSortingMostViewed\ViewModel\ProductViewBeacon;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A product page has a beacon only where Most Viewed is enabled in the page's store and the page shows a saved
 * product; the beacon is the store's view endpoint URL and the product id, and nothing else.
 */
class ProductViewBeaconTest extends TestCase
{
    private const STORE_ID = 4;
    private const ENDPOINT = 'https://shop.test/hryvinskyi_sorting/view/count/';

    /**
     * @var MostViewedConfigInterface&MockObject
     */
    private $config;

    /**
     * @var UrlInterface&MockObject
     */
    private $urlBuilder;

    /**
     * @var ProductViewBeacon
     */
    private ProductViewBeacon $viewModel;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(MostViewedConfigInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn((string)self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->viewModel = new ProductViewBeacon($this->config, $storeManager, $this->urlBuilder);
    }

    /**
     * @return void
     */
    public function testTheBeaconIsTheEndpointOfTheStoreAndTheProductId(): void
    {
        $this->config->method('isEnabledIn')->with(self::STORE_ID)->willReturn(true);
        $this->urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('hryvinskyi_sorting/view/count')
            ->willReturn(self::ENDPOINT);

        self::assertSame(
            ['url' => self::ENDPOINT, 'product' => 42],
            $this->viewModel->getBeacon($this->product('42'))
        );
    }

    /**
     * @return void
     */
    public function testNoBeaconWhereMostViewedIsNotEnabledInTheStore(): void
    {
        $this->config->method('isEnabledIn')->with(self::STORE_ID)->willReturn(false);
        $this->urlBuilder->expects(self::never())->method('getUrl');

        self::assertNull($this->viewModel->getBeacon($this->product('42')));
    }

    /**
     * @return void
     */
    public function testNoBeaconWithoutAProduct(): void
    {
        $this->config->method('isEnabledIn')->willReturn(true);

        self::assertNull($this->viewModel->getBeacon(null));
    }

    /**
     * @param mixed $id The product's id
     * @return void
     */
    #[DataProvider('unsavedIds')]
    public function testNoBeaconForAProductWithoutASavedId(mixed $id): void
    {
        $this->config->method('isEnabledIn')->willReturn(true);

        self::assertNull($this->viewModel->getBeacon($this->product($id)));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsavedIds(): array
    {
        return [
            'no id' => [null],
            'zero' => ['0'],
            'not a number' => ['new'],
        ];
    }

    /**
     * A product whose id is the given value
     *
     * @param mixed $id
     * @return ProductInterface
     */
    private function product(mixed $id): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
