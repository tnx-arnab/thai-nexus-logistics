<?php
/**
 * Fire-and-forget D1 copy. WordPress remains the source of truth.
 */

if (!defined('ABSPATH')) exit;

class TNXL_D1_Copy {

    public const OPTION_INGEST_URL = 'tnxl_d1_ingest_url';
    public const OPTION_INGEST_SECRET = 'tnxl_d1_ingest_secret';

    /** Thai Nexus Cloudflare ingest worker (workers.dev). Overridable via option. */
    public const DEFAULT_INGEST_URL = 'https://woo.thainexus.co.th';

    /**
     * Shared ingest key for the official Thai Nexus Woo D1 mirror.
     * Sites can override with tnxl_d1_ingest_secret if they run a private ingest.
     */
    public const DEFAULT_INGEST_SECRET = 'faea3cc9d09b023cda9dd725403435784f7b9961487b326a21493f492b4b3aac';

    public static function init(): void {
        add_action('admin_init', array(__CLASS__, 'maybe_push_settings'), 20);
    }

    public static function ingest_url(): string {
        $url = trim((string) get_option(self::OPTION_INGEST_URL, ''));
        return $url !== '' ? $url : self::DEFAULT_INGEST_URL;
    }

    public static function ingest_secret(): string {
        $secret = trim((string) get_option(self::OPTION_INGEST_SECRET, ''));
        return $secret !== '' ? $secret : self::DEFAULT_INGEST_SECRET;
    }

    public static function is_configured(): bool {
        return self::ingest_url() !== '' && self::ingest_secret() !== '' && self::ingest_secret() !== 'REPLACE_AFTER_DEPLOY';
    }

    /**
     * Push settings once per day from wp-admin so connected shops appear in the
     * store monitor without waiting for the next settings save.
     */
    public static function maybe_push_settings(): void {
        if (!self::is_configured()) {
            return;
        }
        if (!TNXL_Settings::has_api_token()) {
            return;
        }
        if (get_transient('tnxl_d1_settings_pushed')) {
            return;
        }
        self::copy_settings();
        set_transient('tnxl_d1_settings_pushed', 1, DAY_IN_SECONDS);
    }

    public static function copy_settings(): void {
        self::post('settings', array(
            'api_token' => (string) get_option('tnxl_api_token', ''),
            'features' => array(
                'checkout_rates' => TNXL_Settings::is_checkout_rates_enabled(),
                'auto_shipments' => TNXL_Settings::is_auto_shipment_enabled(),
            ),
            'disabled_service_ids' => TNXL_Settings::get_disabled_service_ids(),
            'shipping_ineligible_product_ids' => TNXL_Settings::get_ineligible_product_ids(),
            'product_weight_unit' => TNXL_Settings::get_product_weight_unit(),
            'charge_actual_weight_only' => TNXL_Settings::is_actual_weight_only(),
            'service_coverage' => TNXL_Settings::get_service_coverage(),
            'pricing_mode' => TNXL_Settings::get_pricing_mode(),
            'commission_rules' => get_option('tnxl_commission_rules', array()),
            'boxes' => get_option('tnxl_box_definitions', array()),
            'shipper' => array(
                'name' => get_option('tnxl_shipper_name', ''),
                'phone' => get_option('tnxl_shipper_phone', ''),
                'address' => get_option('tnxl_shipper_address', ''),
                'city' => get_option('tnxl_shipper_city', ''),
                'state' => get_option('tnxl_shipper_state', ''),
                'postal_code' => get_option('tnxl_shipper_postal_code', ''),
                'country' => get_option('tnxl_shipper_country', 'TH'),
            ),
        ));
    }

    /**
     * Selected checkout shipping on an order (not quoted alternatives).
     *
     * @return array{shipping_amount: float|null, shipping_currency: string|null, selected_courier: string|null, selected_courier_title: string|null}
     */
    public static function selected_shipping_from_order($order): array {
        $amount = null;
        $currency = null;
        $courier = null;
        $title = null;

        if (is_object($order) && method_exists($order, 'get_shipping_total')) {
            $raw = (float) $order->get_shipping_total();
            if ($raw > 0) {
                $amount = round($raw, 2);
            }
        }
        if (is_object($order) && method_exists($order, 'get_currency')) {
            $code = trim((string) $order->get_currency());
            $currency = $code !== '' ? $code : null;
        }
        if (is_object($order) && method_exists($order, 'get_shipping_methods')) {
            foreach ($order->get_shipping_methods() as $method) {
                $id = '';
                $name = '';
                if (is_object($method) && method_exists($method, 'get_meta')) {
                    $id = trim((string) $method->get_meta('tnxl_courier'));
                    $name = trim((string) $method->get_meta('tnxl_courier_display'));
                }
                if ($name === '' && is_object($method) && method_exists($method, 'get_method_title')) {
                    $raw_title = (string) $method->get_method_title();
                    $name = trim((string) preg_replace('/\s*\([^)]*days?\)\s*$/i', '', $raw_title));
                }
                if ($id !== '' || $name !== '') {
                    $courier = $id !== '' ? $id : $name;
                    $title = $name !== '' ? $name : $id;
                    break;
                }
            }
        }

        return array(
            'shipping_amount' => $amount,
            'shipping_currency' => $currency,
            'selected_courier' => $courier,
            'selected_courier_title' => $title,
        );
    }

    public static function copy_shipment($order): void {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }

        $selected = self::selected_shipping_from_order($order);

        self::post('shipment', array(
            'order_id' => (string) $order->get_id(),
            'request_number' => (string) $order->get_meta('_tnxl_request_number'),
            'status' => (string) $order->get_meta('_tnxl_status'),
            'complete' => $order->get_meta('_tnxl_shipment_complete'),
            'expected_box_count' => absint($order->get_meta('_tnxl_expected_box_count')),
            'shipments' => $order->get_meta('_tnxl_all_shipments'),
            'errors' => $order->get_meta('_tnxl_shipment_errors'),
            'shipping_amount' => $selected['shipping_amount'],
            'shipping_currency' => $selected['shipping_currency'],
            'selected_courier' => $selected['selected_courier'],
            'selected_courier_title' => $selected['selected_courier_title'],
        ));
    }

    public static function copy_debug_entry(array $entry): void {
        self::post('debug', $entry);
    }

    public static function clear_debug(): void {
        self::post('debug_clear', array());
    }

    private static function post(string $kind, array $payload): void {
        if (!self::is_configured()) {
            return;
        }

        $endpoint = rtrim(self::ingest_url(), '/') . '/copy';
        wp_remote_post($endpoint, array(
            'timeout' => 0.01,
            'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-TNXL-Key' => self::ingest_secret(),
            ),
            'body' => wp_json_encode(array(
                'kind' => $kind,
                'site_url' => home_url(),
                'payload' => $payload,
            )),
        ));
    }
}
