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
 * @since      File available since Release 1.8
 */


require_once __DIR__ . "/../api.inc.php";

class ProviderZooplus extends LookupProvider {

    const SEARCH_URL = 'https://www.zooplus.nl/hopps-search/api/v1/sites/7/nl/suggest';

    function __construct(string $apiKey = null) {
        parent::__construct($apiKey);
        $this->providerName      = 'Zooplus Nederland';
        $this->providerConfigKey = 'LOOKUP_USE_ZOOPLUS';
    }

    /**
     * Looks up a barcode
     * @param string $barcode The barcode to lookup
     * @return array|null Name of product, null if none found
     */
    public function lookupBarcode(string $barcode): ?array {
        if (!$this->isProviderEnabled() || !self::isSupportedBarcode($barcode)) {
            return null;
        }

        // Anonymous endpoint used by the Zooplus website and mobile app. It is
        // undocumented, so an empty or changed response must fail softly.
        $query = http_build_query(array(
            'q' => $barcode,
            'hl' => '0',
            'u' => 'barcodebuddy',
            'include3p' => 'false'
        ), '', '&', PHP_QUERY_RFC3986);
        $result = $this->execute(self::SEARCH_URL . '?' . $query, METHOD_GET);

        if (!is_array($result) || !isset($result['results']) || !is_array($result['results'])) {
            return null;
        }

        foreach ($result['results'] as $group) {
            if (!is_array($group) || !isset($group['type']) || $group['type'] !== 'product' ||
                !isset($group['children']) || !is_array($group['children'])) {
                continue;
            }
            foreach ($group['children'] as $product) {
                if (!is_array($product) || !isset($product['name'])) {
                    continue;
                }
                $name = trim(strip_tags((string)$product['name']));
                if ($name !== '') {
                    return self::createReturnArray(sanitizeString($name));
                }
            }
        }
        return null;
    }

    /**
     * Zooplus indexes retail GTIN formats. Checking the checksum avoids sending
     * arbitrary internal Barcode Buddy commands or malformed values upstream.
     * @param string $barcode
     * @return bool
     */
    private static function isSupportedBarcode(string $barcode): bool {
        $length = strlen($barcode);
        if (!ctype_digit($barcode) || !in_array($length, array(8, 12, 13, 14), true)) {
            return false;
        }

        $sum = 0;
        $weight = 3;
        for ($i = $length - 2; $i >= 0; $i--) {
            $sum += intval($barcode[$i]) * $weight;
            $weight = ($weight === 3) ? 1 : 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit === intval($barcode[$length - 1]);
    }
}
