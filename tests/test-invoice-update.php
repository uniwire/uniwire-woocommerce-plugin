<?php
/**
 * Test: the rules for the "update_invoice" request from the checkout page.
 *
 * Usage via WP-CLI (from the cc-woo ddev project):
 *   ./.superset/test.sh invoice-update
 *
 * The request comes from the buyer's browser and stores the invoice id that
 * check_orders() later polls and applies to the order. It must be accepted only
 * with the order key, for an order of this gateway that still waits for payment,
 * and only for an invoice id that looks like a UUID.
 *
 * Exit code is non-zero when any case fails.
 */

if (!defined('ABSPATH')) {
	exit;
}

// Resolve the gateway without hard-coding its id: the build renames 'merchant_plugin'.
$gateway = null;
foreach (WC()->payment_gateways()->payment_gateways() as $candidate) {
	if (method_exists($candidate, 'handle_invoice_update')) {
		$gateway = $candidate;
		break;
	}
}

if (!$gateway) {
	echo "FATAL: gateway with handle_invoice_update() not found — is the plugin active?\n";
	if (class_exists('WP_CLI')) {
		WP_CLI::halt(1);
	}
	return;
}

echo "Gateway under test: {$gateway->id}\n";

function tiu_create_order($status, $payment_method) {
	$order = wc_create_order();
	$order->set_total(10);
	$order->set_payment_method($payment_method);
	$order->set_status($status);
	$order->update_meta_data('_merchant_payment_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
	$order->save();

	return $order;
}

$new_invoice = '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

// label => [order status, payment method, request overrides, expect stored]
$cases = [
	'valid request on pending order'      => ['pending', $gateway->id, [], true],
	'valid request on blockchainpending'  => ['blockchainpending', $gateway->id, [], true],
	'order id as string'                  => ['pending', $gateway->id, ['order_id' => 'string'], true],
	'invoice id without hyphens'          => ['pending', $gateway->id, ['invoice_id' => str_replace('-', '', $new_invoice)], true],
	'missing order key'                   => ['pending', $gateway->id, ['order_key' => null], false],
	'empty order key'                     => ['pending', $gateway->id, ['order_key' => ''], false],
	'wrong order key'                     => ['pending', $gateway->id, ['order_key' => 'wc_order_wrong'], false],
	'order key as array'                  => ['pending', $gateway->id, ['order_key' => ['x']], false],
	'other payment method'                => ['pending', 'bacs', [], false],
	'order already processing'            => ['processing', $gateway->id, [], false],
	'order on-hold'                       => ['on-hold', $gateway->id, [], false],
	'order cancelled'                     => ['cancelled', $gateway->id, [], false],
	'invoice id not a UUID'               => ['pending', $gateway->id, ['invoice_id' => 'test-invoice-1'], false],
	'invoice id too long'                 => ['pending', $gateway->id, ['invoice_id' => $new_invoice . 'a'], false],
	'invoice id as number'                => ['pending', $gateway->id, ['invoice_id' => 12345], false],
	'unknown order id'                    => ['pending', $gateway->id, ['order_id' => 999999999], false],
];

$failures = 0;

foreach ($cases as $label => $case) {
	list($status, $method, $overrides, $expect_stored) = $case;
	$order = tiu_create_order($status, $method);
	$before = $order->get_meta('_merchant_payment_id');

	$request = [
		'action'     => 'update_invoice',
		'invoice_id' => $new_invoice,
		'order_id'   => $order->get_id(),
		'order_key'  => $order->get_order_key(),
	];
	foreach ($overrides as $key => $value) {
		if ($key === 'order_id' && $value === 'string') {
			$value = (string) $order->get_id();
		}
		if ($value === null) {
			unset($request[$key]);
		} else {
			$request[$key] = $value;
		}
	}

	$result = $gateway->handle_invoice_update($request);

	$after = wc_get_order($order->get_id())->get_meta('_merchant_payment_id');
	$expected_after = $expect_stored ? $request['invoice_id'] : $before;
	$ok = $after === $expected_after && (strpos($result, 'refused') === 0) === !$expect_stored;
	$failures += $ok ? 0 : 1;

	printf("%s  %s -> %s\n", $ok ? 'PASS' : 'FAIL', $label, $result);
	$order->delete(true);
}

echo $failures ? "RESULT: {$failures} of " . count($cases) . " cases FAILED\n" : "RESULT: all " . count($cases) . " cases passed\n";

if (class_exists('WP_CLI')) {
	WP_CLI::halt($failures ? 1 : 0);
}
