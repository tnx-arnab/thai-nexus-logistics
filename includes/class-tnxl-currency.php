<?php
/**
 * TNXL Currency Converter
 *
 * Converts THB amounts into the store currency. Frankfurter is tried first;
 * ExchangeRate-API (open.er-api.com) is used when Frankfurter does not support the pair.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Currency {

    private static $instance = null;

    private const FRANKFURTER_RATE_URL = 'https://api.frankfurter.dev/v2/rate/%s/%s';
    private const ERAPI_LATEST_URL = 'https://open.er-api.com/v6/latest/%s';

    /** @var string Last provider that returned a live rate: frankfurter|er-api|cache|identity */
    private $last_provider = '';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return;
        }
        add_filter('woocommerce_get_price_html', array($this, 'append_usd_price'), 10, 2);
    }

    /**
     * Get exchange rate between two currencies.
     *
     * @return float|false
     */
    public function get_rate($from = 'THB', $to = 'USD') {
        $from = $this->normalize_currency_code($from);
        $to = $this->normalize_currency_code($to);

        if (!TNXL_Settings::are_services_active()) {
            return false;
        }

        if ($from === '' || $to === '') {
            return false;
        }

        if ($from === $to) {
            $this->last_provider = 'identity';
            return 1.0;
        }

        $transient_key = 'tnxl_rate_' . strtolower($from) . '_' . strtolower($to);
        $cached = get_transient($transient_key);
        if (is_numeric($cached) && (float) $cached > 0) {
            $this->last_provider = 'cache';
            return (float) $cached;
        }

        $rate = $this->fetch_frankfurter_rate($from, $to);
        $provider = 'frankfurter';

        if (!$this->is_valid_rate($rate)) {
            $rate = $this->fetch_erapi_rate($from, $to);
            $provider = 'er-api';
        }

        if (!$this->is_valid_rate($rate)) {
            return false;
        }

        $rate = (float) $rate;
        $this->last_provider = $provider;
        set_transient($transient_key, $rate, DAY_IN_SECONDS);

        return $rate;
    }

    /**
     * Convert an amount. Returns false when no usable rate exists.
     *
     * @return float|false
     */
    public function convert_amount($amount, string $from = 'THB', string $to = 'USD') {
        $rate = $this->get_rate($from, $to);
        if (!$this->is_valid_rate($rate)) {
            return false;
        }
        return (float) $amount * (float) $rate;
    }

    public function get_last_provider(): string {
        return $this->last_provider;
    }

    /**
     * Backward compatibility for product price display (specifically USD)
     */
    public function get_thb_to_usd_rate() {
        return $this->get_rate('THB', 'USD');
    }

    /**
     * Append USD price to the product price HTML
     */
    public function append_usd_price($price_html, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price_html;
        }

        // Avoid double conversion if already processed
        if (strpos($price_html, 'tnxl-usd-price') !== false) {
            return $price_html;
        }

        // The requirement specifically asked for USD next to the original price
        $rate = $this->get_thb_to_usd_rate();
        if (!$this->is_valid_rate($rate)) {
            return $price_html;
        }

        $price = $product->get_price();
        if (empty($price)) {
            return $price_html;
        }

        // We assume the base price of the product is in THB as per requirements
        $usd_price = (float) $price * (float) $rate;
        $usd_price_formatted = '$' . number_format($usd_price, 2);

        $append_html = sprintf(
            ' <span class="tnxl-usd-price" style="font-size: 0.8em; color: #666;">(%s %s)</span>',
            esc_html__('USD', 'thai-nexus-logistics'),
            esc_html($usd_price_formatted)
        );

        return $price_html . $append_html;
    }

    /**
     * @return float|false
     */
    private function fetch_frankfurter_rate(string $from, string $to) {
        $url = sprintf(self::FRANKFURTER_RATE_URL, rawurlencode($from), rawurlencode($to));
        $data = $this->request_json($url);
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['rate']) && is_numeric($data['rate'])) {
            return (float) $data['rate'];
        }

        if (isset($data['rates'][$to]) && is_numeric($data['rates'][$to])) {
            return (float) $data['rates'][$to];
        }

        return false;
    }

    /**
     * ExchangeRate-API open access endpoint. Cached as a full rate table per base currency.
     *
     * @return float|false
     */
    private function fetch_erapi_rate(string $from, string $to) {
        $table = $this->get_erapi_table($from);
        if (isset($table[$to]) && is_numeric($table[$to]) && (float) $table[$to] > 0) {
            return (float) $table[$to];
        }

        // Triangulate via USD when the FROM table is unavailable.
        $usd_table = $this->get_erapi_table('USD');
        if (
            isset($usd_table[$from], $usd_table[$to])
            && is_numeric($usd_table[$from])
            && is_numeric($usd_table[$to])
            && (float) $usd_table[$from] > 0
        ) {
            return (float) $usd_table[$to] / (float) $usd_table[$from];
        }

        return false;
    }

    /**
     * @return array<string, float>
     */
    private function get_erapi_table(string $base): array {
        $cache_key = 'tnxl_erapi_table_' . strtolower($base);
        $cached = get_transient($cache_key);
        if (is_array($cached) && $cached !== array()) {
            return $cached;
        }

        $url = sprintf(self::ERAPI_LATEST_URL, rawurlencode($base));
        $data = $this->request_json($url);
        if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates']) || !is_array($data['rates'])) {
            return array();
        }

        $rates = array();
        foreach ($data['rates'] as $code => $value) {
            if (is_numeric($value) && (float) $value > 0) {
                $rates[strtoupper((string) $code)] = (float) $value;
            }
        }

        if ($rates !== array()) {
            set_transient($cache_key, $rates, DAY_IN_SECONDS);
        }

        return $rates;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function request_json(string $url) {
        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 10,
                'redirection' => 5,
                'headers'     => array('Accept' => 'application/json'),
                'user-agent'  => 'ThaiNexusLogistics/' . (defined('TNXL_VERSION') ? TNXL_VERSION : '1.0') . '; ' . home_url('/'),
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return false;
        }

        return $data;
    }

    private function normalize_currency_code($code): string {
        $code = strtoupper(sanitize_text_field((string) $code));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : '';
    }

    private function is_valid_rate($rate): bool {
        return is_numeric($rate) && (float) $rate > 0;
    }
}
