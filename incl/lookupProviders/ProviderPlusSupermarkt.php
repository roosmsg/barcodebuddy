<?php

/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * @author     Marc Ole Bulling
 * @copyright  2019 Marc Ole Bulling
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      File available since Release 1.6
 */


require_once __DIR__ . "/../api.inc.php";

class ProviderPlusSupermarkt extends LookupProvider {

    function __construct(string $apiKey = null) {
        parent::__construct($apiKey);
        $this->providerName       = 'Plus Supermarkt';
        $this->providerConfigKey  = 'LOOKUP_USE_PLUS';
        $this->ignoredResultCodes = array('404');
    }

    /**
     * Looks up a barcode
     * @param string $barcode The barcode to lookup
     * @return array|null Name of product, null if none found
     */
    public function lookupBarcode(string $barcode): ?array {
        if (!$this->isProviderEnabled()) {
            return null;
        }

        if (substr($barcode, 0, 2) == 21) {
            $barcode = str_pad(substr($barcode, 0, 6), 13, '0');
        }

        // Endpoint of the PLUS mobile app (com.mobgen.plus). The former middleware
        // host pls-sprmrkt-mw.prd.vdc1.plus.nl was decommissioned in 2026; this
        // endpoint answers anonymously and returns 404 for unknown barcodes.
        $url    = 'https://apiframna.app.plus.nl/api/app/v1/products_by_barcode/' . $barcode . '?store_number=0';
        $result = $this->execute($url, METHOD_GET);

        if (!is_array($result) || !isset($result['key']) || empty($result['description'])) {
            return null;
        }

        $productName = self::buildProductName($result);
        $genericName = null;

        if ($this->useGenericName) {
            $genericName = self::findLegalDescription($result);
        }

        return self::createReturnArray($this->returnNameOrGenericName($productName, $genericName));
    }

    /**
     * Combines brand and description ("Calvé" + "Pindakaas regular"),
     * unless the description already starts with the brand.
     * @param array $product Decoded product JSON
     * @return string
     */
    private static function buildProductName(array $product): string {
        $description = trim($product['description']);
        $brand       = isset($product['brand_name']) ? trim((string)$product['brand_name']) : '';
        if ($brand === '' || stripos($description, $brand) === 0) {
            return $description;
        }
        return $brand . ' ' . $description;
    }

    /**
     * Returns the legal description ("Wettelijke omschrijving") from the
     * product information sections, or null if not present.
     * @param array $product Decoded product JSON
     * @return string|null
     */
    private static function findLegalDescription(array $product): ?string {
        if (!isset($product['product_information']['main_items']) || !is_array($product['product_information']['main_items'])) {
            return null;
        }
        foreach ($product['product_information']['main_items'] as $section) {
            if (!isset($section['items']) || !is_array($section['items'])) {
                continue;
            }
            foreach ($section['items'] as $item) {
                if (isset($item['title'], $item['text']) && $item['title'] === 'Wettelijke omschrijving' && trim($item['text']) !== '') {
                    return trim($item['text']);
                }
            }
        }
        return null;
    }
}