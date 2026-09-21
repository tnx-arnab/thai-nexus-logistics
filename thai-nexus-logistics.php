<?php
/**
 * Plugin Name: Thai Nexus Logistics - International Shipping Rates & Currency Converter for WooCommerce
 * Description: Real-time WooCommerce shipping rates, automated shipments, and multi-currency conversion for Thailand and international orders via the Thai Nexus API.
 * Version: 1.5.15
 * Author: Thai Nexus
 * Author URI: https://app.thainexus.co.th
 * Text Domain: thai-nexus-logistics
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 8.2
 * Tested up to: 7.1
 */

if (!defined('ABSPATH')) exit;

// Release line: stay on 1.5.x (patch) until explicitly approved for 1.6+.
define('TNXL_VERSION', '1.5.15');
define('TNXL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TNXL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TNXL_DEBUG', false);

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="error"><p>';
        echo esc_html(sprintf(
            /* translators: %s: PHP version number */
            __('Thai Nexus Logistics requires PHP 8.2 or higher. Your server is running PHP %s.', 'thai-nexus-logistics'),
            PHP_VERSION
        ));
        echo '</p></div>';
    });
    return;
}

$tnxl_autoload = TNXL_PLUGIN_DIR . 'vendor/autoload.php';
if (!is_readable($tnxl_autoload)) {
    add_action('admin_notices', static function () {
        echo '<div class="error"><p>';
        esc_html_e(
            'Thai Nexus Logistics is missing its vendor directory. Reinstall the plugin from a complete package.',
            'thai-nexus-logistics'
        );
        echo '</p></div>';
    });
    return;
}

require_once $tnxl_autoload;



/**
 * Main Plugin Class
 */
class Thai_Nexus_Logistics {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        // Load dependencies
        $this->load_dependencies();
        
        // Initialize hooks (priority 20 — after WooCommerce loads on plugins_loaded).
        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('admin_init', array($this, 'handle_dismiss_admin_notice'));
    }

    /**
     * Whether WooCommerce is installed and active.
     */
    private function is_woocommerce_active() {
        if (class_exists('WooCommerce')) {
            return true;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php');
    }

    /**
     * Persist dismissal of admin notices (per user).
     */
    public function handle_dismiss_admin_notice() {
        if (!isset($_GET['tnxl_dismiss']) || !isset($_GET['_wpnonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'tnxl_dismiss_notice')) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['tnxl_dismiss']));
        if ($notice === 'woocommerce') {
            update_user_meta(get_current_user_id(), 'tnxl_dismiss_woocommerce_notice', '1');
        }

        wp_safe_redirect(remove_query_arg(array('tnxl_dismiss', '_wpnonce')));
        exit;
    }

    private function load_dependencies() {
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-settings.php';
        TNXL_Settings::init();
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-service-coverage.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-migration.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-api.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-admin.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-debug-logger.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-rest-api.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-currency.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-tracking.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-tracking-sync.php';
    }

    public function init() {

        // Start Migration
        TNXL_Migration::get_instance();

        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        if (!class_exists('DVDoug\BoxPacker\Packer')) {
            add_action('admin_notices', array($this, 'boxpacker_missing_notice'));
        }

        // Load WooCommerce dependent files
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-product.php';
        if (class_exists('DVDoug\BoxPacker\Packer')) {
            require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-box-packer.php';
        }
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-shipping-method.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-order.php';
        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-commission.php';

        // Initialize Classes (admin always available)
        TNXL_API::get_instance();
        TNXL_Admin::get_instance();
        TNXL_REST_API::get_instance();
        TNXL_Product::get_instance();
        TNXL_Order::get_instance();
        TNXL_Tracking_Sync::get_instance();

        if (!TNXL_Settings::are_services_active()) {
            add_action('admin_notices', array($this, 'api_token_missing_notice'));
            return;
        }

        if (class_exists('TNXL_Box_Packer')) {
            TNXL_Box_Packer::get_instance();
        }

        $needs_shipping_method = TNXL_Settings::can_fetch_checkout_rates()
            || TNXL_Settings::is_auto_shipment_enabled();
        if ($needs_shipping_method) {
            add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));
        }

        if (TNXL_Settings::can_fetch_checkout_rates()) {
            TNXL_Currency::get_instance();
            TNXL_Commission::get_instance();

            add_filter('woocommerce_cart_shipping_packages', array($this, 'force_shipping_recalculation'));
            add_action('woocommerce_checkout_update_order_review', array($this, 'force_refresh_shipping'));
            add_action('woocommerce_store_api_cart_update_customer_from_request', array($this, 'force_refresh_shipping'), 10, 2);
            // WooCommerce may fire woocommerce_blocks_loaded before plugins_loaded:20.
            if (did_action('woocommerce_blocks_loaded')) {
                $this->register_block_integration();
            } else {
                add_action('woocommerce_blocks_loaded', array($this, 'register_block_integration'));
            }
            add_filter('woocommerce_package_rates', array($this, 'inject_global_rates'), 99, 2);
            add_filter('woocommerce_cart_ready_to_calc_shipping', array($this, 'maybe_hide_shipping_on_cart'), 99);
        }
    }

    /**
     * Determine if we should hide shipping (Cart page context)
     */
    public function maybe_hide_shipping_on_cart($show) {
        $is_cart = is_cart();
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            // If it's a store API request for the cart, but NOT for checkout
            if (strpos($request_uri, 'wc/store/v1/cart') !== false && strpos($request_uri, 'checkout') === false) {
                $is_cart = true;
            }
        }

        if ($is_cart) {
            return false; // Hide shipping on cart
        }
        
        return $show;
    }

    public function inject_global_rates($rates, $package) {
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return $rates;
        }

        // Double check: if we are in cart context, don't inject rates
        if ($this->maybe_hide_shipping_on_cart(true) === false) {
            return $rates;
        }

        foreach ($rates as $rate) {
            if ($rate instanceof WC_Shipping_Rate && $rate->get_method_id() === 'tnxl_shipping') {
                return $rates;
            }
        }

        try {
            $shipping_method = new TNXL_Shipping_Method();
            if ($shipping_method->enabled === 'no') {
                return $rates;
            }

            $shipping_method->calculate_shipping($package);
            $new_rates = $shipping_method->rates;

            if (!empty($new_rates)) {
                $rates = array_merge($rates, $new_rates);
            }
        } catch (\Throwable $e) {
            if (defined('TNXL_DEBUG') && TNXL_DEBUG) {
                error_log('TNXL inject_global_rates: ' . $e->getMessage());
            }
        }

        return $rates;
    }

    public function force_shipping_recalculation($packages) {
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return $packages;
        }

        $request_address = array();
        
        // If we are in a REST API request (Store API / Checkout Block)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request = method_exists('WP_REST_Server', 'get_current_request') ? WP_REST_Server::get_current_request() : null;
            if ($request && strpos($request->get_route(), 'wc/store') !== false) {
                $params = $request->get_params();
                // Check for shipping address in the request
                $addr = isset($params['shipping_address']) ? $params['shipping_address'] : (isset($params['billing_address']) ? $params['billing_address'] : array());
                
                if (!empty($addr)) {
                    foreach (array('country', 'state', 'postcode', 'city') as $field) {
                        if (isset($addr[$field]) && !empty($addr[$field])) {
                            $request_address[$field] = $addr[$field];
                        }
                    }
                }
            }
        }

        foreach ($packages as $i => $package) {
            // Override address fields if we found newer ones in the request
            if (!empty($request_address)) {
                $packages[$i]['destination'] = array_merge($packages[$i]['destination'], $request_address);

            }
            
            // Stable hash when destination changes (avoid microtime — it busts cache every request).
            $packages[$i]['tnxl_dest_hash'] = md5(wp_json_encode($packages[$i]['destination'] ?? array()));
        }
        return $packages;
    }

    /**
     * Force WooCommerce to recalculate shipping
     */
    public function force_refresh_shipping() {
        if (!TNXL_Settings::can_fetch_checkout_rates()) {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $wc = WC();
        if (!$wc->cart || !$wc->session) {
            return;
        }

        // Recalculate only — do not clear chosen_shipping_methods (breaks checkout selection).
        $wc->cart->calculate_shipping();
        $wc->cart->calculate_totals();
    }

    public function register_block_integration() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Package')
            || !interface_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
            return;
        }

        require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-checkout-block-integration.php';

        add_action('woocommerce_blocks_checkout_block_registration', function ($integration_registry) {
            $integration_registry->register(new TNXL_Checkout_Block_Integration());
        });

        // Expose commission breakdown to Store API (Checkout Blocks).
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(
                array(
                    'endpoint'        => 'cart',
                    'namespace'       => 'tnxl-shipping',
                    'data_callback'   => static function () {
                        if (!TNXL_Settings::can_fetch_checkout_rates()) {
                            return array();
                        }
                        return array(
                            'commission' => TNXL_Commission::get_instance()->get_total_commission(),
                        );
                    },
                    'schema_callback' => static function () {
                        return array(
                            'commission' => array(
                                'description' => __('Total Thai Nexus commission buffer applied to shipping.', 'thai-nexus-logistics'),
                                'type'        => 'number',
                                'context'     => array('view', 'edit'),
                                'readonly'    => true,
                            ),
                        );
                    },
                    'schema_type'     => ARRAY_A,
                )
            );
        }
    }

    public function register_shipping_method($methods) {
        $methods['tnxl_shipping'] = 'TNXL_Shipping_Method';
        return $methods;
    }

    public function woocommerce_missing_notice() {
        if ($this->is_woocommerce_active()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_user_meta(get_current_user_id(), 'tnxl_dismiss_woocommerce_notice', true)) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg('tnxl_dismiss', 'woocommerce', admin_url()),
            'tnxl_dismiss_notice'
        );
        ?>
        <div class="notice notice-warning is-dismissible tnxl-wc-missing-notice" data-dismiss-url="<?php echo esc_url($dismiss_url); ?>">
            <p>
                <?php esc_html_e('Thai Nexus Logistics requires WooCommerce to be installed and active.', 'thai-nexus-logistics'); ?>
                <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="button button-small" style="margin-left: 8px;">
                    <?php esc_html_e('Install WooCommerce', 'thai-nexus-logistics'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-small" style="margin-left: 4px;">
                    <?php esc_html_e('Dismiss', 'thai-nexus-logistics'); ?>
                </a>
            </p>
        </div>
        <script>
        (function () {
            document.addEventListener('click', function (event) {
                var notice = event.target.closest('.tnxl-wc-missing-notice');
                if (!notice) {
                    return;
                }
                var dismissButton = event.target.closest('.notice-dismiss');
                if (!dismissButton || !notice.dataset.dismissUrl) {
                    return;
                }
                fetch(notice.dataset.dismissUrl, { method: 'GET', credentials: 'same-origin' });
            });
        })();
        </script>
        <?php
    }

    public function boxpacker_missing_notice() {
        ?>
        <div class="error">
            <p><?php esc_html_e('Thai Nexus Logistics could not load the BoxPacker library. Reinstall the plugin from a complete package.', 'thai-nexus-logistics'); ?></p>
        </div>
        <?php
    }

    public function api_token_missing_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen) {
            $show = ($screen->id === 'toplevel_page_tnxl-logistics')
                || str_contains((string) $screen->id, 'woocommerce')
                || str_contains((string) $screen->id, 'wc-');
            if (!$show) {
                return;
            }
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: settings admin URL */
                        __('Thai Nexus Logistics is inactive until you add your API token in <a href="%s">Settings</a>.', 'thai-nexus-logistics'),
                        esc_url(admin_url('admin.php?page=tnxl-logistics'))
                    ),
                    array('a' => array('href' => array()))
                );
                ?>
            </p>
        </div>
        <?php
    }
}

register_deactivation_hook(__FILE__, static function () {
    require_once TNXL_PLUGIN_DIR . 'includes/class-tnxl-tracking-sync.php';
    TNXL_Tracking_Sync::unschedule();
});

// Start the plugin
Thai_Nexus_Logistics::get_instance();

