<?php
/**
 * Regression test: an order left unfinalised by an earlier faulty release must be
 * recoverable by re-sending the same callback.
 *
 * Usage via WP-CLI:
 *   ddev wp eval-file <plugin path>/tests/test-stuck-order-recovery.php
 *
 * Background: releases before 0.8 called update_status('processing') before
 * payment_complete(), so payment was never finalised — no _transaction_id and no
 * woocommerce_payment_complete. Those orders still carry _merchant_status =
 * complete/confirmed, so _update_order_status() sees $status === $prev_status and
 * skips the whole block. Re-sending the callback therefore cannot repair them, and
 * the cron poller only looks at pending/blockchainpending orders, so it skips them
 * too. Such an order stays "Processing" with no payment recorded, forever.
 *
 * Exit code is non-zero when any assertion fails.
 */

if (!defined('ABSPATH')) {
	exit;
}

// Resolve the gateway without hard-coding its id: the build process renames
// 'merchant_plugin' to a provider-specific id, so look it up by capability.
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
echo "Gateway under test: {$GLOBALS['gateway_id']}\n";

/**
 * Build an order in the exact state a pre-0.8 release left behind:
 * moved to "processing" but with no payment recorded.
 */
function tsor_create_stuck_order($total, $merchant_status, $currency = 'USD') {
	$order = wc_create_order();
	$product = new WC_Product_Simple();
	$product->set_name('Test Product');
	$product->set_regular_price($total);
	$product->save();
	$order->add_product($product);
	$order->set_currency($currency);
	$order->set_total($total);
	$order->set_payment_method($GLOBALS['gateway_id']);
	$order->set_status('processing');
	$order->update_meta_data('_merchant_status', $merchant_status);
	$order->save();
	return $order;
}

function tsor_invoice($amount, $status, $currency = 'USD') {
	return [
		'id'          => 'test-invoice-' . wp_generate_uuid4(),
		'status'      => $status,
		'amount'      => [
			'requested' => ['amount' => $amount, 'currency' => $currency],
		],
		'passthrough' => '{}',
	];
}

$payment_complete_fired = [];
add_action('woocommerce_payment_complete', function ($order_id) use (&$payment_complete_fired) {
	$payment_complete_fired[] = (int) $order_id;
}, 10, 1);

$total = 712.81;

// Each case re-sends a callback carrying the status the order is already marked with.
$cases = [
	['merchant_status' => 'complete',  'callback_status' => 'complete'],
	['merchant_status' => 'confirmed', 'callback_status' => 'confirmed'],
	['merchant_status' => 'paid',      'callback_status' => 'paid'],
];

$failures = 0;

echo "\n=== stuck order recovery test ===\n\n";

foreach ($cases as $case) {
	$order   = tsor_create_stuck_order($total, $case['merchant_status']);
	$invoice = tsor_invoice(number_format($total, 2, '.', ''), $case['callback_status']);
	$id      = $order->get_id();

	// Sanity: the order must start out unfinalised, otherwise the test proves nothing.
	// Note WooCommerce sets date_paid itself when an order moves to "processing", so the
	// missing transaction id is what marks a payment that was never completed.
	if ($order->get_transaction_id()) {
		printf("Order #%d  SETUP ERROR: order already has a transaction id before the callback\n", $id);
		$failures++;
		continue;
	}

	$gateway->_update_order_status($order, $case['callback_status'], $invoice);

	// Reload from DB so we assert on persisted state.
	$order = wc_get_order($id);

	$checks = [
		'status is processing/completed'     => in_array($order->get_status(), ['processing', 'completed'], true),
		'date_paid is set'                   => $order->get_date_paid() !== null,
		'_transaction_id equals invoice id'  => $order->get_transaction_id() === $invoice['id'],
		'woocommerce_payment_complete fired' => in_array($id, $payment_complete_fired, true),
	];

	$case_failed = in_array(false, $checks, true);
	$failures   += $case_failed ? 1 : 0;

	printf("Order #%d  stuck at _merchant_status '%s', callback re-sends '%s'  ... %s\n",
		$id, $case['merchant_status'], $case['callback_status'], $case_failed ? 'FAIL' : 'PASS');
	foreach ($checks as $label => $ok) {
		printf("    [%s] %s\n", $ok ? 'x' : ' ', $label);
	}
	printf("    status=%s date_paid=%s transaction_id=%s\n\n",
		$order->get_status(),
		$order->get_date_paid() ? $order->get_date_paid()->date('c') : 'null',
		$order->get_transaction_id() ?: 'null'
	);
}

// Idempotency: finalising twice must fire woocommerce_payment_complete only once.
echo "Guard: re-processing a finalised order must not fire payment_complete twice\n";
$guard_order   = tsor_create_stuck_order($total, 'complete');
$guard_invoice = tsor_invoice(number_format($total, 2, '.', ''), 'complete');
$guard_id      = $guard_order->get_id();

$gateway->_update_order_status(wc_get_order($guard_id), 'complete', $guard_invoice);
$fired_once = count(array_keys($payment_complete_fired, $guard_id, true));

$gateway->_update_order_status(wc_get_order($guard_id), 'complete', $guard_invoice);
$fired_twice = count(array_keys($payment_complete_fired, $guard_id, true));

$guard_ok = ($fired_once === 1 && $fired_twice === 1);
$failures += $guard_ok ? 0 : 1;
printf("Order #%d  ... %s (payment_complete fired %d time(s) after first call, %d after second)\n\n",
	$guard_id, $guard_ok ? 'PASS' : 'FAIL', $fired_once, $fired_twice);

// An order an admin already completed by hand must never be downgraded by a late callback.
// This is the realistic repair path for orders damaged by the old release, so a re-sent
// callback has to record the payment without moving the order backwards.
echo "Guard: a manually completed order must not be downgraded\n";
$done_order = tsor_create_stuck_order($total, 'complete');
$done_id    = $done_order->get_id();
$done_order->set_status('completed');
$done_order->save();
$done_invoice = tsor_invoice(number_format($total, 2, '.', ''), 'complete');

$gateway->_update_order_status(wc_get_order($done_id), 'complete', $done_invoice);

$done_order = wc_get_order($done_id);
$done_ok = ($done_order->get_status() === 'completed');
$failures += $done_ok ? 0 : 1;
printf("Order #%d  ... %s (status after callback: %s, transaction_id=%s)\n\n",
	$done_id, $done_ok ? 'PASS' : 'FAIL', $done_order->get_status(),
	$done_order->get_transaction_id() ?: 'null');

$total_cases = count($cases) + 2;
echo $failures ? "RESULT: {$failures} of {$total_cases} cases FAILED\n" : "RESULT: all {$total_cases} cases passed\n";

if (class_exists('WP_CLI')) {
	WP_CLI::halt($failures ? 1 : 0);
}
