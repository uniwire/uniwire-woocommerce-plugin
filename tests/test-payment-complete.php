<?php
/**
 * Regression test: a confirmed/complete crypto payment must finalise the order
 * through WC_Order::payment_complete().
 *
 * Usage via WP-CLI (from the cc-woo ddev project):
 *   ddev wp eval-file web/app/plugins/cryptochill-woocommerce-plugin/tests/test-payment-complete.php
 *
 * Bug: _update_order_status() calls $order->update_status('processing') and only
 * then $order->payment_complete(). WooCommerce guards payment_complete() with
 * has_status(['on-hold','pending','failed','cancelled']), so the call is a silent
 * no-op: date_paid stays null, _transaction_id is never set and neither
 * woocommerce_payment_complete_order_status nor woocommerce_payment_complete fires.
 * The custom 'blockchainpending' status is not in that list either.
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

$gateway_id = $gateway->id;
$GLOBALS['gateway_id'] = $gateway_id;
echo "Gateway under test: {$gateway_id}\n";

function tpc_create_order($total, $status, $currency = 'EUR') {
	$order = wc_create_order();
	$product = new WC_Product_Simple();
	$product->set_name('Test Product');
	$product->set_regular_price($total);
	$product->save();
	$order->add_product($product);
	$order->set_currency($currency);
	$order->set_total($total);
	$order->set_payment_method($GLOBALS['gateway_id']);
	$order->set_status($status);
	$order->save();
	return $order;
}

function tpc_invoice($amount, $status, $currency = 'EUR') {
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

$status_filter_fired = [];
add_filter('woocommerce_payment_complete_order_status', function ($status, $order_id) use (&$status_filter_fired) {
	$status_filter_fired[] = (int) $order_id;
	return $status;
}, 10, 2);

$cases = [
	['from' => 'pending',           'invoice_status' => 'confirmed'],
	['from' => 'pending',           'invoice_status' => 'complete'],
	['from' => 'pending',           'invoice_status' => 'paid'],
	['from' => 'blockchainpending', 'invoice_status' => 'confirmed'],
	['from' => 'blockchainpending', 'invoice_status' => 'complete'],
];

$failures = 0;
$total    = 100.00;

echo "\n=== payment_complete() regression test ===\n\n";

foreach ($cases as $case) {
	$order   = tpc_create_order($total, $case['from']);
	$invoice = tpc_invoice(number_format($total, 2, '.', ''), $case['invoice_status']);
	$id      = $order->get_id();

	$gateway->_update_order_status($order, $case['invoice_status'], $invoice);

	// Reload from DB so we assert on persisted state.
	$order = wc_get_order($id);

	$checks = [
		'status is processing/completed'          => in_array($order->get_status(), ['processing', 'completed'], true),
		'date_paid is set'                        => $order->get_date_paid() !== null,
		'_transaction_id equals invoice id'       => $order->get_transaction_id() === $invoice['id'],
		'woocommerce_payment_complete fired'      => in_array($id, $payment_complete_fired, true),
		'woocommerce_payment_complete_order_status fired' => in_array($id, $status_filter_fired, true),
	];

	$case_failed = in_array(false, $checks, true);
	$failures   += $case_failed ? 1 : 0;

	printf("Order #%d  %s -> invoice '%s'  ... %s\n", $id, $case['from'], $case['invoice_status'], $case_failed ? 'FAIL' : 'PASS');
	foreach ($checks as $label => $ok) {
		printf("    [%s] %s\n", $ok ? 'x' : ' ', $label);
	}
	printf("    status=%s date_paid=%s transaction_id=%s\n\n",
		$order->get_status(),
		$order->get_date_paid() ? $order->get_date_paid()->date('c') : 'null',
		$order->get_transaction_id() ?: 'null'
	);
}

echo $failures ? "RESULT: {$failures} of " . count($cases) . " cases FAILED\n" : "RESULT: all " . count($cases) . " cases passed\n";

if (class_exists('WP_CLI')) {
	WP_CLI::halt($failures ? 1 : 0);
}
