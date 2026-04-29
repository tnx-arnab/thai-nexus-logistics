<?php
/**
 * TNX Admin Class
 */

if (!defined('ABSPATH')) exit;

class TNX_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Thai Nexus Logistics', 'thai-nexus-logistics'),
            __('TNX Logistics', 'thai-nexus-logistics'),
            'manage_options',
            'tnx-logistics',
            array($this, 'render_admin_page'),
            'dashicons-cart', // You can replace with a custom SVG icon later
            56
        );
    }

    public function render_admin_page() {
        echo '<div id="tnx-admin-root"></div>';
    }

    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_tnx-logistics' !== $hook) {
            return;
        }

        // In production, we'll load from dist/. In development, we might want to load from Vite dev server.
        // For now, let's assume production-ready dist/ folder.
        
        $manifest_path = TNX_PLUGIN_DIR . 'dist/manifest.json';
        if (!file_exists($manifest_path)) {
            $manifest_path = TNX_PLUGIN_DIR . 'dist/.vite/manifest.json';
        }
        
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            
            // Enqueue main JS
            if (isset($manifest['index.html']['file'])) {
                wp_enqueue_script(
                    'tnx-admin-js',
                    TNX_PLUGIN_URL . 'dist/' . $manifest['index.html']['file'],
                    array(),
                    TNX_VERSION,
                    true
                );
            }
            
            // Enqueue main CSS
            if (isset($manifest['index.html']['css'][0])) {
                wp_enqueue_style(
                    'tnx-admin-css',
                    TNX_PLUGIN_URL . 'dist/' . $manifest['index.html']['css'][0],
                    array(),
                    TNX_VERSION
                );
            }

            // Localize script with API data
            wp_localize_script('tnx-admin-js', 'tnxData', array(
                'apiUrl' => esc_url_raw(rest_url('tnx/v1')),
                'nonce'  => wp_create_nonce('wp_rest'),
                'assets' => TNX_PLUGIN_URL . 'assets/',
            ));
        } else {
            // Fallback/Warning if not built yet
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . __('Thai Nexus Logistics: Admin assets not found. Please run `npm run build` in the `admin` directory.', 'thai-nexus-logistics') . '</p></div>';
            });
        }
    }
}
