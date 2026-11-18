<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewPrunerInterface;
use Hryvinskyi\CatalogSortingMostViewed\Model\ResourceModel\MostViewedMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Intl\DateTimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A period of N days counts the views of today and the N - 1 UTC days before it. Before the select is built, the
 * counts of the days before that first day are deleted, so the counter keeps no more than the period. The first
 * day is quoted into the condition, so the select carries no placeholder and no bind for the refresh to carry over.
 *
 * What the whole query sums is checked against real counts by the integration test.
 */
class MostViewedMetricSourceTest extends TestCase
{
    /**
     * 2025-11-18 23:59:59 UTC, one second before the UTC day changes
     */
    private const NOW = 1763510399;

    /**
     * @var MostViewedConfigInterface&MockObject
     */
    private $config;

    /**
     * @var ProductViewPrunerInterface&MockObject
     */
    private $pruner;

    /**
     * @var MostViewedMetricSource
     */
    private MostViewedMetricSource $source;

    /**
     * @var list<string> What touched the database, in order: `prune` or `select`
     */
    private array $events = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $connection = $this->createMock(Mysql::class);
        $renderer = $this->createMock(SelectRenderer::class);
        $connection->method('select')->willReturnCallback(function () use ($connection, $renderer): Select {
            $this->events[] = 'select';

            return new Select($connection, $renderer);
        });
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace(
                '?',
                "'" . (is_string($value) ? $value : '') . "'",
                $text
            )
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->config = $this->createMock(MostViewedConfigInterface::class);
        $this->pruner = $this->createMock(ProductViewPrunerInterface::class);
        $dateTimeFactory = $this->createMock(DateTimeFactory::class);
        $dateTimeFactory->method('create')->willReturnCallback(
            static fn (): \DateTime => new \DateTime('@' . self::NOW)
        );

        $this->source = new MostViewedMetricSource($resource, $this->config, $this->pruner, $dateTimeFactory);
    }

    /**
     * @param int $days Period in days
     * @param string $firstDay First UTC day counted
     * @return void
     */
    #[DataProvider('periods')]
    public function testCountsTheViewsFromTheFirstDayOfThePeriodToToday(int $days, string $firstDay): void
    {
        $this->config->method('getPeriodDays')->willReturn($days);

        $conditions = $this->conditions($this->source->select());

        self::assertStringContainsString("v.viewed_on >= '" . $firstDay . "'", $conditions);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function periods(): array
    {
        return [
            'today only' => [1, '2025-11-18'],
            'two days' => [2, '2025-11-17'],
            'the default 90 days' => [90, '2025-08-21'],
        ];
    }

    /**
     * @return void
     */
    public function testDeletesTheCountsBeforeThePeriodBeforeBuildingTheSelect(): void
    {
        $this->config->method('getPeriodDays')->willReturn(90);
        $this->pruner->expects(self::once())
            ->method('deleteBefore')
            ->with('2025-08-21')
            ->willReturnCallback(function (): int {
                $this->events[] = 'prune';

                return 3;
            });

        $this->source->select();

        self::assertSame(['prune', 'select'], $this->events);
    }

    /**
     * @return void
     */
    public function testLeavesOutTheAdminStore(): void
    {
        $this->config->method('getPeriodDays')->willReturn(90);

        self::assertStringContainsString('v.store_id > 0', $this->conditions($this->source->select()));
    }

    /**
     * The refresh wraps the select in a statement with a named bind of its own, so no positional placeholder may
     * be left in it
     *
     * @return void
     */
    public function testTheFirstDayIsQuotedIntoItsCondition(): void
    {
        $this->config->method('getPeriodDays')->willReturn(30);

        $select = $this->source->select();

        self::assertStringNotContainsString('?', $this->conditions($select));
        self::assertSame([], $select->getBind());
    }

    /**
     * Get the select's WHERE conditions as one string
     *
     * @param Select $select The metric select
     * @return string
     */
    private function conditions(Select $select): string
    {
        $where = $select->getPart(Select::WHERE);
        self::assertIsArray($where);

        return implode(' ', array_map(static fn (mixed $part): string => is_string($part) ? $part : '', $where));
    }
}
