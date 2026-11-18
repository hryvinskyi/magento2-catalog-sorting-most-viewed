<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Integration;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSorting\Api\Search\ShopperSortFieldsInterface;
use Hryvinskyi\CatalogSorting\Model\Index\DocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\RecordingSearchDocumentRefresher;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\ReplacesSearchEngineAndPageCache;
use Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel\MostViewedMetricSource;
use Hryvinskyi\CatalogSortingOptions\Api\ListingSortOptionsInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Most Viewed on the real wiring and the real database: the refresh stores, per store, the sum of a product's
 * daily view counts from the first day of the period to today, and deletes the counts of the days before the
 * period; the values reach the product search documents, a `most_viewed` sort is rendered on the document field,
 * and the listing option is offered where the store enables it.
 *
 * The refresh commits its own transaction, so the refresh scenario runs outside the test transaction and removes
 * the products, view counts, stored values and pending rows it created. Recording doubles stand in for the search
 * engine and the page cache, which the run neither needs nor may write to.
 */
#[AppArea('global')]
class MostViewedSortTest extends TestCase
{
    use ReplacesSearchEngineAndPageCache;

    private const CODE = 'most_viewed';
    private const FIELD = 'sort_most_viewed';
    private const DEFAULT_STORE = 'default';
    private const SECOND_STORE = 'hryvinskyi_most_viewed';
    private const VIEW_TABLE = 'hryvinskyi_catalog_sorting_product_view';
    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';
    private const PENDING_TABLE = 'hryvinskyi_catalog_sorting_metric_pending';
    private const SKU_PREFIX = 'hryvinskyi-most-viewed-test-';
    private const DAY = 86400;

    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $connection = $this->connection();
        $connection->delete($this->table(self::METRIC_TABLE), ['metric_code = ?' => self::CODE]);
        if ($this->productIds !== []) {
            $connection->delete($this->table(self::VIEW_TABLE), ['product_id IN (?)' => $this->productIds]);
            $connection->delete($this->table(self::PENDING_TABLE), ['product_id IN (?)' => $this->productIds]);
            $connection->delete($this->table('catalog_product_entity'), ['entity_id IN (?)' => $this->productIds]);
        }
        $this->productIds = [];
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        DbIsolation(false),
        DataFixture(StoreFixture::class, ['code' => self::SECOND_STORE], 'second_store'),
        Config('catalog_sorting/most_viewed/enabled', '1', 'store', self::DEFAULT_STORE),
        Config('catalog_sorting/most_viewed/enabled', '1', 'store', self::SECOND_STORE),
        Config('catalog_sorting/most_viewed/period', '30')
    ]
    public function testTheRefreshStoresTheViewsOfThePeriodPerStoreAndDeletesOlderDays(): void
    {
        $this->replaceTheSearchEngineAndThePageCache();
        $store = $this->storeId(self::DEFAULT_STORE);
        $secondStore = $this->storeId(self::SECOND_STORE);
        $popular = $this->createProduct('popular');
        $quiet = $this->createProduct('quiet');
        $forgotten = $this->createProduct('forgotten');

        $this->countViews($popular, $store, 0, 5);
        $this->countViews($popular, $store, 29, 3);
        $this->countViews($popular, $store, 30, 100);
        $this->countViews($quiet, $store, 1, 1);
        $this->countViews($popular, $secondStore, 2, 7);
        $this->countViews($forgotten, $secondStore, 45, 9);

        $changed = $this->refresh();

        // The popular and the quiet product: one product changed in two stores counts once, and views older than
        // the period store no value, so the forgotten product never changed
        self::assertSame([self::CODE => 2], $changed);
        self::assertSame([$popular => 8.0, $quiet => 1.0], $this->storedValues($store));
        self::assertSame([$popular => 7.0], $this->storedValues($secondStore));
        self::assertSame(
            [[$popular, $store, 3], [$popular, $store, 5], [$quiet, $store, 1], [$popular, $secondStore, 7]],
            $this->keptCounts()
        );
        self::assertSame([], array_diff([$popular, $quiet], $this->refresher()->takeProductIds()));
        self::assertSame([], $this->pendingIds());

        $fields = $this->service(DocumentFields::class)->getFields([$popular, $quiet], $store);
        self::assertEquals(8, $fields[$popular][self::FIELD] ?? null);
        self::assertEquals(1, $fields[$quiet][self::FIELD] ?? null);

        self::assertSame([self::CODE => 0], $this->refresh());
        self::assertSame([], $this->refresher()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testTheMetricIsRegisteredOnTheDocumentField(): void
    {
        $metric = $this->service(MetricRegistryInterface::class)->get(self::CODE);

        self::assertSame(self::FIELD, $metric->getDocumentField());
        self::assertSame(FieldType::Long, $metric->getFieldType());
        self::assertInstanceOf(MostViewedMetricSource::class, $metric->getSource());
        self::assertFalse($metric->isEnabledIn($this->storeId(self::DEFAULT_STORE)));
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    public function testAMostViewedSortIsRenderedOnTheDocumentFieldWithTheShoppersDirection(): void
    {
        $sorts = $this->service(ShopperSortFieldsInterface::class)->apply([[self::CODE => ['order' => 'asc']]]);

        self::assertSame(
            [[self::FIELD => ['order' => 'asc', 'missing' => 0, 'unmapped_type' => 'long']]],
            $sorts
        );
    }

    /**
     * @return void
     */
    #[
        AppIsolation(true),
        Config('catalog_sorting/most_viewed/enabled', '1', 'store', self::DEFAULT_STORE)
    ]
    public function testTheListingOptionIsOfferedDescendingWhereTheStoreEnablesIt(): void
    {
        $options = $this->service(ListingSortOptionsInterface::class);

        self::assertSame('Most Viewed', $options->offeredIn($this->storeId(self::DEFAULT_STORE))[self::CODE] ?? null);
        self::assertSame('desc', $options->defaultDirectionOf(self::CODE)?->value);
    }

    /**
     * Run the Most Viewed refresh through the real container
     *
     * @return array<string, int>
     */
    private function refresh(): array
    {
        return $this->service(MetricRefreshInterface::class)->refresh([self::CODE]);
    }

    /**
     * Store a day's view count of a product in a store
     *
     * @param int $productId Product id
     * @param int $storeId Store id
     * @param int $daysAgo UTC day, counted back from today
     * @param int $views Views of that day
     * @return void
     */
    private function countViews(int $productId, int $storeId, int $daysAgo, int $views): void
    {
        $this->connection()->insert($this->table(self::VIEW_TABLE), [
            'product_id' => $productId,
            'store_id' => $storeId,
            'viewed_on' => gmdate('Y-m-d', time() - $daysAgo * self::DAY),
            'views' => $views,
        ]);
    }

    /**
     * Create a bare product row
     *
     * @param string $name Name that makes the SKU unique
     * @return int Product id
     */
    private function createProduct(string $name): int
    {
        $connection = $this->connection();
        $attributeSetId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('eav_entity_type'), ['default_attribute_set_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        $sku = uniqid(self::SKU_PREFIX . $name . '-');
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
        $this->productIds[] = (int)$productId;

        return (int)$productId;
    }

    /**
     * Get the stored Most Viewed values of the test products in a store
     *
     * @param int $storeId Store id
     * @return array<int, float> Product id => value, in the order the products were created
     */
    private function storedValues(int $storeId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(self::METRIC_TABLE), ['product_id', 'value'])
                ->where('metric_code = ?', self::CODE)
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', $this->productIds)
                ->order('product_id ASC')
        );

        $values = [];
        foreach ($rows as $productId => $value) {
            $values[(int)$productId] = is_numeric($value) ? (float)$value : 0.0;
        }

        return $values;
    }

    /**
     * Get the day counts of the test products still in the counter
     *
     * @return list<array{0: int, 1: int, 2: int}> Product id, store id and views, by store, product and views
     */
    private function keptCounts(): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table(self::VIEW_TABLE), ['product_id', 'store_id', 'views'])
                ->where('product_id IN (?)', $this->productIds)
                ->order(['store_id ASC', 'product_id ASC', 'views ASC'])
        );

        $counts = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $counts[] = [
                is_numeric($row['product_id'] ?? null) ? (int)$row['product_id'] : 0,
                is_numeric($row['store_id'] ?? null) ? (int)$row['store_id'] : 0,
                is_numeric($row['views'] ?? null) ? (int)$row['views'] : 0,
            ];
        }

        return $counts;
    }

    /**
     * Get the pending product ids among the test products
     *
     * @return list<int>
     */
    private function pendingIds(): array
    {
        $connection = $this->connection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::PENDING_TABLE), ['product_id'])
                ->where('product_id IN (?)', $this->productIds)
        );

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids));
    }

    /**
     * Get the shared recording document refresher the refresh holds
     *
     * @return RecordingSearchDocumentRefresher
     */
    private function refresher(): RecordingSearchDocumentRefresher
    {
        return $this->service(RecordingSearchDocumentRefresher::class);
    }

    /**
     * Get the id of a store view by code
     *
     * @param string $code Store code
     * @return int
     */
    private function storeId(string $code): int
    {
        return (int)$this->service(StoreManagerInterface::class)->getStore($code)->getId();
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->service(ResourceConnection::class)->getTableName($name);
    }

    /**
     * Get the default database connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->service(ResourceConnection::class)->getConnection();
    }

    /**
     * Get a service from the test application's container
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function service(string $type): object
    {
        $service = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }
}
