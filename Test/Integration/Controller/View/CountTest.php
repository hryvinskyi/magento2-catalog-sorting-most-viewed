<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Integration\Controller\View;

use Magento\Customer\Model\Visitor;
use Magento\Customer\Observer\Visitor\InitByRequestObserver;
use Magento\Customer\Observer\Visitor\SaveByRequestObserver;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestSafetyInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The view endpoint through the real front controller, routing and database: a POST of `product=<id>` without a
 * form key answers an empty 204 no cache may keep, and where Most Viewed is enabled in the store it counts the
 * view on today's row of that product and store, one more per POST. Where Most Viewed is off, or the id names no
 * product, the answer is the same and nothing is written. The request records no visitor: a beacon is not a page
 * the shopper opened, and recording one would add a visitor write to every product view.
 */
#[AppArea('frontend'), DbIsolation(true)]
class CountTest extends AbstractController
{
    private const URI = 'hryvinskyi_sorting/view/count';
    private const VIEW_TABLE = 'hryvinskyi_catalog_sorting_product_view';
    private const SKU_PREFIX = 'hryvinskyi-most-viewed-count-test-';

    /**
     * @return void
     */
    #[Config('catalog_sorting/most_viewed/enabled', '1', 'store', 'default')]
    public function testEachPostCountsOneMoreViewOfTheProductTodayInTheStore(): void
    {
        $productId = $this->createProduct();

        $this->postView((string)$productId);
        $this->assertNoContent();
        self::assertSame([$this->storeId() => 1], $this->todaysViews($productId));

        $this->startANewRequest();
        $this->postView((string)$productId);
        $this->assertNoContent();
        self::assertSame([$this->storeId() => 2], $this->todaysViews($productId));
    }

    /**
     * @return void
     */
    #[Config('catalog_sorting/most_viewed/enabled', '0', 'store', 'default')]
    public function testNothingIsCountedWhereMostViewedIsOff(): void
    {
        $productId = $this->createProduct();

        $this->postView((string)$productId);

        $this->assertNoContent();
        self::assertSame([], $this->todaysViews($productId));
    }

    /**
     * @return void
     */
    #[Config('catalog_sorting/most_viewed/enabled', '1', 'store', 'default')]
    public function testAnIdThatNamesNoProductCountsNothingAndStillAnswers204(): void
    {
        $missingId = $this->largestProductId() + 1000;

        $this->postView((string)$missingId);

        $this->assertNoContent();
        self::assertSame([], $this->todaysViews($missingId));
    }

    /**
     * @return void
     */
    #[Config('catalog_sorting/most_viewed/enabled', '1', 'store', 'default')]
    public function testAMalformedProductCountsNothing(): void
    {
        $productId = $this->createProduct();

        $this->postView($productId . 'x');

        $this->assertNoContent();
        self::assertSame([], $this->todaysViews($productId));
    }

    /**
     * @return void
     */
    #[Config('catalog_sorting/most_viewed/enabled', '1', 'store', 'default')]
    #[AppIsolation(true)]
    public function testABeaconWritesNoVisitorRecord(): void
    {
        $this->letTheVisitorSeeTheDispatchedRequest();
        $productId = $this->createProduct();
        $visitorsBefore = $this->visitorCount();

        $this->postView((string)$productId);

        $this->assertNoContent();
        self::assertSame([$this->storeId() => 1], $this->todaysViews($productId));
        self::assertSame($visitorsBefore, $this->visitorCount(), 'the beacon request records no visitor');
    }

    /**
     * Give the visitor's request-safety check the request the test dispatches
     *
     * The application serves both request contracts from one request object, so the visitor reads the route of the
     * request being dispatched. The test framework serves the request contract from its own request class and
     * leaves the request-safety contract on another instance that never sees a route, so it is bound to the test
     * request here, and the visitor and its request observers are rebuilt with it.
     *
     * @return void
     */
    private function letTheVisitorSeeTheDispatchedRequest(): void
    {
        $objectManager = $this->_objectManager;
        $objectManager->configure(['preferences' => [RequestSafetyInterface::class => TestRequest::class]]);
        foreach ([
            RequestSafetyInterface::class,
            Visitor::class,
            InitByRequestObserver::class,
            SaveByRequestObserver::class,
        ] as $type) {
            $objectManager->removeSharedInstance($type);
        }
        self::assertSame($this->getRequest(), $objectManager->get(RequestSafetyInterface::class));
    }

    /**
     * Count the stored visitor records
     *
     * @return int
     */
    private function visitorCount(): int
    {
        $connection = $this->connection();
        $count = $connection->fetchOne(
            $connection->select()->from($this->resource()->getTableName('customer_visitor'), ['COUNT(*)'])
        );

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * POST the beacon body to the endpoint, without a form key
     *
     * @param string $product The `product` value
     * @return void
     */
    private function postView(string $product): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(HttpRequest::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue(['product' => $product]);
        $this->dispatch(self::URI);
    }

    /**
     * Drop the request and the response of the previous dispatch, as a second beacon arrives in a request of its own
     *
     * @return void
     */
    private function startANewRequest(): void
    {
        $this->resetRequest();
        $this->_objectManager->removeSharedInstance(ResponseInterface::class);
        $response = $this->_objectManager->get(ResponseInterface::class);
        self::assertInstanceOf(ResponseInterface::class, $response);
        $this->_response = $response;
    }

    /**
     * Assert the answer is an empty 204 that no cache keeps
     *
     * @return void
     */
    private function assertNoContent(): void
    {
        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\Framework\App\Response\Http::class, $response);
        self::assertSame(204, $response->getHttpResponseCode());
        self::assertSame('', (string)$response->getBody());
        $this->assertHeaderPcre('Cache-Control', '/^no-store$/');
    }

    /**
     * Get today's view counts of a product per store
     *
     * @param int $productId Product id
     * @return array<int, int> Store id => views
     */
    private function todaysViews(int $productId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(self::VIEW_TABLE), ['store_id', 'views'])
                ->where('product_id = ?', $productId)
                ->where('viewed_on = UTC_DATE()')
        );

        $views = [];
        foreach ($rows as $storeId => $count) {
            $views[(int)$storeId] = is_numeric($count) ? (int)$count : 0;
        }

        return $views;
    }

    /**
     * Create a bare product row
     *
     * @return int Product id
     */
    private function createProduct(): int
    {
        $connection = $this->connection();
        $attributeSetId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('eav_entity_type'), ['default_attribute_set_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        $sku = uniqid(self::SKU_PREFIX);
        $connection->insert($this->table('catalog_product_entity'), [
            'attribute_set_id' => is_numeric($attributeSetId) ? (int)$attributeSetId : 4,
            'type_id' => 'simple',
            'sku' => $sku,
        ]);
        $productId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', $sku)
        );
        self::assertIsNumeric($productId);

        return (int)$productId;
    }

    /**
     * Get the largest product id in the catalogue
     *
     * @return int
     */
    private function largestProductId(): int
    {
        $connection = $this->connection();
        $id = $connection->fetchOne(
            $connection->select()->from($this->table('catalog_product_entity'), ['MAX(entity_id)'])
        );

        return is_numeric($id) ? (int)$id : 0;
    }

    /**
     * Get the id of the default store view
     *
     * @return int
     */
    private function storeId(): int
    {
        $storeManager = $this->_objectManager->get(StoreManagerInterface::class);
        self::assertInstanceOf(StoreManagerInterface::class, $storeManager);

        return (int)$storeManager->getStore('default')->getId();
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource()->getTableName($name);
    }

    /**
     * Get the default database connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource()->getConnection();
    }

    /**
     * Get the resource connection of the test application
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);

        return $resource;
    }
}
