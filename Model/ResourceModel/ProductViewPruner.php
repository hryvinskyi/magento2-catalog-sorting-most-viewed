<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewPrunerInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Deletes the view counts of the days before a day, in one statement read through the index on the day
 */
class ProductViewPruner implements ProductViewPrunerInterface
{
    private const VIEW_TABLE = 'hryvinskyi_catalog_sorting_product_view';

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table name
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deleteBefore(string $firstKeptDay): int
    {
        return $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName(self::VIEW_TABLE),
            ['viewed_on < ?' => $firstKeptDay]
        );
    }
}
