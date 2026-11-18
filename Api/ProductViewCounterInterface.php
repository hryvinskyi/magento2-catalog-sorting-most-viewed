<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Api;

/**
 * Counts one view of a product page in a store, on the current UTC day
 */
interface ProductViewCounterInterface
{
    /**
     * Add one view of a product's page in a store to today's count
     *
     * A product id that names no product counts nothing and is not an error: the id comes from an anonymous
     * request anybody can forge.
     *
     * @param int $productId Product id, above zero
     * @param int $storeId Store view id the page was viewed in
     * @return void
     */
    public function count(int $productId, int $storeId): void;
}
