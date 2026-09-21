<?php
/**
 * CLI checks for checkout pricing in store currency.
 *
 * Run: php tests/test-pricing-rules.php
 */

$_SERVER['HTTP_HOST'] = 'woo.test';
require_once dirname(__DIR__, 4) . '/wp-load.php';

if (!class_exists('TNXL_Commission')) {
    fwrite(STDERR, "TNXL_Commission not loaded\n");
    exit(1);
}

$saved_rules = get_option('tnxl_commission_rules');
$saved_mode = get_option('tnxl_pricing_mode');
$rate = TNXL_Currency::get_instance()->get_rate('THB', get_woocommerce_currency());
$quote_thb = 1000.0;
$ctx = array(
    'items'              => array(array('product_id' => '1', 'quantity' => 1, 'line_total' => 200)),
    'cartSubtotal'       => 200.0,
    'destinationCountry' => 'QA',
    'cartWeightKg'       => 1.0,
    'itemQuantity'       => 1,
    'serviceIds'         => array(),
);

function tnxl_assert($ok, $label, $detail = '') {
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}";
    if ($detail !== '') {
        echo "  {$detail}";
    }
    echo "\n";
    return $ok;
}

$pass = 0;
$fail = 0;

update_option('tnxl_pricing_mode', 'basic');

// Test 1: live saved rule (pickup 30 QAR, markup 1, cart 0)
update_option('tnxl_commission_rules', array(array(
    'id' => 'rule_basic',
    'conditionType' => 'subtotal_range',
    'minRange' => 0,
    'maxRange' => 0,
    'specificProducts' => array(),
    'conditions' => array(),
    'feeType' => 'fixed',
    'feeValue' => 30,
    'markupPercent' => 0,
    'cartPercent' => 0,
    'pickupUnit' => 'once',
    'stopProcessing' => false,
    'feeLabel' => 'Commission Fee',
)));
$old = ($quote_thb + 30) * (float) $rate;
$new = TNXL_Commission::price_converted_quote($quote_thb, $rate, $ctx);
$expected = round($quote_thb * (float) $rate + 30, 2);
$ok = tnxl_assert(
    abs($new['priced'] - $expected) < 0.011 && $new['commission'] >= 29.99,
    'Test 1 pickup 30 in store currency',
    sprintf(
        'quote_thb=1000 rate=%s quote_store=%s priced=%s expected=%s old_wrong=%s commission=%s',
        $rate,
        $new['quote_store'],
        $new['priced'],
        $expected,
        round($old, 2),
        $new['commission']
    )
);
$ok ? $pass++ : $fail++;

// Test 2: (quote + 30) x 1.15
update_option('tnxl_commission_rules', array(array(
    'id' => 'rule_basic',
    'conditionType' => 'subtotal_range',
    'minRange' => 0,
    'maxRange' => 0,
    'specificProducts' => array(),
    'conditions' => array(),
    'feeType' => 'mixed',
    'feeValue' => 30,
    'markupPercent' => 15,
    'cartPercent' => 0,
    'pickupUnit' => 'once',
    'stopProcessing' => false,
    'feeLabel' => 'Pickup + markup',
)));
$new = TNXL_Commission::price_converted_quote($quote_thb, $rate, $ctx);
$expected = round(($quote_thb * (float) $rate + 30) * 1.15, 2);
$ok = tnxl_assert(
    abs($new['priced'] - $expected) < 0.02,
    'Test 2 pickup 30 then x1.15 markup',
    sprintf('priced=%s expected=%s', $new['priced'], $expected)
);
$ok ? $pass++ : $fail++;

// Test 3: pickup 0, 10% of 200 QAR cart after markup 1
update_option('tnxl_commission_rules', array(array(
    'id' => 'rule_basic',
    'conditionType' => 'subtotal_range',
    'minRange' => 0,
    'maxRange' => 0,
    'specificProducts' => array(),
    'conditions' => array(),
    'feeType' => 'percentage',
    'feeValue' => 10,
    'markupPercent' => 0,
    'cartPercent' => 0,
    'pickupUnit' => 'once',
    'stopProcessing' => false,
    'feeLabel' => 'Cart percent',
)));
$new = TNXL_Commission::price_converted_quote($quote_thb, $rate, $ctx);
$expected = round($quote_thb * (float) $rate + 20, 2);
$ok = tnxl_assert(
    abs($new['priced'] - $expected) < 0.02 && abs($new['commission'] - 20) < 0.02,
    'Test 3 add 10% of 200 QAR cart',
    sprintf('priced=%s expected=%s commission=%s', $new['priced'], $expected, $new['commission'])
);
$ok ? $pass++ : $fail++;

update_option('tnxl_commission_rules', $saved_rules);
update_option('tnxl_pricing_mode', $saved_mode);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
