<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Model;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the Most Viewed settings: the Yes/No setting per store, the period at default scope
 */
class MostViewedConfig implements MostViewedConfigInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig Reads the settings
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabledIn(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getPeriodDays(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PERIOD, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value) || (int)$value < 1) {
            return self::DEFAULT_PERIOD_DAYS;
        }

        return (int)$value;
    }
}
