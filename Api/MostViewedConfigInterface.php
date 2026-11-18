<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Api;

/**
 * The settings of Most Viewed: where product page views are counted, and how many days of them rank a product
 *
 * Views are counted only in a store where the method is enabled. The period is read at default scope, because the
 * metric is computed for every store in one query, and it also bounds what the view counter keeps: the refresh
 * deletes the days before it.
 */
interface MostViewedConfigInterface
{
    public const XML_PATH_ENABLED = 'catalog_sorting/most_viewed/enabled';
    public const XML_PATH_PERIOD = 'catalog_sorting/most_viewed/period';
    public const DEFAULT_PERIOD_DAYS = 90;

    /**
     * Check whether Most Viewed is enabled in a store, which is also whether product page views are counted there
     *
     * @param int $storeId Store view id
     * @return bool
     */
    public function isEnabledIn(int $storeId): bool;

    /**
     * Get the number of days, ending today in UTC, whose product page views rank a product
     *
     * @return int One or more; a blank, zero, negative or non-numeric setting reads as {@see self::DEFAULT_PERIOD_DAYS}
     */
    public function getPeriodDays(): int;
}
