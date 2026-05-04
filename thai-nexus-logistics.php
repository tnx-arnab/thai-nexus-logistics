<?php
/**
 * Plugin Name: Thai Nexus Logistics
 * Description: Real-time shipping quotations and automated shipment creation via Thai Nexus API.
 * Version: 1.5.0
 * Author: Thai Nexus Logistics
 * Text Domain: thai-nexus-logistics
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

// Define Constants
define('TNX_VERSION', '1.0.0');
define('TNX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TNX_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer Autoloader
if (file_exists(TNX_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TNX_PLUGIN_DIR . 'vendor/autoload.php';
}

error_log("TNX Plugin Booting: " . date('Y-m-d H:i:s'));

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
        
        // Initialize hooks
        add_action('plugins_loaded', array($this, 'init'));
    }

    private function load_dependencies() {
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-api.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-admin.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-rest-api.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-currency.php';
    }

    public function init() {

        // Check if WooCommerce is active
        if (!class_exists('Commerce')) {
            // Check for WooCommerce (different versions might have different class names but class_exists('WooCommerce') is standard)
        }
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load WooCommerce dependent files
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-product.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-box-packer.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-shipping-method.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-order.php';
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-commission.php';

        // Initialize Classes
        TNX_API::get_instance();
        TNX_Admin::get_instance();
        TNX_REST_API::get_instance();
        TNX_Product::get_instance();
        TNX_Box_Packer::get_instance();
        TNX_Order::get_instance();
        TNX_Currency::get_instance();
        TNX_Commission::get_instance();

        // Register Shipping Method
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));

        // Disable shipping rate caching for testing/real-time updates
        add_filter('transient_shipping-transient-version', function() { return time(); });
        
        // Force recalculation when destination changes
        add_filter('woocommerce_cart_shipping_packages', array($this, 'force_shipping_recalculation'));
        add_action('woocommerce_checkout_update_order_review', array($this, 'force_refresh_shipping'));
        
        // WooCommerce Blocks / Store API support
        add_action('woocommerce_store_api_cart_update_customer_from_request', array($this, 'force_refresh_shipping'), 10, 2);
        add_action('woocommerce_blocks_loaded', array($this, 'register_block_integration'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_scripts'));

        // Global Injection: Bypass Zones entirely
        add_filter('woocommerce_package_rates', array($this, 'inject_global_rates'), 99, 2);

        // Explicitly hide shipping calculation on cart page
        add_filter('woocommerce_cart_ready_to_calc_shipping', array($this, 'maybe_hide_shipping_on_cart'), 99);
    }

    /**
     * Determine if we should hide shipping (Cart page context)
     */
    public function maybe_hide_shipping_on_cart($show) {
        $is_cart = is_cart();
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
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
        // Double check: if we are in cart context, don't inject rates
        if ($this->maybe_hide_shipping_on_cart(true) === false) {
            return $rates;
        }

        $shipping_method = new TNX_Shipping_Method();
        if ($shipping_method->enabled === 'no') {
            return $rates;
        }

        // Manually trigger calculation
        $shipping_method->calculate_shipping($package);
        $new_rates = $shipping_method->rates;

        if (!empty($new_rates)) {
            $rates = array_merge($rates, $new_rates);
        }

        return $rates;
    }

    public function force_shipping_recalculation($packages) {

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
            
            // Create a robust hash of the entire destination to force recalculation
            $packages[$i]['tnx_dest_hash'] = md5(json_encode($packages[$i]['destination']) . microtime());
        }
        return $packages;
    }

    /**
     * Force WooCommerce to recalculate shipping
     */
    public function force_refresh_shipping() {

        if (isset(WC()->cart)) {
            // Clear all shipping-related caches in session and transients
            WC()->session->set('shipping_responses', array());
            WC()->session->set('chosen_shipping_methods', array());
            
            // Force customer data to be synced
            WC()->customer->save();
            
            // Recalculate
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
        }
    }

    public function register_block_integration() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Package')) {
            return;
        }
        
        require_once TNX_PLUGIN_DIR . 'includes/class-tnx-checkout-block-integration.php';
        
        add_action('woocommerce_register_main_checkout_block_integration', function($integration_registry) {
            $integration_registry->register(new TNX_Checkout_Block_Integration());
        });

        // Expose commission breakdown to Store API (Checkout Blocks)
        add_filter('woocommerce_store_api_cart_extensions', function($extensions) {
            $extensions['tnx-shipping'] = array(
                'commission' => TNX_Commission::get_instance()->get_total_commission(),
            );
            return $extensions;
        });
    }

    /**
     * Fail-safe script enqueuing for the Checkout Block
     */
    public function enqueue_block_scripts() {
        // Enqueueing is handled by TNX_Checkout_Block_Integration for Checkout Blocks.
        // For classic checkout, we could enqueue a separate script if needed, 
        // but for now, we'll avoid duplicate registration warnings.
    }

    public function register_shipping_method($methods) {
        $methods['tnx_shipping'] = 'TNX_Shipping_Method';
        return $methods;
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Thai Nexus Logistics requires WooCommerce to be installed and active.', 'thai-nexus-logistics'); ?></p>
        </div>
        <?php
    }
}

// Start the plugin
Thai_Nexus_Logistics::get_instance();
