<?php
/**
 * Public TNX tracking helpers (URL, extraction, customer display, notes).
 */

if (!defined('ABSPATH')) exit;

class TNXL_Tracking {

    public const TRACKING_BASE = 'https://tracking.thainexus.co.th/track/';

    private const TNX_PATTERN = '/^TNX[A-Z0-9]+$/i';

    private const CUSTOMER_EMAIL_IDS = array(
        'customer_processing_order',
        'customer_completed_order',
    );

    /**
     * Public live-tracking URL for a TNX code.
     */
    public static function get_tracking_url(string $tnx): string {
        $tnx = strtoupper(trim($tnx));
        if ($tnx === '') {
            return '';
        }
        return self::TRACKING_BASE . rawurlencode($tnx);
    }

    public static function is_tnx_code(string $value): bool {
        return (bool) preg_match(self::TNX_PATTERN, strtoupper(trim($value)));
    }

    /**
     * Pull a TNX code from a shipment entity or nested data blob.
     */
    public static function extract_tnx_code($shipment): string {
        if (!is_array($shipment)) {
            return '';
        }

        $keys = array(
            'tnx_tracking_number',
            'customer_tracking_code',
            'tnx_tracking_code',
            'tnx_code',
            'tracking_number',
        );

        $sources = array($shipment);
        if (isset($shipment['data']) && is_array($shipment['data'])) {
            $sources[] = $shipment['data'];
        }

        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (empty($source[$key]) || !is_scalar($source[$key])) {
                    continue;
                }
                $candidate = strtoupper(trim((string) $source[$key]));
                if (self::is_tnx_code($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * Add tnx_tracking_number and tracking_url when a TNX code is present.
     */
    public static function normalize_shipment($shipment) {
        if (!is_array($shipment)) {
            return $shipment;
        }

        $tnx = self::extract_tnx_code($shipment);
        if ($tnx === '') {
            return $shipment;
        }

        $shipment['tnx_tracking_number'] = $tnx;
        $shipment['tracking_url'] = self::get_tracking_url($tnx);

        return $shipment;
    }

    /**
     * @return array<int, array>
     */
    public static function get_order_shipments(WC_Order $order): array {
        $all = $order->get_meta('_tnxl_all_shipments');
        if (!is_array($all) || empty($all)) {
            $req = (string) $order->get_meta('_tnxl_request_number');
            if ($req === '') {
                return array();
            }
            return array(
                array(
                    'request_number'      => $req,
                    'status'              => (string) $order->get_meta('_tnxl_status'),
                    'tnx_tracking_number' => (string) $order->get_meta('_tnxl_tnx_tracking_number'),
                ),
            );
        }

        $shipments = array();
        foreach ($all as $shipment) {
            if (is_array($shipment)) {
                $shipments[] = $shipment;
            }
        }
        return $shipments;
    }

    /**
     * Unique TNX codes stored on the order.
     *
     * @return string[]
     */
    public static function get_order_tnx_codes(WC_Order $order): array {
        $codes = array();
        foreach (self::get_order_shipments($order) as $shipment) {
            $tnx = self::extract_tnx_code($shipment);
            if ($tnx !== '') {
                $codes[$tnx] = $tnx;
            }
        }

        $legacy = strtoupper(trim((string) $order->get_meta('_tnxl_tnx_tracking_number')));
        if (self::is_tnx_code($legacy)) {
            $codes[$legacy] = $legacy;
        }

        return array_values($codes);
    }

    public static function normalize_status(string $status): string {
        return strtolower(str_replace(array(' ', '-'), '_', trim($status)));
    }

    public static function is_terminal_status(string $status): bool {
        $normalized = self::normalize_status($status);
        return in_array(
            $normalized,
            array('delivered', 'cancelled', 'canceled', 'lost', 'confiscated', 'returned'),
            true
        );
    }

    public static function is_delivered_status(string $status): bool {
        return str_contains(self::normalize_status($status), 'deliver');
    }

    public static function all_shipments_delivered(WC_Order $order): bool {
        $shipments = self::get_order_shipments($order);
        if (empty($shipments)) {
            return false;
        }
        foreach ($shipments as $shipment) {
            if (!self::is_delivered_status((string) ($shipment['status'] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    public static function all_shipments_terminal(WC_Order $order): bool {
        $shipments = self::get_order_shipments($order);
        if (empty($shipments)) {
            return false;
        }
        foreach ($shipments as $shipment) {
            if (!self::is_terminal_status((string) ($shipment['status'] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Least-complete status across boxes so one delivered box cannot hide an in-transit box.
     */
    public static function aggregate_status(array $shipments): string {
        $chosen = '';
        $chosen_rank = PHP_INT_MAX;

        foreach ($shipments as $shipment) {
            if (!is_array($shipment)) {
                continue;
            }
            $status = trim((string) ($shipment['status'] ?? ''));
            if ($status === '') {
                continue;
            }
            $rank = self::status_progress_rank($status);
            if ($rank < $chosen_rank) {
                $chosen_rank = $rank;
                $chosen = $status;
            }
        }

        return $chosen;
    }

    public static function first_tnx_code(array $shipments): string {
        foreach ($shipments as $shipment) {
            if (!is_array($shipment)) {
                continue;
            }
            $tnx = self::extract_tnx_code($shipment);
            if ($tnx !== '') {
                return $tnx;
            }
        }
        return '';
    }

    private static function status_progress_rank(string $status): int {
        $normalized = self::normalize_status($status);
        if (str_contains($normalized, 'pending') || str_contains($normalized, 'submit')) {
            return 0;
        }
        if (str_contains($normalized, 'process')) {
            return 1;
        }
        if (str_contains($normalized, 'transit')) {
            return 2;
        }
        if (str_contains($normalized, 'deliver')) {
            return 3;
        }
        return 4;
    }

    /**
     * Customer-facing tracking block. Renders nothing until a TNX code exists.
     */
    public static function render_customer_tracking(WC_Order $order, bool $plain_text = false): void {
        $codes = self::get_order_tnx_codes($order);
        if (empty($codes)) {
            return;
        }

        $status = trim((string) $order->get_meta('_tnxl_status'));

        if ($plain_text) {
            echo "\n" . esc_html__('Shipment tracking', 'thai-nexus-logistics') . "\n";
            if ($status !== '') {
                echo esc_html__('Status:', 'thai-nexus-logistics') . ' ' . esc_html($status) . "\n";
            }
            foreach ($codes as $tnx) {
                echo $tnx . ' - ' . self::get_tracking_url($tnx) . "\n";
            }
            echo "\n";
            return;
        }

        echo '<section class="tnxl-order-tracking" style="margin: 1.5em 0;">';
        echo '<h2>' . esc_html__('Shipment tracking', 'thai-nexus-logistics') . '</h2>';
        if ($status !== '') {
            echo '<p><strong>' . esc_html__('Status:', 'thai-nexus-logistics') . '</strong> ' . esc_html($status) . '</p>';
        }
        echo '<ul style="list-style: none; padding: 0; margin: 0;">';
        foreach ($codes as $tnx) {
            $url = self::get_tracking_url($tnx);
            echo '<li style="margin: 0.5em 0;">';
            echo '<code>' . esc_html($tnx) . '</code> ';
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html__('Track shipment', 'thai-nexus-logistics');
            echo '</a>';
            echo '</li>';
        }
        echo '</ul></section>';
    }

    /**
     * HTML tracking block for WooCommerce emails.
     */
    public static function render_email_tracking(WC_Order $order, bool $plain_text = false): void {
        $codes = self::get_order_tnx_codes($order);
        if (empty($codes)) {
            return;
        }

        if ($plain_text) {
            self::render_customer_tracking($order, true);
            return;
        }

        $status = trim((string) $order->get_meta('_tnxl_status'));
        $text_align = is_rtl() ? 'right' : 'left';

        echo '<div style="margin-bottom: 40px;">';
        echo '<h2>' . esc_html__('Shipment tracking', 'thai-nexus-logistics') . '</h2>';
        echo '<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">';
        echo '<thead><tr>';
        echo '<th style="text-align:' . esc_attr($text_align) . ';">' . esc_html__('Tracking number', 'thai-nexus-logistics') . '</th>';
        echo '<th style="text-align:' . esc_attr($text_align) . ';">' . esc_html__('Track', 'thai-nexus-logistics') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($codes as $tnx) {
            $url = self::get_tracking_url($tnx);
            echo '<tr>';
            echo '<td style="text-align:' . esc_attr($text_align) . ';"><code>' . esc_html($tnx) . '</code></td>';
            echo '<td style="text-align:' . esc_attr($text_align) . ';"><a href="' . esc_url($url) . '">' . esc_html($url) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ($status !== '') {
            echo '<p><strong>' . esc_html__('Status:', 'thai-nexus-logistics') . '</strong> ' . esc_html($status) . '</p>';
        }
        echo '</div>';
    }

    public static function is_customer_status_email($email): bool {
        $id = is_object($email) ? (string) ($email->id ?? '') : '';
        return in_array($id, self::CUSTOMER_EMAIL_IDS, true);
    }

    /**
     * Customer note for newly stored TNX codes. Guarded so retries do not spam.
     */
    public static function maybe_notify_new_tracking(WC_Order $order): void {
        $codes = self::get_order_tnx_codes($order);
        if (empty($codes)) {
            return;
        }

        $notified = $order->get_meta('_tnxl_tracking_notified');
        $notified = is_array($notified) ? array_map('strval', $notified) : array();
        $new_codes = array_values(array_diff($codes, $notified));
        if (empty($new_codes)) {
            return;
        }

        $lines = array(
            __('Your Thai Nexus tracking number is ready:', 'thai-nexus-logistics'),
        );
        foreach ($new_codes as $tnx) {
            $lines[] = $tnx . ' - ' . self::get_tracking_url($tnx);
        }

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(implode("\n", $lines), true);
        }

        $order->update_meta_data('_tnxl_tracking_notified', array_values(array_unique(array_merge($notified, $new_codes))));
        $order->save();
    }

    /**
     * Admin note on status change. One customer note when every box is delivered.
     */
    public static function maybe_notify_status_change(WC_Order $order, string $previous_status, string $new_status): void {
        $previous_status = self::normalize_status($previous_status);
        $new_status = self::normalize_status($new_status);
        $changed = $new_status !== '' && $previous_status !== $new_status;
        $dirty = false;

        if ($changed && method_exists($order, 'add_order_note')) {
            $order->add_order_note(sprintf(
                /* translators: 1: previous status, 2: new status */
                __('Thai Nexus status updated: %1$s -> %2$s', 'thai-nexus-logistics'),
                $previous_status !== '' ? $previous_status : __('unknown', 'thai-nexus-logistics'),
                $new_status
            ));
            $dirty = true;
        }

        if (self::all_shipments_delivered($order) && $order->get_meta('_tnxl_delivered_notified') !== 'yes') {
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(
                    __('Your Thai Nexus shipment has been delivered.', 'thai-nexus-logistics'),
                    true
                );
            }
            $order->update_meta_data('_tnxl_delivered_notified', 'yes');
            $dirty = true;
        }

        if ($dirty) {
            $order->save();
        }
    }
}
