<?php
/**
 * Hourly sync of TNX tracking codes and shipment status onto WooCommerce orders.
 */

if (!defined('ABSPATH')) exit;

class TNXL_Tracking_Sync {

    public const HOOK = 'tnxl_sync_shipment_status';
    public const GROUP = 'tnxl-shipping';
    public const LOCK_KEY = 'tnxl_sync_lock';
    public const BATCH_SIZE = 40;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_schedule'));
        add_action(self::HOOK, array($this, 'run'));
    }

    public function maybe_schedule(): void {
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_has_scheduled_action(self::HOOK, array(), self::GROUP)) {
                as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), self::GROUP);
            }
            return;
        }

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, array(), self::GROUP);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function run(): void {
        if (!TNXL_Settings::are_services_active() || !function_exists('wc_get_orders')) {
            return;
        }

        if (get_transient(self::LOCK_KEY)) {
            return;
        }
        set_transient(self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS);

        try {
            $this->sync_batch();
        } catch (\Throwable $e) {
            if (defined('TNXL_DEBUG') && TNXL_DEBUG) {
                error_log('TNXL tracking sync: ' . $e->getMessage());
            }
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function sync_batch(): void {
        $candidates = wc_get_orders(array(
            'limit'        => self::BATCH_SIZE * 3,
            'type'         => 'shop_order',
            'status'       => array('pending', 'processing', 'on-hold', 'completed'),
            'orderby'      => 'modified',
            'order'        => 'ASC',
            'meta_key'     => '_tnxl_request_number',
            'meta_compare' => '!=',
            'meta_value'   => '',
        ));

        if (empty($candidates) || !is_array($candidates)) {
            return;
        }

        $api = TNXL_API::get_instance();
        $synced = 0;

        foreach ($candidates as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            if ($order->get_meta('_tnxl_sync_terminal') === 'yes') {
                continue;
            }

            try {
                $this->sync_order($order, $api);
            } catch (\Throwable $e) {
                if (defined('TNXL_DEBUG') && TNXL_DEBUG) {
                    error_log('TNXL tracking sync order ' . $order->get_id() . ': ' . $e->getMessage());
                }
            }

            $synced++;
            if ($synced >= self::BATCH_SIZE) {
                break;
            }
        }
    }

    /**
     * Fetch one Thai Nexus shipment and push TNX/status onto any linked WooCommerce orders.
     *
     * @return array{success:bool,shipment:array,orders_updated:int[]}|WP_Error
     */
    public function sync_request_number(string $request_number) {
        $request_number = sanitize_text_field($request_number);
        if ($request_number === '') {
            return new WP_Error(
                'tnxl_invalid_request',
                __('A shipment request number is required.', 'thai-nexus-logistics')
            );
        }

        if (!TNXL_Settings::are_services_active()) {
            return new WP_Error(
                'tnxl_not_configured',
                __('Thai Nexus API token is not configured.', 'thai-nexus-logistics')
            );
        }

        $api = TNXL_API::get_instance();
        $response = $api->shipment_crud('get', array(
            'request_number' => $request_number,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $shipment = is_array($response['data'] ?? null) ? $response['data'] : (is_array($response) ? $response : array());
        if (!is_array($shipment)) {
            $shipment = array('request_number' => $request_number);
        }
        $shipment = TNXL_Tracking::normalize_shipment($shipment);

        $updated_ids = array();
        foreach ($this->find_orders_for_request($request_number) as $order) {
            $this->sync_order($order, $api);
            $updated_ids[] = $order->get_id();
        }

        return array(
            'success'         => true,
            'shipment'        => $shipment,
            'orders_updated'  => $updated_ids,
        );
    }

    /**
     * @return WC_Order[]
     */
    private function find_orders_for_request(string $request_number): array {
        if (!function_exists('wc_get_orders')) {
            return array();
        }

        $found = array();
        $statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded');
        $queries = array(
            array(
                'meta_key'   => '_tnxl_request_number',
                'meta_value' => $request_number,
            ),
            array(
                'meta_key'     => '_tnxl_all_shipments',
                'meta_value'   => $request_number,
                'meta_compare' => 'LIKE',
            ),
        );

        foreach ($queries as $meta) {
            $orders = wc_get_orders(array_merge(array(
                'limit'  => 20,
                'type'   => 'shop_order',
                'status' => $statuses,
            ), $meta));

            if (empty($orders) || !is_array($orders)) {
                continue;
            }

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }
                if (!$this->order_has_request_number($order, $request_number)) {
                    continue;
                }
                $found[$order->get_id()] = $order;
            }
        }

        return array_values($found);
    }

    private function order_has_request_number(WC_Order $order, string $request_number): bool {
        if ((string) $order->get_meta('_tnxl_request_number') === $request_number) {
            return true;
        }

        foreach (TNXL_Tracking::get_order_shipments($order) as $shipment) {
            if ((string) ($shipment['request_number'] ?? '') === $request_number) {
                return true;
            }
        }

        return false;
    }

    public function sync_order(WC_Order $order, ?TNXL_API $api = null): void {
        $api = $api ?: TNXL_API::get_instance();
        $shipments = $order->get_meta('_tnxl_all_shipments');
        if (!is_array($shipments) || empty($shipments)) {
            $req = (string) $order->get_meta('_tnxl_request_number');
            if ($req === '') {
                return;
            }
            $shipments = array(
                array('request_number' => $req, 'status' => (string) $order->get_meta('_tnxl_status')),
            );
        }

        $previous_status = (string) $order->get_meta('_tnxl_status');
        $updated = array();
        $had_api_error = false;

        foreach ($shipments as $index => $shipment) {
            if (!is_array($shipment) || empty($shipment['request_number'])) {
                $updated[$index] = $shipment;
                continue;
            }

            $response = $api->shipment_crud('get', array(
                'request_number' => $shipment['request_number'],
            ));

            if (is_wp_error($response)) {
                $had_api_error = true;
                $updated[$index] = $shipment;
                continue;
            }

            $fresh = is_array($response['data'] ?? null) ? $response['data'] : (is_array($response) ? $response : array());
            $updated[$index] = $this->merge_shipment($shipment, $fresh);
        }

        ksort($updated);
        $order->update_meta_data('_tnxl_all_shipments', $updated);

        $new_status = TNXL_Tracking::aggregate_status($updated);
        if ($new_status === '') {
            $new_status = $previous_status;
        }
        $tnx = TNXL_Tracking::first_tnx_code($updated);

        $order->update_meta_data('_tnxl_status', $new_status);
        if ($tnx !== '') {
            $order->update_meta_data('_tnxl_tnx_tracking_number', $tnx);
        }

        if (!$had_api_error && !empty($updated)) {
            $all_terminal = true;
            foreach ($updated as $shipment) {
                if (!is_array($shipment) || !TNXL_Tracking::is_terminal_status((string) ($shipment['status'] ?? ''))) {
                    $all_terminal = false;
                    break;
                }
            }
            if ($all_terminal) {
                $order->update_meta_data('_tnxl_sync_terminal', 'yes');
            }
        }

        $order->save();

        TNXL_Tracking::maybe_notify_new_tracking($order);
        TNXL_Tracking::maybe_notify_status_change($order, $previous_status, $new_status);

        $order = wc_get_order($order->get_id());
        if (!$order instanceof WC_Order) {
            return;
        }

        if (TNXL_Tracking::all_shipments_delivered($order) && $order->has_status(array('processing', 'on-hold'))) {
            $order->update_status(
                'completed',
                __('Thai Nexus marked all shipments as delivered.', 'thai-nexus-logistics')
            );
        }
    }

    /**
     * Overlay live API fields onto the stored shipment without dropping box data.
     */
    private function merge_shipment(array $stored, array $fresh): array {
        if (isset($fresh['data']) && is_array($fresh['data']) && empty($fresh['request_number'])) {
            $fresh = $fresh['data'];
        }

        foreach (array('status', 'customer_tracking_code', 'tnx_tracking_code', 'tnx_code', 'tracking_number', 'id') as $key) {
            if (!empty($fresh[$key]) && is_scalar($fresh[$key])) {
                $stored[$key] = $fresh[$key];
            }
        }

        $tnx = TNXL_Tracking::extract_tnx_code($fresh);
        if ($tnx === '') {
            $tnx = TNXL_Tracking::extract_tnx_code($stored);
        }
        if ($tnx !== '') {
            $stored['tnx_tracking_number'] = $tnx;
            $stored['customer_tracking_code'] = $stored['customer_tracking_code'] ?? $tnx;
        }

        return TNXL_Tracking::normalize_shipment($stored);
    }
}
