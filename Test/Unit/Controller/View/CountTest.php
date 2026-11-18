<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Controller\View;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewCounterInterface;
use Hryvinskyi\CatalogSortingMostViewed\Controller\View\Count;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The view endpoint counts one view of the posted product in the current store, only where Most Viewed is enabled
 * and only for a product that is a positive whole number; whatever it does, it answers an empty 204 no cache may
 * keep, and it never asks for a form key.
 */
class CountTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * @var HttpRequest&MockObject
     */
    private $request;

    /**
     * @var MostViewedConfigInterface&MockObject
     */
    private $config;

    /**
     * @var ProductViewCounterInterface&MockObject
     */
    private $counter;

    /**
     * @var Count
     */
    private Count $action;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->config = $this->createMock(MostViewedConfigInterface::class);
        $this->counter = $this->createMock(ProductViewCounterInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn((string)self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturnCallback(static fn (): Raw => new Raw());

        $this->action = new Count($this->request, $storeManager, $this->config, $this->counter, $rawFactory);
    }

    /**
     * @return void
     */
    public function testCountsOneViewOfThePostedProductInTheCurrentStore(): void
    {
        $this->post('42');
        $this->config->method('isEnabledIn')->with(self::STORE_ID)->willReturn(true);
        $this->counter->expects(self::once())->method('count')->with(42, self::STORE_ID);

        $this->assertNoContent($this->action->execute());
    }

    /**
     * @return void
     */
    public function testCountsNothingWhereMostViewedIsNotEnabled(): void
    {
        $this->post('42');
        $this->config->method('isEnabledIn')->with(self::STORE_ID)->willReturn(false);
        $this->counter->expects(self::never())->method('count');

        $this->assertNoContent($this->action->execute());
    }

    /**
     * @param mixed $posted The posted product value
     * @return void
     */
    #[DataProvider('malformedProducts')]
    public function testCountsNothingForAProductThatIsNotAPositiveWholeNumber(mixed $posted): void
    {
        $this->post($posted);
        $this->config->method('isEnabledIn')->willReturn(true);
        $this->counter->expects(self::never())->method('count');

        $this->assertNoContent($this->action->execute());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedProducts(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-7'],
            'a fraction' => ['4.5'],
            'a leading zero' => ['042'],
            'with text' => ['42abc'],
            'with a space' => [' 42'],
            'a list' => [['42']],
            'beyond the largest id' => ['4294967296'],
        ];
    }

    /**
     * @return void
     */
    public function testTheLargestIdIsCounted(): void
    {
        $this->post('4294967295');
        $this->config->method('isEnabledIn')->willReturn(true);
        $this->counter->expects(self::once())->method('count')->with(4294967295, self::STORE_ID);

        $this->assertNoContent($this->action->execute());
    }

    /**
     * @return void
     */
    public function testNeverAsksForAFormKey(): void
    {
        self::assertTrue($this->action->validateForCsrf($this->request));
        self::assertNull($this->action->createCsrfValidationException($this->request));
    }

    /**
     * Make the request carry a form body value for `product`
     *
     * @param mixed $value
     * @return void
     */
    private function post(mixed $value): void
    {
        $this->request->method('getPostValue')->willReturnCallback(
            static fn (mixed $name = null): mixed => $name === 'product' ? $value : null
        );
    }

    /**
     * Assert the answer is an empty 204 that no cache keeps
     *
     * @param Raw $result
     * @return void
     */
    private function assertNoContent(Raw $result): void
    {
        $response = new HttpResponse(
            $this->createMock(HttpRequest::class),
            $this->createMock(CookieManagerInterface::class),
            $this->createMock(CookieMetadataFactory::class),
            $this->createMock(HttpContext::class),
            $this->createMock(DateTime::class),
            $this->createMock(SessionConfig::class)
        );

        $result->renderResult($response);

        self::assertSame(204, $response->getHttpResponseCode());
        self::assertSame('', $response->getBody());
        $cacheControl = $response->getHeader('Cache-Control');
        self::assertInstanceOf(HeaderInterface::class, $cacheControl);
        self::assertSame('no-store', $cacheControl->getFieldValue());
    }
}
