<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Api;

/**
 * Deletes counted product page views of the days before a given day
 */
interface ProductViewPrunerInterface
{
    /**
     * Delete the view counts of every product and store for the days before a day
     *
     * @param string $firstKeptDay UTC day, `Y-m-d`, whose counts and later ones are kept
     * @return int Number of day counts deleted
     */
    public function deleteBefore(string $firstKeptDay): int;
}
