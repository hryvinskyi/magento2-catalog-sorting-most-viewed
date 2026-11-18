<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Controller\View;

use Hryvinskyi\CatalogSortingMostViewed\Api\MostViewedConfigInterface;
use Hryvinskyi\CatalogSortingMostViewed\Api\ProductViewCounterInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Counts one view of a product page, sent by the page's view beacon
 *
 * The beacon posts `product=<id>` as a form-encoded body. The answer is always an empty 204 that no cache may keep,
 * whether a view was counted or not: when the method is not enabled in the current store, or the product is not a
 * positive whole number, nothing is written. A product id that names no product is left to the counter, which
 * counts nothing for it.
 *
 * The action is exempt from the form-key check: the beacon is sent from full-page-cached pages that carry no form
 * key. It reads no customer data and answers nothing a caller could learn from, but it counts every well-formed
 * post: any client can post views of any product without opening its page, and nothing limits how many one client
 * posts. The count is a popularity signal that can be inflated this way, and the Most Viewed order follows it.
 */
class Count implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const PRODUCT_PARAM = 'product';
    private const NO_CONTENT = 204;
    private const POSITIVE_WHOLE_NUMBER = '/^[1-9][0-9]{0,9}$/';
    private const MAX_PRODUCT_ID = 4294967295;

    /**
     * @param HttpRequest $request The beacon request; the request contract does not expose the form body
     * @param StoreManagerInterface $storeManager Tells the store the page was viewed in
     * @param MostViewedConfigInterface $config Tells whether views are counted in the store
     * @param ProductViewCounterInterface $counter Counts the view
     * @param RawFactory $rawFactory Creates the empty answer
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly MostViewedConfigInterface $config,
        private readonly ProductViewCounterInterface $counter,
        private readonly RawFactory $rawFactory
    ) {
    }

    /**
     * Count the view when the store counts views and the product id is well formed, then answer 204
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $productId = $this->productId();
        if ($productId !== null && $this->config->isEnabledIn($storeId)) {
            $this->counter->count($productId, $storeId);
        }

        return $this->noContent();
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Read the product id from the form body
     *
     * @return int|null The id, or null when the value is missing or not a positive whole number that fits an id
     */
    private function productId(): ?int
    {
        $value = $this->request->getPostValue(self::PRODUCT_PARAM);
        if (!is_string($value) || preg_match(self::POSITIVE_WHOLE_NUMBER, $value) !== 1) {
            return null;
        }

        $productId = (int)$value;

        return $productId <= self::MAX_PRODUCT_ID ? $productId : null;
    }

    /**
     * Create the empty answer no cache may keep
     *
     * @return Raw
     */
    private function noContent(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(self::NO_CONTENT);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setContents('');

        return $result;
    }
}
