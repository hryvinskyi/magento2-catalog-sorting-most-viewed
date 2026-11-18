# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-18

### Added
- The sort order **Most Viewed** (`most_viewed`): product page views per product and store within a period,
  most-viewed first by default.
- A product page view beacon and the endpoint `POST hryvinskyi_sorting/view/count`, which counts views per product,
  store and UTC day in the table `hryvinskyi_catalog_sorting_product_view` and writes no visitor record.
- The metric `most_viewed`, the sum of the period's views, written to the document field `sort_most_viewed`; days
  before the period are deleted at each refresh.
- Settings per store view (**Enabled**) and at default scope (**Period, Days**, 90 by default).
