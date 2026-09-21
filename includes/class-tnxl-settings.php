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
    public const OPTION_PRODUCT_WEIGHT_UNIT = 'tnxl_product_weight_unit';
    public const OPTION_ACTUAL_WEIGHT_ONLY = 'tnxl_charge_actual_weight_only';
    public const OPTION_SERVICE_COVERAGE = 'tnxl_service_coverage';
    public const OPTION_PRICING_MODE = 'tnxl_pricing_mode';

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

    public static function get_product_weight_unit(): string {
        $saved = (string) get_option(self::OPTION_PRODUCT_WEIGHT_UNIT, '');
        if ($saved === 'g' || $saved === 'kg') {
            return $saved;
        }
        return get_option('woocommerce_weight_unit') === 'g' ? 'g' : 'kg';
    }

    public static function save_product_weight_unit($value): void {
        $unit = strtolower(trim((string) $value));
        update_option(self::OPTION_PRODUCT_WEIGHT_UNIT, $unit === 'g' ? 'g' : 'kg', false);
    }

    public static function is_actual_weight_only(): bool {
        return self::option_is_yes(self::OPTION_ACTUAL_WEIGHT_ONLY, 'no');
    }

    public static function save_actual_weight_only($value): void {
        update_option(self::OPTION_ACTUAL_WEIGHT_ONLY, self::bool_to_yes_no($value), false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_service_coverage(): array {
        $coverage = get_option(self::OPTION_SERVICE_COVERAGE, array());
        return is_array($coverage) ? $coverage : array();
    }

    /**
     * @param array<mixed> $coverage
     */
    public static function save_service_coverage(array $coverage): void {
        $clean = array();
        foreach ($coverage as $key => $rule) {
            $id = self::normalize_service_id($key);
            if ($id === '' || !is_array($rule)) {
                continue;
            }
            $rest = !empty($rule['restOfWorld']) || !empty($rule['rest_of_world']);
            $exclude = !$rest && (!empty($rule['excludeCountries']) || !empty($rule['exclude_countries']));
            $worldwide = !$rest && !$exclude && (!isset($rule['worldwide']) || $rule['worldwide']);
            $countries = array();
            foreach ((array) ($rule['countries'] ?? array()) as $code) {
                $iso = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code));
                if (strlen($iso) === 2) {
                    $countries[] = $iso;
                }
            }
            $countries = array_values(array_unique($countries));
            if ($rest) {
                $clean[$id] = array('worldwide' => false, 'restOfWorld' => true, 'countries' => array());
                continue;
            }
            if ($exclude) {
                $clean[$id] = array('worldwide' => true, 'excludeCountries' => true, 'countries' => $countries);
                continue;
            }
            $clean[$id] = $worldwide
                ? array('worldwide' => true, 'countries' => array())
                : array('worldwide' => false, 'countries' => $countries);
        }
        update_option(self::OPTION_SERVICE_COVERAGE, $clean, false);
    }

    public static function get_pricing_mode(): string {
        $mode = (string) get_option(self::OPTION_PRICING_MODE, '');
        if ($mode === 'advanced' || $mode === 'basic') {
            return $mode;
        }

        $rules = get_option('tnxl_commission_rules', array());
        if (!is_array($rules) || !class_exists('TNXL_Commission')) {
            return 'basic';
        }

        $always_on_with_effect = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (!TNXL_Commission::rule_is_always_on($rule)) {
                return 'advanced';
            }
            if (TNXL_Commission::rule_has_effect($rule)) {
                $always_on_with_effect++;
                if ($always_on_with_effect > 1) {
                    return 'advanced';
                }
            }
        }

        return 'basic';
    }

    public static function save_pricing_mode($value): void {
        $mode = (string) $value;
        update_option(self::OPTION_PRICING_MODE, $mode === 'advanced' ? 'advanced' : 'basic', false);
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
