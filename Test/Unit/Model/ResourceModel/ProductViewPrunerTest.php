<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel\ProductViewPruner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The counts of every product and store of the days strictly before the first kept day are deleted in one
 * statement on the view counter table; the first kept day itself stays.
 */
class ProductViewPrunerTest extends TestCase
{
    /**
     * @return void
     */
    public function testDeletesTheCountsOfTheDaysBeforeTheFirstKeptDayInOneStatement(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('delete')
            ->with('pfx_hryvinskyi_catalog_sorting_product_view', ['viewed_on < ?' => '2025-08-21'])
            ->willReturn(12);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name): string => 'pfx_' . $name);

        self::assertSame(12, (new ProductViewPruner($resource))->deleteBefore('2025-08-21'));
    }
}
