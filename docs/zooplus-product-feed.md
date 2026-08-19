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
- This is a search endpoint rather than a published product-data API. A result
  is based on Zooplus search ranking, and not-found does not prove that the EAN
  does not exist elsewhere.
