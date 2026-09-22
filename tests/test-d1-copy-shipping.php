<?php
/**
 * CLI checks for selected checkout shipping on D1 shipment copies.
 *
 * Run: php tests/test-d1-copy-shipping.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/class-tnxl-d1-copy.php';

function tnxl_assert($ok, $label, $detail = '') {
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}";
    if ($detail !== '') {
        echo "  {$detail}";
    }
    echo "\n";
    return $ok;
}

class TNXL_Fake_Shipping_Method {
    private $id;
    private $name;
    private $title;

    public function __construct($id, $name, $title = '') {
        $this->id = $id;
        $this->name = $name;
        $this->title = $title;
    }

    public function get_meta($key) {
        if ($key === 'tnxl_courier') {
            return $this->id;
        }
        if ($key === 'tnxl_courier_display') {
            return $this->name;
        }
        return '';
    }

    public function get_method_title() {
        return $this->title;
    }
}

class TNXL_Fake_Order {
    public function get_id() {
        return 42;
    }

    public function get_shipping_total() {
        return '185.50';
    }

    public function get_currency() {
        return 'THB';
    }

    public function get_shipping_methods() {
        return array(new TNXL_Fake_Shipping_Method('flex_dap', 'Flex DAP', 'Thai Nexus Express (Flex DAP)'));
    }
}

$pass = 0;
$fail = 0;

$selected = TNXL_D1_Copy::selected_shipping_from_order(new TNXL_Fake_Order());
$ok = tnxl_assert(
    $selected['shipping_amount'] === 185.5
        && $selected['shipping_currency'] === 'THB'
        && $selected['selected_courier'] === 'flex_dap'
        && $selected['selected_courier_title'] === 'Flex DAP',
    'selected shipping from Woo order',
    json_encode($selected)
);
$ok ? $pass++ : $fail++;

$empty = TNXL_D1_Copy::selected_shipping_from_order(null);
$ok = tnxl_assert(
    $empty['shipping_amount'] === null && $empty['selected_courier'] === null,
    'null order has no selected shipping'
);
$ok ? $pass++ : $fail++;

echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
