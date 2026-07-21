<?php
/**
 * Plugin feature flags and shared settings helpers.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Settings {

    public static function init(): void {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_register_defaults'), 4);
    }

    public const OPTION_CHECKOUT_RATES   = 'tnxl_enable_checkout_rates';
    public const OPTION_AUTO_SHIPMENTS   = 'tnxl_enable_auto_shipments';
    public const OPTION_DISABLED_SERVICES = 'tnxl_disabled_service_ids';
    public const OPTION_INELIGIBLE_PRODUCTS = 'tnxl_shipping_ineligible_product_ids';

    public static function maybe_register_defaults(): void {
        if (null === get_option(self::OPTION_CHECKOUT_RATES, null)) {
            update_option(self::OPTION_CHECKOUT_RATES, 'yes');
        }
        if (null === get_option(self::OPTION_AUTO_SHIPMENTS, null)) {
            update_option(self::OPTION_AUTO_SHIPMENTS, 'yes');
        }
    }

    /**
     * Whether live Thai Nexus rates are fetched at checkout.
     */
    public static function is_checkout_rates_enabled(): bool {
        return self::option_is_yes(self::OPTION_CHECKOUT_RATES, 'yes');
    }

    /**
     * Whether shipments are created automatically when orders are processed/completed.
     */
    public static function is_auto_shipment_enabled(): bool {
        return self::option_is_yes(self::OPTION_AUTO_SHIPMENTS, 'yes');
    }

    /**
     * Master gate: no Thai Nexus API token means all external services stay off.
     */
    public static function are_services_active(): bool {
        return self::has_api_token();
    }

    /**
     * Checkout rates require the feature flag and a valid API token.
     */
    public static function can_fetch_checkout_rates(): bool {
        return self::is_checkout_rates_enabled() && self::are_services_active();
    }

    /**
     * Auto shipment requires the feature flag, API token, and minimum shipper data.
     */
    public static function can_auto_create_shipment(): bool {
        return self::is_auto_shipment_enabled()
            && self::are_services_active()
            && self::has_shipper_details();
    }

    public static function get_api_token(): string {
        return trim((string) get_option('tnxl_api_token', ''));
    }

    public static function has_api_token(): bool {
        return self::get_api_token() !== '';
    }

    public static function has_shipper_details(): bool {
        $required = array('tnxl_shipper_name', 'tnxl_shipper_phone', 'tnxl_shipper_address', 'tnxl_shipper_city', 'tnxl_shipper_country');
        foreach ($required as $option) {
            if (!get_option($option, '')) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string[]
     */
    public static function get_disabled_service_ids(): array {
        $ids = get_option(self::OPTION_DISABLED_SERVICES, array());
        if (!is_array($ids)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize_service_id'), $ids))));
    }

    /**
     * @param array<mixed> $ids
     */
    public static function save_disabled_service_ids(array $ids): void {
        update_option(
            self::OPTION_DISABLED_SERVICES,
            array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize_service_id'), $ids)))),
            false
        );
    }

    public static function normalize_service_id($value): string {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/\s+/', '_', $value);

        return sanitize_key((string) $value);
    }

    /**
     * @return int[]
     */
    public static function get_ineligible_product_ids(): array {
        $ids = get_option(self::OPTION_INELIGIBLE_PRODUCTS, array());
        if (!is_array($ids)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    /**
     * @param array<mixed> $ids
     */
    public static function save_ineligible_product_ids(array $ids): void {
        update_option(
            self::OPTION_INELIGIBLE_PRODUCTS,
            array_values(array_unique(array_filter(array_map('absint', $ids)))),
            false
        );
    }

    /**
     * Feature flags for REST/admin UI.
     */
    public static function get_features(): array {
        return array(
            'checkout_rates'   => self::is_checkout_rates_enabled(),
            'auto_shipments'   => self::is_auto_shipment_enabled(),
            'services_active'  => self::are_services_active(),
        );
    }

    /**
     * @param array<string, mixed> $features
     */
    public static function save_features(array $features): void {
        if (array_key_exists('checkout_rates', $features)) {
            update_option(self::OPTION_CHECKOUT_RATES, self::bool_to_yes_no($features['checkout_rates']));
        }
        if (array_key_exists('auto_shipments', $features)) {
            update_option(self::OPTION_AUTO_SHIPMENTS, self::bool_to_yes_no($features['auto_shipments']));
        }
    }

    /**
     * @param mixed $value
     */
    private static function bool_to_yes_no($value): string {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
    }

    /**
     * @param mixed $value
     */
    private static function option_is_yes(string $option, string $default = 'yes'): bool {
        $value = get_option($option, $default);
        return $value === 'yes' || $value === true || $value === '1' || $value === 1;
    }
}
