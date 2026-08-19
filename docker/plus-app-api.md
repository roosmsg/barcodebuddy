# PLUS Supermarkt app API — endpoint specification

Reverse-engineered from the official PLUS Android app (`com.mobgen.plus`,
version 12.2.0, build 10119; decompiled with jadx). Documents the endpoints
needed to build product lookup, price and offer features in Grocy or Barcode
Buddy. Verified against the live API on 2026-08-19.

> **Status:** unofficial, undocumented, no stability guarantee. PLUS can change
> or version the paths at any time. Treat every field as optional/nullable and
> fail soft. There is a legitimate risk PLUS blocks non-app clients later
> (rate limiting, WAF, mandatory auth) — see *Operational notes*.

---

## 1. Basics

| | |
|---|---|
| **Base URL** | `https://apiframna.app.plus.nl/api/app/v1/` |
| **Auth** | **None.** No API key, no token, no cookies. All endpoints below answer anonymously. |
| **Method** | `GET` for everything documented here |
| **Response** | JSON (`Content-Type: application/json`) |
| **User-Agent** | App sends `com.mobgen.plus/12.2.0.10119 (Android; SDK 34; 14; nl-NL) Manufacturer/Model`. Any UA works, including `BarcodeBuddy v…`. |
| **Rate limit** | Not documented; unknown ceiling. Be conservative (see notes). |
| **Not found** | Unknown barcode → HTTP `404` with an HTML error body (not JSON). Handle 404 as "no match". |

`apiframna` = **app** + **Framna** (the agency, formerly Mobgen, that builds the
PLUS app — hence the `com.mobgen.plus` package id). The `resourceBaseUrl` is
hard-coded in `OneginiConfigModel.java` in the app.

### The `store_number` parameter — important

Most product/price endpoints take `store_number`.

- `store_number=0` → returns the product with **national list price**, but
  **no store-specific offers** (`is_discount` stays `false`, `discounts` is empty).
- `store_number=<real>` (e.g. `934`) → returns **live per-store pricing incl.
  active offers**. Get valid numbers from `GET /stores` (field `number`).

For a pure name+list-price lookup, `store_number=0` is enough. For offers you
must pass a real store number.

---

## 2. Product lookup by barcode

```
GET /products_by_barcode/{barcode}?store_number={n}
```

- `{barcode}` — EAN/GTIN as scanned, URL path segment.
- Optional query: `delivery_date` (ISO date; affects availability, not needed for lookup).

**Barcode Buddy already uses this** (see `incl/lookupProviders/ProviderPlusSupermarkt.php`):
product name from `brand_name` + `description`, generic name from the
`Wettelijke omschrijving` item.

### Response — core fields

| Field | Type | Meaning |
|---|---|---|
| `id` | string (UUID) | Internal product id |
| `key` | string | **SKU** (stable product number, e.g. `191311`). Links to offer/detail endpoints. |
| `description` | string | Product name, e.g. `Ananas schijven op sap` |
| `brand_name` | string | Brand, e.g. `Calvé`, `PLUS` |
| `image.url` | string | Contentful image URL (append `?w=400` for a size) |
| `shelf_item_consumer_package` | object | `net_content_value` (400), `net_content_uom` (`g`), `type_value` (`Bakje`) |
| `categories[]` | array | Category tree with `name`, `slug`, `key`, `ancestors[]` |
| `product_information.main_items[]` | array | Collapsible info sections; each has `items[]` of `{type,title,text}`. Legal name is the item with `title == "Wettelijke omschrijving"`. Also holds ingredients, allergens, nutrition, supplier contact. |
| `nutritional_information` | object | Structured nutrition table |
| `allowed_in_cart` / `max_order_limit` / `is_local_item` | bool/int/bool | Ordering constraints |

### Response — price fields

| Field | Type | Meaning |
|---|---|---|
| `price` | object | Current unit price: `{cent_amount, currency_code, price_type}`. `price_type` is `null` normally, **`"DISCOUNT"`** when on offer. |
| `base_unit` | object | Comparison price: `{text:"kilo", price:{cent_amount,…}}` (e.g. price per kilo/liter) |
| `old_prices[]` | array | The pre-offer price(s) `{cent_amount,…}`. **Empty when not on offer**, filled with the original price when discounted. |

Prices are integer cents. `349` = €3,49.

### Response — offer/promotion fields (only meaningful with a real `store_number`)

| Field | Type | Meaning |
|---|---|---|
| `is_discount` | bool | `true` when this product is currently on offer at that store |
| `discount_name` | string\|null | Offer title, e.g. `PLUS Blauwe bessen en Nederlandse aardbeien` |
| `cart_discount` | object\|null | Offer detail: `{key, description, valid_from, valid_until, offer_variant, is_multi_promo}`. `key` is the promotion id (see §4). |
| `valid_period_list[]` | string[] | Human text, e.g. `Geldig van woensdag 19 augustus tot en met dinsdag 25 augustus` |
| `campaign_id` | string\|null | Campaign reference where applicable |
| `store_availability` | object | `{store_number, is_on_stock}` |

### Worked example — product on offer

`GET /products_by_barcode/8710624817244?store_number=934` (PLUS Nederlandse Aardbeien):

```json
{
  "key": "255461",
  "description": "PLUS Nederlandse Aardbeien",
  "brand_name": "PLUS",
  "price":      { "cent_amount": 349, "currency_code": "EUR", "price_type": "DISCOUNT" },
  "old_prices": [ { "cent_amount": 499, "currency_code": "EUR", "price_type": null } ],
  "base_unit":  { "text": "kilo", "price": { "cent_amount": 1248, "currency_code": "EUR" } },
  "is_discount": true,
  "discount_name": "PLUS Blauwe bessen en Nederlandse aardbeien",
  "cart_discount": {
    "key": "4443-183",
    "description": "PLUS Blauwe bessen en Nederlandse aardbeien",
    "valid_from": "2026-08-19T00:00:00+00:00",
    "valid_until": "2026-08-25T00:00:00+00:00",
    "offer_variant": "Schaal 300-400 gram Per schaal",
    "is_multi_promo": true
  },
  "valid_period_list": [ "Geldig van woensdag 19 augustus tot en met dinsdag 25 augustus" ],
  "store_availability": { "store_number": 934, "is_on_stock": true }
}
```

The same barcode with `store_number=0` returns the product with `price_type:null`,
`old_prices:[]`, `is_discount:false`.

---

## 3. Stores

```
GET /stores
GET /stores/{id}
GET /stores/{id}/payment_methods
```

`GET /stores` returns all PLUS stores; each has `number` (the value to pass as
`store_number`), `name`, `city`, address/geo. Pick the user's home store once
and reuse its number.

---

## 4. Offers / promotions

Store-scoped. All take `?store_number={n}`.

```
GET /discounts?store_number={n}          # full folder, grouped by category
GET /discount_filters?store_number={n}
GET /discount_period_filters?store_number={n}
GET /multi_promo/{promotion_id}?store_number={n}   # one promotion, incl. its products
```

### `GET /discounts`

```json
{ "categories": [ { "name": "Ontbijtgranen, broodbeleg, tussendoor",
                    "items": [ { "type": "multi_promo", "title": "…",
                                 "price": {…}, "old_price": [ {…} ],
                                 "notice": { "text": "Gratis bezorging bij 2 stuks", "type": "DELIVERY" },
                                 "valid_period_list": [ "…" ],
                                 "url": "https://plus.nl/aanbiedingen/4443-186",
                                 "image": {…}, "key": "4443-186" } ] } ],
  "total": 165 }
```

- `total` = number of active promotions for that store.
- Each item's `key` (e.g. `4443-183`) is the **promotion id** → feed into `multi_promo/{id}`.
- Item `type` seen: `multi_promo`. `sku` present on single-product items.

### `GET /multi_promo/{id}`

Full promotion detail. Fields include: `discount_type`, `valid_from`,
`valid_until`, `offer_explanation[]`, `offer_example`, `offer_package`,
`offer_product`, `price`, `old_price[]`, and **`products[]`** — the list of
participating products, each with `sku`, `description`, `brand_name`, `price`,
`image`, `store_availability`. Note: promotion products carry **`sku`, not the
EAN** — to map a scanned barcode to a promotion, go the other way
(`products_by_barcode` → its `cart_discount.key`).

Example (`/multi_promo/4443-183`): `discount_type:"other"`,
`valid_from:"2026-08-19"`, `valid_until:"2026-08-25"`,
`offer_explanation:["Schaal 300-400 gram Per schaal"]`,
`price.cent_amount:349`, `old_price[0].cent_amount:499`.

### Related

```
GET /campaigns?store_number={n}          # savings/stamp campaigns
GET /campaigns/{id}
GET /folders                             # digital leaflet
```

---

## 5. Text search & recommendations

```
GET /search_suggestions?...              # autocomplete terms
GET /products?...&store_number={n}       # product search — REQUIRES store_number > 0
GET /products/{sku}/recommendations?store_number={n}
GET /previously_bought_products          # requires auth (user context) — not anonymous
GET /product_filters?store_number={n}
```

- `GET /products` needs a **positive** `store_number` (validation error `20`
  "This value should be positive" otherwise). Exact query-param name for the
  search string still to confirm (`search_string`/`query` returned validation
  errors only because of `store_number=0` in testing).
- **Barcode search is not supported here** — an EAN as a search term returns
  0 results on both the app search and the website. Barcode → product only
  works via `/products_by_barcode/{barcode}`.

---

## 6. Other routes present in the app (for reference)

Route path constants extracted from the app (`nl.plus.app.network.route.*`),
not all tested; most user-data routes (`/carts`, `/orders`, `/shopping_lists`,
`user/*`) require OneWelcome/Onegini OAuth login and are **not** anonymous:

`/calculate_plus_points_price`, `/carts`, `/orders`, `/orders/{id}`,
`content/homepage`, `content/urgent_notices`, `recipes`, `recipes/{id}`,
`recipes/{id}/ingredients`, `cookbooks`, `shopping_lists`,
`shopping_lists/{id}/multiple_line_items`, `previously_bought_products`,
`address/by_postal_code`, `/timeslot_requests`.

Auth endpoints (OneWelcome / Onegini `token.plus.nl`, `aanmelden.plus.nl`) are
only needed for account-bound features (orders, personal lists, saved store).
**Product/price/offer lookups documented above need none of this.**

---

## 7. Operational notes for a Grocy / Barcode Buddy module

- **Product lookup:** `products_by_barcode/{ean}?store_number=0` — name =
  `brand_name` + ` ` + `description` (skip brand if `description` already starts
  with it), legal name = the `Wettelijke omschrijving` info item. This is the
  logic already in `ProviderPlusSupermarkt.php`.
- **Prices/offers:** store one PLUS `store_number` in config (from `/stores`),
  query `products_by_barcode/{ean}?store_number={n}` and read `price`,
  `old_prices`, `is_discount`, `cart_discount`, `valid_period_list`. For the
  weekly leaflet as a whole, `discounts` + `multi_promo/{id}`.
- **Units for Grocy:** `shelf_item_consumer_package` gives quantity + unit
  (`400 g`), `base_unit` gives the comparison unit + price (per kilo/liter).
- **Robustness:** every field nullable; unknown barcode = 404 with HTML body;
  cents are integers. Cache aggressively (prices change ~weekly, product data
  rarely) and throttle requests — this is an internal app backend, so keep call
  volume app-like to avoid being blocked. Certificate pinning exists in the app
  but does not affect a normal HTTPS client.
- **Legality/ToS:** internal, undocumented endpoint; fine for personal/home use,
  but do not redistribute the data or hammer the service.

---

## 8. How this was obtained

1. Play Store → PLUS app package `com.mobgen.plus`.
2. XAPK pulled from an APK mirror, unpacked, `classes*.dex` decompiled with jadx.
3. Base URL found hard-coded in `OneginiConfigModel.java` / HTTP-client setup;
   route paths found as Ktor route constants (`C4835c("products_by_barcode/{barcode}", 4)`
   etc.) in `nl.plus.app.network.route.*`.
4. Endpoints validated with `curl` (anonymous) against known barcodes and a real
   store number.
