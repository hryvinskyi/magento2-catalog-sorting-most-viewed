<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMostViewed\Model\MostViewedConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Yes/No setting is read per store. The period is read at default scope and is a whole number of days, one or
 * more: a value that is blank, not a number, zero or below zero reads as the default 90 days, since the counter
 * keeps no more days than the period and a period without days would rank nothing.
 */
class MostViewedConfigTest extends TestCase
{
    /**
     * @return void
     */
    public function testReadsTheYesNoSettingOfTheStore(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with('catalog_sorting/most_viewed/enabled', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn(true);

        self::assertTrue((new MostViewedConfig($scopeConfig))->isEnabledIn(4));
    }

    /**
     * @param mixed $stored The stored setting
     * @param int $expected Days the setting is read as
     * @return void
     */
    #[DataProvider('periods')]
    public function testReadsThePeriodAtDefaultScope(mixed $stored, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('catalog_sorting/most_viewed/period', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->willReturn($stored);

        self::assertSame($expected, (new MostViewedConfig($scopeConfig))->getPeriodDays());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function periods(): array
    {
        return [
            'days' => ['30', 30],
            'one day' => ['1', 1],
            'surrounded by spaces' => [' 120 ', 120],
            'an integer' => [45, 45],
            'zero' => ['0', 90],
            'negative' => ['-5', 90],
            'blank' => ['', 90],
            'not a number' => ['three months', 90],
            'not set' => [null, 90],
        ];
    }
}
