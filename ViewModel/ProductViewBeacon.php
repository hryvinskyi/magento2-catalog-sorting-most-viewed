<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\ViewModel;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds what a product page's view beacon needs: the endpoint that counts the view and the product's id
 *
 * The product page is full-page cached and shared by every visitor, so the beacon carries only the product id and
 * the store's endpoint URL, nothing about the visitor. Where Most Viewed is not enabled in the store there is no
 * beacon, and the page sends nothing.
 */
class ProductViewBeacon implements ArgumentInterface
{
    private const ENDPOINT_ROUTE = 'hryvinskyi_sorting/view/count';

    /**
     * @param MostViewedConfigInterface $config Tells whether views are counted in the store
     * @param StoreManagerInterface $storeManager Tells the store of the page
     * @param UrlInterface $urlBuilder Builds the endpoint URL of the store
     */
    public function __construct(
        private readonly MostViewedConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Get the beacon of a product's page
     *
     * @param ProductInterface|null $product The product the page shows
     * @return array{url: string, product: int}|null Endpoint URL and product id; null when the store does not count
     *         views or the page has no saved product
     */
    public function getBeacon(?ProductInterface $product): ?array
    {
        if (!$this->config->isEnabledIn((int)$this->storeManager->getStore()->getId())) {
            return null;
        }

        $productId = $product?->getId();
        if (!is_numeric($productId) || (int)$productId < 1) {
            return null;
        }

        return ['url' => $this->urlBuilder->getUrl(self::ENDPOINT_ROUTE), 'product' => (int)$productId];
    }
}
