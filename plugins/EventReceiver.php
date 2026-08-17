<?php
/**
 * Barcode Buddy plugin: automatically create Grocy products for unknown barcodes.
 *
 * Replaces the stock plugins/EventReceiver.php (bind-mounted into the container).
 * Barcode Buddy calls pluginEventReceiver_processEvent() for every log event; this
 * plugin reacts to EVENT_TYPE_ADD_NEW_BARCODE (an unknown barcode whose name was
 * found by the lookup providers — Open Food Facts, AH, PLUS, ...). For every pending
 * unknown barcode that has a name it:
 *   1. links the barcode to an existing Grocy product with the same name, or
 *      creates a new Grocy product (location/unit/best-before from the constants below);
 *   2. associates the barcode with that product in Grocy;
 *   3. removes the entry from Barcode Buddy's "New barcodes" list;
 *   4. if Barcode Buddy is currently in purchase mode, books the accumulated amount
 *      as a purchase (other modes: nothing is booked — a new product has no stock).
 * Barcodes without a name (lookup failed) stay in the "New barcodes" list for manual handling.
 *
 * Deployed at /opt/barcodebuddy/plugins/EventReceiver.php on the Docker VM,
 * mounted to /app/bbuddy/plugins/EventReceiver.php (see stack barcodebuddy).
 */

// ---- settings -----------------------------------------------------------------
const AUTOCREATE_ENABLED           = true;
const AUTOCREATE_LOCATION_NAME     = null;   // Grocy location name, or null = first location
const AUTOCREATE_UNIT_NAME         = "Piece"; // Grocy quantity unit name (singular), or null = first unit
const AUTOCREATE_BEST_BEFORE_DAYS  = -1;     // default_best_before_days: -1 = never expires, 0 = unknown, n = days
const AUTOCREATE_DESCRIPTION       = "Automatisch aangemaakt door Barcode Buddy (barcode-lookup)";
const AUTOCREATE_PREFIX_BRAND      = true;   // ask Open Food Facts for the brand and prefix it: "Coca-Cola Original Taste"
// --------------------------------------------------------------------------------

function pluginEventReceiver_processEvent($eventType, $log): void {
    static $running = false;
    if (!AUTOCREATE_ENABLED || $running)
        return;
    if ($eventType != EVENT_TYPE_ADD_NEW_BARCODE)
        return;
    $running = true;
    try {
        autocreate_processPending();
    } catch (Throwable $e) {
        autocreate_log("Auto-create failed: " . $e->getMessage(), true);
    } finally {
        $running = false;
    }
}

function autocreate_log(string $text, bool $isError = false, ?string $barcode = null, bool $websocket = false): void {
    $l = new LogOutput("[AutoCreate] " . $text, EVENT_TYPE_ASSOCIATE_PRODUCT, $barcode, $isError);
    if ($websocket)
        $l->setWebsocketResultCode(WS_RESULT_PRODUCT_FOUND);
    else
        $l->dontSendWebsocket();
    $l->createLog();
}

function autocreate_processPending(): void {
    $db      = DatabaseConnection::getInstance();
    $pending = $db->getStoredBarcodes()["known"]; // entries with a looked-up name
    if (count($pending) == 0)
        return;

    $isPurchase = ($db->getTransactionState() == STATE_PURCHASE);
    $products   = API::getAllProductsInfo(true) ?? array();
    $byName     = array();
    foreach ($products as $p)
        $byName[mb_strtolower(trim($p->name))] = $p;

    foreach ($pending as $item) {
        $barcode = $item["barcode"];
        $name    = trim(html_entity_decode($item["name"], ENT_QUOTES | ENT_HTML5));
        if ($name == "" || $name == "N/A")
            continue;
        if (!preg_match('/^[0-9]{6,14}$/', $barcode)) // only real EAN/UPC codes
            continue;

        $name = autocreate_enrichName($barcode, $name); // e.g. "Original Taste" -> "Coca-Cola Original Taste"
        $key  = mb_strtolower($name);
        if (isset($byName[$key])) {
            $productId = intval($byName[$key]->id);
            $created   = false;
        } else {
            $productId = autocreate_createProduct($name);
            if ($productId === null) {
                autocreate_log("Could not create Grocy product $name; left in New barcodes", true, $barcode);
                continue;
            }
            $created = true;
        }

        $note = (BBConfig::getInstance()["SAVE_BARCODE_NAME"] == "1") ? $name : null;
        API::addBarcode($productId, $barcode, $note);
        RedisConnection::expireAllProductInfo();
        RedisConnection::expireAllBarcodes();
        $db->deleteBarcode($item["id"]);
        QuantityManager::syncBarcodeToGrocy($barcode);

        $msg = ($created ? "Created Grocy product: $name" : "Linked to existing product: $name") . " (barcode $barcode)";
        if ($isPurchase) {
            $amount     = max(1, floatval($item["amount"]));
            $bestBefore = (isset($item["bestBeforeInDays"]) && $item["bestBeforeInDays"] !== null && $item["bestBeforeInDays"] !== "") ? strval($item["bestBeforeInDays"]) : null;
            $price      = (isset($item["price"]) && $item["price"] !== null && $item["price"] !== "") ? strval($item["price"]) : null;
            API::purchaseProduct($productId, $amount, $bestBefore, $price);
            $msg .= "; purchased $amount";
        }
        autocreate_log($msg, false, null, true);
        // Refresh name index so a second pending barcode with the same name links instead of creating
        $byName[$key] = (object) array("id" => $productId, "name" => $name);
    }
}

/**
 * Builds "Brand Productname" from Open Food Facts when possible (Barcode Buddy's own
 * OFF provider drops the brand). Falls back to the name Barcode Buddy looked up.
 */
function autocreate_enrichName(string $barcode, string $bbName): string {
    if (!AUTOCREATE_PREFIX_BRAND)
        return $bbName;
    global $CONFIG;
    $lang = isset($CONFIG->DEFAULT_LOOKUP_LANGUAGE) ? $CONFIG->DEFAULT_LOOKUP_LANGUAGE : "en";
    $url  = "https://world.openfoodfacts.org/api/v2/product/" . $barcode . ".json?fields=brands,product_name,product_name_" . $lang . ",generic_name";
    $ch   = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => "BarcodeBuddy-AutoCreate/1.0 (homelab)"));
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false)
        return $bbName;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j["status"]) || $j["status"] != 1 || !isset($j["product"]))
        return $bbName;
    $p    = $j["product"];
    $name = "";
    foreach (array("product_name_" . $lang, "product_name", "generic_name") as $f)
        if (isset($p[$f]) && trim($p[$f]) != "") { $name = trim($p[$f]); break; }
    if ($name == "")
        $name = $bbName;
    $brand = "";
    if (isset($p["brands"]) && trim($p["brands"]) != "")
        $brand = trim(explode(",", $p["brands"])[0]);
    if ($brand != "" && mb_stripos($name, $brand) === false)
        $name = $brand . " " . $name;
    return trim(preg_replace('/\s+/', ' ', $name));
}

function autocreate_pickId(array $rows, ?string $wantedName): ?int {
    if (count($rows) == 0)
        return null;
    if ($wantedName !== null) {
        foreach ($rows as $r)
            if (isset($r["name"]) && mb_strtolower(trim($r["name"])) == mb_strtolower($wantedName))
                return intval($r["id"]);
    }
    return intval($rows[0]["id"]);
}

function autocreate_createProduct(string $name): ?int {
    $locations = (new CurlGenerator("objects/locations"))->execute(true);
    $units     = (new CurlGenerator("objects/quantity_units"))->execute(true);
    $locId     = autocreate_pickId(is_array($locations) ? $locations : array(), AUTOCREATE_LOCATION_NAME);
    $unitId    = autocreate_pickId(is_array($units) ? $units : array(), AUTOCREATE_UNIT_NAME);
    if ($locId === null || $unitId === null) {
        autocreate_log("No Grocy location or quantity unit available", true);
        return null;
    }
    $data = json_encode(array(
        "name"                     => mb_substr($name, 0, 200),
        "description"              => AUTOCREATE_DESCRIPTION,
        "location_id"              => $locId,
        "qu_id_purchase"           => $unitId,
        "qu_id_stock"              => $unitId,
        "qu_id_consume"            => $unitId,
        "qu_id_price"              => $unitId,
        "default_best_before_days" => AUTOCREATE_BEST_BEFORE_DAYS
    ));
    $result = (new CurlGenerator(API_O_PRODUCTS, METHOD_POST, $data))->execute(true);
    if (is_array($result) && isset($result["created_object_id"]))
        return intval($result["created_object_id"]);
    autocreate_log("Unexpected Grocy response when creating $name: " . json_encode($result), true);
    return null;
}
