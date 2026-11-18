<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewCounterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\ExpressionFactory;

/**
 * Adds one to a product's view count of the current UTC day in a store, in one statement
 *
 * The row is inserted with a count of one, or its count raised by one when the product already has a row for that
 * store and day. The day is the database's UTC date. A row the foreign keys refuse, as for a product id that names
 * no product, counts nothing and is not an error: the id comes from an anonymous request anybody can forge. Every
 * other database error is thrown on.
 */
class ProductViewCounter implements ProductViewCounterInterface
{
    private const VIEW_TABLE = 'hryvinskyi_catalog_sorting_product_view';

    /**
     * MySQL and MariaDB error numbers of a row whose foreign key names no existing row
     */
    private const MISSING_REFERENCED_ROW = [1216, 1452];

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table name
     * @param ExpressionFactory $expressionFactory Creates the day and the raised count of the statement
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ExpressionFactory $expressionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function count(int $productId, int $storeId): void
    {
        try {
            $this->resourceConnection->getConnection()->insertOnDuplicate(
                $this->resourceConnection->getTableName(self::VIEW_TABLE),
                [
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'viewed_on' => $this->expressionFactory->create(['expression' => 'UTC_DATE()']),
                    'views' => 1,
                ],
                ['views' => $this->expressionFactory->create(['expression' => 'views + 1'])]
            );
        } catch (\Zend_Db_Statement_Exception $exception) {
            if (!$this->namesAMissingRow($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Check whether the statement failed because a foreign key names a row that does not exist
     *
     * @param \Zend_Db_Statement_Exception $exception
     * @return bool
     */
    private function namesAMissingRow(\Zend_Db_Statement_Exception $exception): bool
    {
        $driverError = $exception->getPrevious();
        if (!$driverError instanceof \PDOException || !is_array($driverError->errorInfo)) {
            return false;
        }

        return in_array($driverError->errorInfo[1] ?? null, self::MISSING_REFERENCED_ROW, true);
    }
}
