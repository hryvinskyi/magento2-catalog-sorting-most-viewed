# Magento 2 Catalog Sorting: Most Viewed

Adds **Most Viewed** to the storefront Sort By list: the products whose pages were viewed most within a period first, per store view. Views are counted in the visitor's browser, so full-page-cached pages count too.

## What it does for a store

- **A Most Viewed sort order** (`product_list_order=most_viewed`), offered in the store views where it is enabled. A listing sorted by it opens most-viewed first when the shopper chose no direction.
- **Views counted by a browser beacon.** Where the method is enabled, a product page carries a hidden element with the counting URL and the product id, and a deferred script that posts `product=<id>` once per page load to `POST hryvinskyi_sorting/view/count` (`navigator.sendBeacon`, else `fetch`). The cached page holds nothing about the visitor. Where the method is off, the page prints nothing and sends nothing.
- **One row per product, store and day.** Each view adds one to the product's count of the current UTC day in the table `hryvinskyi_catalog_sorting_product_view`, in one statement. The beacon request writes no visitor record.
- **Computed once a day, not per request.** The popularity data refresh of `hryvinskyi/magento2-catalog-sorting-metrics` sums each product's views over the period, today included, and deletes the days before the period first, so the table never holds more than the period. Only products whose value changed get their search documents rebuilt.
- **Sorted in the search engine** on the numeric document field `sort_most_viewed`; no join and no computation per query. A product without views in the period sorts as 0.

## Requirements

- PHP 8.1 to 8.5.
- `hryvinskyi/magento2-catalog-sorting`, `hryvinskyi/magento2-catalog-sorting-options`, `hryvinskyi/magento2-catalog-sorting-metrics` and `hryvinskyi/magento2-theme-assets`, ^1.0 each.
- The platform's Catalog, Customer, Store, Config and Backend modules (what `composer.json` and `etc/module.xml` declare).

## Installation

```bash
composer require hryvinskyi/magento2-catalog-sorting-most-viewed
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento indexer:reindex catalogsearch_fulltext
```

The module adds the field `sort_most_viewed` to the product search documents. One full `catalogsearch_fulltext` reindex puts it into the index mapping; listings keep working before it. Views are counted from the moment the method is enabled in a store view; rankings follow at each refresh, or at once with `bin/magento catalog-sorting:metrics:refresh --metric=most_viewed`.

## Configuration

**Stores › Configuration › Catalog › Catalog Sorting › Most Viewed**

| Path | Label | Default | Scope |
|---|---|---|---|
| `catalog_sorting/most_viewed/enabled` | Enabled (switches on both the counting and the option) | No | default, website, store view |
| `catalog_sorting/most_viewed/period` | Period, Days (blank or 0 counts 90 days) | 90 | default |

The label, the place and the visibility of the option in Sort By are set in **Catalog Sorting › Sort Options**. The refresh schedule is in **Catalog Sorting › Popularity Data Refresh**.

## The counting endpoint

`POST hryvinskyi_sorting/view/count` (route `hryvinskyi_sorting`, storefront) takes a form-encoded `product`, a positive whole number. It always answers an empty 204 with `Cache-Control: no-store`, counted or not. It counts nothing where the method is off in the store, for a malformed id, or for an id that names no product.

The endpoint is exempt from the form-key check, because cached pages carry no form key. It reads no customer data and answers nothing, but it counts every well-formed post: any client can post views of any product without opening its page, and nothing limits how many one client posts. The count is a popularity signal that can be inflated this way. A reload counts again; views are not de-duplicated per visitor.

While the method is on, every product page view costs one database write.

## Registrations

| Pool | Item |
|---|---|
| `metrics` of `Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry` | metric `most_viewed`, field `sort_most_viewed` (`long`) |
| `sortFields` of `Hryvinskyi\CatalogSorting\Model\Search\ShopperSortFields` | code `most_viewed` on `sort_most_viewed` |
| `options` of `Hryvinskyi\CatalogSortingOptions\Model\ListingSortOptions` | option `most_viewed`, "Most Viewed", descending, `sortOrder` 110 |

The beacon is the block `hryvinskyi.catalog.sorting.most.viewed.beacon` in the container `before.body.end` of the layout handle `catalog_product_view`; its script `Hryvinskyi_CatalogSortingMostViewed::js/product-view-beacon.js` is rendered external and deferred through the theme-assets renderer.
