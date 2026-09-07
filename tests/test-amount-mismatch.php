<?php
/**
 * Simulate the amount manipulation attack.
 *
 * Usage via WP-CLI:
 *   ddev wp eval-file /var/www/html/web/app/plugins/cryptochill-woocommerce-plugin/tests/test-amount-mismatch.php
 *
 * This creates two test orders and simulates a webhook callback where the invoice
 * amount differs from the order total (the attack vector from the security audit).
 *
 * TEST 1 (pre-v0.5 behavior): Amount check DISABLED - order goes to "processing" (VULNERABLE)
 * TEST 2 (v0.5 behavior):     Amount check ENABLED  - order goes to "on-hold" (PROTECTED)
 */

if (!defined('ABSPATH')) {
	exit;
}

echo "\n";
echo "========================================================\n";
echo "  SECURITY AUDIT: Amount Manipulation Attack Simulation\n";
echo "========================================================\n\n";

// ── helpers ──

function create_test_order($total, $currency = 'EUR') {
	$order = wc_create_order();
	$product = new WC_Product_Simple();
	$product->set_name('Test Product');
	$product->set_regular_price($total);
	$product->save();
	$order->add_product($product);
	$order->set_currency($currency);
	$order->set_total($total);
	$order->set_payment_method($GLOBALS['gateway_id']);
	$order->set_status('pending');
	$order->update_meta_data('_merchant_payment_id', 'test-invoice-' . $order->get_id());
	$order->save();
	return $order;
}

function make_fake_invoice($amount, $currency = 'EUR') {
	return [
		'id'     => 'fake-invoice-' . rand(1000, 9999),
		'status' => 'confirmed',
		'amount' => [
			'requested' => [
				'amount'   => $amount,
				'currency' => $currency,
			],
		],
		'passthrough' => '{}',
	];
}

// Get gateway instance
$gateway = null;
foreach (WC()->payment_gateways()->payment_gateways() as $candidate) {
	if (method_exists($candidate, '_update_order_status')) {
		$gateway = $candidate;
		break;
	}
}

if (!$gateway) {
	echo "FATAL: gateway with _update_order_status() not found — is the plugin active?\n";
	if (class_exists('WP_CLI')) {
		WP_CLI::halt(1);
	}
	return;
}

$GLOBALS['gateway_id'] = $gateway->id;

$order_total    = 1267.65; // Real order total
$attack_amount  = 1.00;    // Attacker-manipulated invoice amount

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 1: Pre-v0.5 (WITHOUT amount check) — VULNERABLE
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 1: Pre-v0.5 behavior (WITHOUT amount verification)\n";
echo "--------------------------------------------------------\n";

$order1 = create_test_order($order_total);
echo "  Created Order #{$order1->get_id()} — Total: {$order_total} EUR\n";
echo "  Attacker manipulates SDK amount to: {$attack_amount} EUR\n";
echo "  Simulating webhook with invoice amount: {$attack_amount} EUR ...\n";

// Simulate pre-v0.5: skip the amount check, just mark as processing
$order1->update_meta_data('_merchant_status', 'confirmed');
$order1->update_status('processing', 'Payment confirmed (NO amount check — pre-v0.5)');
$order1->payment_complete();
$order1->save();

$status1 = $order1->get_status();
echo "\n";
echo "  RESULT: Order #{$order1->get_id()} status = \"{$status1}\"\n";
echo "  ** VULNERABLE: Order completed with \${$attack_amount} payment for \${$order_total} order! **\n";
echo "\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 2: v0.5+ (WITH amount check) — PROTECTED
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 2: v0.5 behavior (WITH amount verification)\n";
echo "-------------------------------------------------\n";

$order2 = create_test_order($order_total);
echo "  Created Order #{$order2->get_id()} — Total: {$order_total} EUR\n";
echo "  Attacker manipulates SDK amount to: {$attack_amount} EUR\n";
echo "  Simulating webhook with invoice amount: {$attack_amount} EUR ...\n";

// Use actual gateway code path (v0.5 with amount check)
$fake_invoice = make_fake_invoice($attack_amount, 'EUR');
$gateway->_update_order_status($order2, 'confirmed', $fake_invoice);

$status2 = $order2->get_status();
echo "\n";
echo "  RESULT: Order #{$order2->get_id()} status = \"{$status2}\"\n";

$notes = wc_get_order_notes(['order_id' => $order2->get_id(), 'limit' => 3]);
foreach ($notes as $note) {
	echo "  Note: {$note->content}\n";
}

if ($status2 === 'on-hold') {
	echo "  ** PROTECTED: Attack blocked! Order placed on-hold for manual review. **\n";
} else {
	echo "  ** WARNING: Order was NOT blocked! Status: {$status2} **\n";
}

echo "\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 3: Legitimate payment (amounts match) — should succeed
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 3: Legitimate payment (amounts match)\n";
echo "--------------------------------------------\n";

$order3 = create_test_order($order_total);
echo "  Created Order #{$order3->get_id()} — Total: {$order_total} EUR\n";
echo "  Invoice amount matches: {$order_total} EUR\n";

$legit_invoice = make_fake_invoice($order_total, 'EUR');
$gateway->_update_order_status($order3, 'confirmed', $legit_invoice);

$status3 = $order3->get_status();
echo "\n";
echo "  RESULT: Order #{$order3->get_id()} status = \"{$status3}\"\n";

if ($status3 === 'processing') {
	echo "  ** CORRECT: Legitimate payment processed successfully. **\n";
} else {
	echo "  ** ISSUE: Expected 'processing', got '{$status3}' **\n";
}

echo "\n";
echo "========================================================\n";
echo "  SUMMARY\n";
echo "========================================================\n";
echo "  Order #{$order1->get_id()} (pre-v0.5, no check):  {$status1}   <-- VULNERABLE\n";
echo "  Order #{$order2->get_id()} (v0.5, amount check):   {$status2}    <-- PROTECTED\n";
echo "  Order #{$order3->get_id()} (legit payment):         {$status3} <-- OK\n";
echo "========================================================\n\n";
