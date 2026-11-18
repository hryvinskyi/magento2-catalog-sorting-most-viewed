<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewPrunerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Intl\DateTimeFactory;

/**
 * Computes the product page views of every product per store within the period, from the view counter
 *
 * A product's value in a store is the sum of its daily view counts in that store from the first day of the period
 * to today, both UTC days; a period of N days covers today and the N - 1 days before it. The counter never keeps
 * more than the period: before the select is built, the counts of the days before the period are deleted, so the
 * table holds at most one row per product, store and day of the period.
 *
 * Products that no longer exist and deleted stores have no counts, as their rows are deleted with them. The first
 * day is quoted into the condition; the select carries no bind of its own.
 */
class MostViewedMetricSource implements MetricSourceInterface
{
    private const VIEW_TABLE = 'hryvinskyi_catalog_sorting_product_view';
    private const VIEWS = 'SUM(v.views)';
    private const SECONDS_PER_DAY = 86400;
    private const DAY_FORMAT = 'Y-m-d';

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table name
     * @param MostViewedConfigInterface $config Period
     * @param ProductViewPrunerInterface $pruner Deletes the counts before the period
     * @param DateTimeFactory $dateTimeFactory Tells the current time
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MostViewedConfigInterface $config,
        private readonly ProductViewPrunerInterface $pruner,
        private readonly DateTimeFactory $dateTimeFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function select(): Select
    {
        $firstDay = $this->firstDayOfPeriod();
        $this->pruner->deleteBefore($firstDay);

        $select = $this->resourceConnection->getConnection()->select();
        $select->from(['v' => $this->resourceConnection->getTableName(self::VIEW_TABLE)], [
            'product_id' => 'v.product_id',
            'store_id' => 'v.store_id',
            'value' => self::VIEWS,
        ]);
        $select->where('v.viewed_on >= ?', $firstDay);
        $select->where('v.store_id > 0');
        $select->group(['v.product_id', 'v.store_id']);
        $select->having(self::VIEWS . ' > 0');

        return $select;
    }

    /**
     * Get the first UTC day of the period that ends today
     *
     * @return string `Y-m-d`
     */
    private function firstDayOfPeriod(): string
    {
        $now = $this->dateTimeFactory->create()->getTimestamp();

        return gmdate(self::DAY_FORMAT, $now - ($this->config->getPeriodDays() - 1) * self::SECONDS_PER_DAY);
    }
}
