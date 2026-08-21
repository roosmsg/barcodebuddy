# Zooplus Nederland: product lookup by EAN

This document describes the Zooplus lookup provider in Barcode Buddy. The
endpoint was identified from the official Zooplus Android app and verified
against the live Dutch service on 2026-08-19.

> **Status:** unofficial and undocumented. Zooplus can change or remove the
> endpoint without notice. The provider therefore fails softly and is disabled
> by default.

## Endpoint

```text
GET https://www.zooplus.nl/hopps-search/api/v1/sites/7/nl/suggest
```

Query parameters used by Barcode Buddy:

| Parameter | Value | Purpose |
|---|---|---|
| `q` | EAN/UPC | Search value |
| `hl` | `0` | Disables highlighted markup in names |
| `u` | `barcodebuddy` | Anonymous client identifier |
| `include3p` | `false` | Excludes third-party marketplace products |

Authentication is not required. The request works with Barcode Buddy's normal
user agent and without cookies, API keys or Zooplus app headers.

## Response handling

The response contains result groups. Barcode Buddy selects the first child of
the group whose `type` is `product` and returns its `name`. Missing groups,
invalid JSON, endpoint errors and unknown EANs are treated as no match so that
the next configured lookup provider can run.

Only checksum-valid GTIN-8, UPC-A, EAN-13 and GTIN-14 values are sent to
Zooplus. This prevents internal Barcode Buddy commands and malformed values
from being forwarded.

## Verified examples

| EAN | Result |
|---|---|
| `4017721869355` | `animonda Integra Protect Renal Yummy Bits` |
| `4260735742675` | No Zooplus.nl product found |

## Product details: prices and ingredients

The suggest endpoint does **not** return prices or ingredients. Its product
result contains only `name`, `path`, `picture`, `product_id`,
`shop_identifier` and `is_sponsored`. The returned `path` can be used for a
second anonymous request to the Dutch product page:

```text
EAN
  -> GET /hopps-search/api/v1/sites/7/nl/suggest
  -> results[type=product].children[0].path
  -> GET https://www.zooplus.nl{path}
  -> parse <script id="__NEXT_DATA__" type="application/json">
```

The decoded product object is located at:

```text
props.pageProps.pageLevelProps.productDetails.product
```

Relevant fields observed in the live response:

| JSON path below `product` | Contents |
|---|---|
| `ingredientsText` | Composition and additives as an HTML string |
| `priceAggregate.minArticlePriceRaw` | Lowest variant price as a number |
| `priceAggregate.maxArticlePriceRaw` | Highest variant price as a number |
| `priceAggregate.currency` | Currency, for example `EUR` |
| `articleVariants[].articleConstituents[]` | Structured analytical values with `ingredientName`, `amount` and `unit` |
| `articleVariants[].offers[].price.currentPrice.value` | Current price for the offer/variant |
| `articleVariants[].offers[].price.referencePrice.value` | Previous or reference price when present |
| `articleVariants[].offers[].price.discounts[]` | Subscription or promotional discounts |
| `articleVariants[].offers[].unit.unitPriceRaw` | Numeric comparison price per unit |
| `articleVariants[].offers[].unit.unitPriceLabel` | Formatted comparison price, for example `€ 26,58 / kg` |

For EAN `4017721869355`, verified on 2026-08-19, the page returned the
following data for the 120 g variant:

| Field | Value |
|---|---|
| Current price | `€ 3,19` |
| Unit price | `€ 26,58 / kg` |
| Composition | Potato (dried), peas, greaves meal, potato starch, poultry fat, beef tallow, dried poultry protein, beet pulp, lignocellulose, poultry liver, salmon oil, minerals and Yucca schidigera |
| Analytical constituents | Protein 26%, fat 24.5%, fibre 2%, ash 4%, calcium 0.65%, phosphorus 0.45%, moisture 6%, potassium 0.5%, sodium 0.35% |

Products can contain multiple variants and offers. Price extraction must select
the variant matching the scanned GTIN/SKU instead of assuming that the first
variant is correct. Regular, set, promotional and subscription prices are
separate values; Barcode Buddy should store the normal current price unless a
different policy is explicitly configured.

The tested `/_next/data/{buildId}/...json` URL returned HTTP `404`, so the
embedded `__NEXT_DATA__` currently has to be read from the product HTML. This
is less stable than the suggest lookup and must remain an optional, fail-soft
enrichment step.

## Barcode Buddy integration

- Provider class: `incl/lookupProviders/ProviderZooplus.php`
- Provider ID: `10`
- Configuration key: `LOOKUP_USE_ZOOPLUS`
- Default state: disabled
- API key: not applicable

Enable **Zooplus Nederland** under Barcode Lookup settings and drag it to the
preferred position in the provider order. Existing installations receive the
provider and order entry through the `1.8.1.9` database upgrade.

## Operational notes

- Use this endpoint conservatively and keep normal lookup fallback enabled.
- Do not depend on fields other than the product name without additional
  validation; the schema has no public compatibility guarantee.
- Strip or sanitize HTML from `ingredientsText` before storing or displaying it.
- Prices are market-, variant- and time-specific. Cache them for a much shorter
  period than product names or ingredients.
- Ingredients and analytical constituents are normally absent for non-food
  products and may also be missing from some pet-food pages.
- This is a search endpoint rather than a published product-data API. A result
  is based on Zooplus search ranking, and not-found does not prove that the EAN
  does not exist elsewhere.
