<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel\ProductViewCounter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\DB\Sql\ExpressionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A view is one insert-or-raise statement on the view counter table: a new row of the product, store and the
 * database's UTC day with a count of one, or that row's count raised by one. A row the foreign keys refuse, as a
 * forged product id is, counts nothing without an error; every other database error is thrown on.
 *
 * What the statement does to the stored counts is checked against the real database by the integration test.
 */
class ProductViewCounterTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var ProductViewCounter
     */
    private ProductViewCounter $counter;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name): string => 'pfx_' . $name);

        $expressionFactory = $this->createMock(ExpressionFactory::class);
        $expressionFactory->method('create')->willReturnCallback(
            static fn (array $data): Expression => new Expression(
                is_string($data['expression'] ?? null) ? $data['expression'] : ''
            )
        );

        $this->counter = new ProductViewCounter($resource, $expressionFactory);
    }

    /**
     * @return void
     */
    public function testInsertsADayCountOfOneOrRaisesItByOne(): void
    {
        $this->connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->willReturnCallback(static function (mixed $table, mixed $row, mixed $update): int {
                self::assertSame('pfx_hryvinskyi_catalog_sorting_product_view', $table);
                self::assertIsArray($row);
                self::assertSame(42, $row['product_id'] ?? null);
                self::assertSame(3, $row['store_id'] ?? null);
                self::assertSame(1, $row['views'] ?? null);
                self::assertInstanceOf(Expression::class, $row['viewed_on'] ?? null);
                self::assertSame('UTC_DATE()', (string)$row['viewed_on']);
                self::assertIsArray($update);
                self::assertSame(['views'], array_keys($update));
                self::assertInstanceOf(Expression::class, $update['views']);
                self::assertSame('views + 1', (string)$update['views']);

                return 1;
            });

        $this->counter->count(42, 3);
    }

    /**
     * @param int $errorNumber Driver error number of a missing referenced row
     * @return void
     */
    #[DataProvider('missingRowErrors')]
    public function testARowTheForeignKeysRefuseCountsNothingWithoutAnError(int $errorNumber): void
    {
        $this->connection->method('insertOnDuplicate')->willThrowException($this->statementError($errorNumber));

        $this->counter->count(999999, 3);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function missingRowErrors(): array
    {
        return ['MySQL 5.5 and later, MariaDB' => [1452], 'older servers' => [1216]];
    }

    /**
     * @return void
     */
    public function testAnyOtherDatabaseErrorIsThrownOn(): void
    {
        $error = $this->statementError(1213);
        $this->connection->method('insertOnDuplicate')->willThrowException($error);

        $this->expectExceptionObject($error);

        $this->counter->count(42, 3);
    }

    /**
     * @return void
     */
    public function testAStatementErrorWithoutADriverErrorIsThrownOn(): void
    {
        $error = new \Zend_Db_Statement_Exception('Statement failed');
        $this->connection->method('insertOnDuplicate')->willThrowException($error);

        $this->expectExceptionObject($error);

        $this->counter->count(42, 3);
    }

    /**
     * A statement error wrapping the driver error the database adapter reports
     *
     * @param int $errorNumber Driver error number
     * @return \Zend_Db_Statement_Exception
     */
    private function statementError(int $errorNumber): \Zend_Db_Statement_Exception
    {
        $driverError = new \PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $driverError->errorInfo = ['23000', $errorNumber, 'Integrity constraint violation'];

        return new \Zend_Db_Statement_Exception($driverError->getMessage(), 23000, $driverError);
    }
}
