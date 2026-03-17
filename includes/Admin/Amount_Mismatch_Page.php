<?php

if (!defined('ABSPATH')) {
	exit;
}

class Uniwire_Amount_Mismatch_Page {

	private static $per_page = 20;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'add_menu_page']);
	}

	public static function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			__('Payment Amount Audit', 'wc_uniwire_gateway'),
			__('Payment Audit', 'wc_uniwire_gateway'),
			'manage_woocommerce',
			'merchant-amount-audit',
			[__CLASS__, 'render_page']
		);
	}

	public static function render_page() {
		$scan_requested = isset($_POST['merchant_scan_orders']) && wp_verify_nonce($_POST['_wpnonce'], 'merchant_scan_orders');

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Payment Amount Audit', 'wc_uniwire_gateway') . '</h1>';

		// Debug logging status
		self::render_debug_logging_status();

		// On-hold orders with amount mismatch (flagged by v0.5+)
		self::render_flagged_orders();

		// Scan tool for pre-v0.5 orders
		self::render_scan_tool($scan_requested);

		echo '</div>';
	}

	private static function render_debug_logging_status() {
		$gateway_settings = get_option('woocommerce_wc_uniwire_gateway_settings', []);
		$debug_enabled = isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes';

		echo '<div class="card" style="max-width:800px;margin-bottom:20px;padding:15px;">';
		echo '<h2>' . esc_html__('Debug Logging', 'wc_uniwire_gateway') . '</h2>';

		if ($debug_enabled) {
			$log_path = WC_Log_Handler_File::get_log_file_path('wc_uniwire_gateway');
			echo '<p style="color:green;"><strong>' . esc_html__('Enabled', 'wc_uniwire_gateway') . '</strong></p>';
			echo '<p>' . sprintf(
				esc_html__('Log file: %s', 'wc_uniwire_gateway'),
				'<code>' . esc_html($log_path) . '</code>'
			) . '</p>';
		} else {
			$settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_uniwire_gateway');
			echo '<p style="color:#d63638;"><strong>' . esc_html__('Disabled', 'wc_uniwire_gateway') . '</strong></p>';
			echo '<p>' . sprintf(
				esc_html__('Enable debug logging in %s to monitor for manipulation attempts.', 'wc_uniwire_gateway'),
				'<a href="' . esc_url($settings_url) . '">' . esc_html__('Gateway Settings', 'wc_uniwire_gateway') . '</a>'
			) . '</p>';
		}

		echo '</div>';
	}

	private static function render_flagged_orders() {
		// Orders placed on-hold by v0.5 amount mismatch check
		$on_hold_orders = wc_get_orders([
			'status'     => 'on-hold',
			'payment_method' => 'wc_uniwire_gateway',
			'limit'      => 50,
			'orderby'    => 'date',
			'order'      => 'DESC',
		]);

		$flagged = [];
		foreach ($on_hold_orders as $order) {
			$notes = wc_get_order_notes(['order_id' => $order->get_id()]);
			foreach ($notes as $note) {
				if (strpos($note->content, 'amount mismatch') !== false) {
					$flagged[] = $order;
					break;
				}
			}
		}

		echo '<div class="card" style="max-width:800px;margin-bottom:20px;padding:15px;">';
		echo '<h2>' . esc_html__('Flagged Orders (v0.5+ amount mismatch)', 'wc_uniwire_gateway') . '</h2>';

		if (empty($flagged)) {
			echo '<p>' . esc_html__('No orders have been flagged for amount mismatch.', 'wc_uniwire_gateway') . '</p>';
		} else {
			echo '<p style="color:#d63638;"><strong>' . sprintf(
				esc_html__('%d order(s) flagged for amount mismatch:', 'wc_uniwire_gateway'),
				count($flagged)
			) . '</strong></p>';
			self::render_orders_table($flagged);
		}

		echo '</div>';
	}

	private static function render_scan_tool($scan_requested) {
		echo '<div class="card" style="max-width:800px;margin-bottom:20px;padding:15px;">';
		echo '<h2>' . esc_html__('Scan Completed Orders (pre-v0.5 audit)', 'wc_uniwire_gateway') . '</h2>';
		echo '<p>' . esc_html__('Scan completed orders to check if the invoice amount on the payment backend matches the WooCommerce order total. This helps identify orders that may have been affected by the amount manipulation vulnerability in versions prior to v0.5.', 'wc_uniwire_gateway') . '</p>';

		echo '<form method="post">';
		wp_nonce_field('merchant_scan_orders');
		echo '<p>';
		echo '<label for="merchant_scan_limit">' . esc_html__('Number of orders to scan:', 'wc_uniwire_gateway') . ' </label>';
		echo '<input type="number" id="merchant_scan_limit" name="merchant_scan_limit" value="50" min="1" max="500" style="width:80px;" />';
		echo '</p>';
		submit_button(__('Scan Orders', 'wc_uniwire_gateway'), 'primary', 'merchant_scan_orders');
		echo '</form>';

		if ($scan_requested) {
			self::run_scan(absint($_POST['merchant_scan_limit'] ?? 50));
		}

		echo '</div>';
	}

	private static function run_scan($limit) {
		include_once dirname(dirname(__FILE__)) . '/class-merchant-sdk-handler.php';
		Uniwire_SDK_Handler::$log = 'WC_Uniwire_Gateway::log';

		// Get completed/processing orders paid via our gateway
		$orders = wc_get_orders([
			'status'         => ['completed', 'processing'],
			'payment_method' => 'wc_uniwire_gateway',
			'limit'          => min($limit, 500),
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		if (empty($orders)) {
			echo '<p>' . esc_html__('No completed orders found for this payment gateway.', 'wc_uniwire_gateway') . '</p>';
			return;
		}

		$mismatches = [];
		$errors = 0;
		$checked = 0;

		echo '<h3>' . sprintf(esc_html__('Scanning %d orders...', 'wc_uniwire_gateway'), count($orders)) . '</h3>';

		foreach ($orders as $order) {
			$payment_id = $order->get_meta('_merchant_payment_id');
			if (empty($payment_id)) {
				continue;
			}

			$checked++;
			usleep(200000); // rate limit

			$response = Uniwire_SDK_Handler::send_request('invoices/status', ['id' => $payment_id], 'POST');

			if (!$response[0]) {
				$errors++;
				continue;
			}

			$invoice = $response[1]['invoice'];
			$order_total = (float) $order->get_total();
			$order_currency = $order->get_currency();

			$req_amount = isset($invoice['amount']['requested']['amount'])
				? (float) $invoice['amount']['requested']['amount']
				: null;
			$req_currency = isset($invoice['amount']['requested']['currency'])
				? $invoice['amount']['requested']['currency']
				: null;

			if ($req_amount === null) {
				$errors++;
				continue;
			}

			$diff = abs($req_amount - $order_total);
			$tolerance = $order_total * 0.01;

			if ($order_total > 0 ? ($diff > $tolerance) : ($diff > 0.0)) {
				$mismatches[] = [
					'order'        => $order,
					'order_total'  => $order_total,
					'order_currency' => $order_currency,
					'invoice_amount' => $req_amount,
					'invoice_currency' => $req_currency,
					'diff'         => $diff,
					'payment_id'   => $payment_id,
				];
			}
		}

		echo '<p>' . sprintf(
			esc_html__('Checked: %1$d | Mismatches: %2$d | Errors: %3$d', 'wc_uniwire_gateway'),
			$checked,
			count($mismatches),
			$errors
		) . '</p>';

		if (!empty($mismatches)) {
			echo '<div style="background:#fcf0f1;border:1px solid #d63638;border-radius:4px;padding:15px;margin:10px 0;">';
			echo '<h3 style="color:#d63638;margin-top:0;">' . sprintf(
				esc_html__('Found %d order(s) with amount discrepancies!', 'wc_uniwire_gateway'),
				count($mismatches)
			) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__('Order', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Date', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Order Total', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Invoice Amount', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Difference', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Status', 'wc_uniwire_gateway') . '</th>';
			echo '<th>' . esc_html__('Invoice ID', 'wc_uniwire_gateway') . '</th>';
			echo '</tr></thead><tbody>';

			foreach ($mismatches as $m) {
				$order = $m['order'];
				$order_url = $order->get_edit_order_url();
				echo '<tr>';
				echo '<td><a href="' . esc_url($order_url) . '">#' . esc_html($order->get_id()) . '</a></td>';
				echo '<td>' . esc_html($order->get_date_created()->date('Y-m-d H:i')) . '</td>';
				echo '<td>' . esc_html($m['order_total'] . ' ' . $m['order_currency']) . '</td>';
				echo '<td style="color:#d63638;font-weight:bold;">' . esc_html($m['invoice_amount'] . ' ' . ($m['invoice_currency'] ?? '')) . '</td>';
				echo '<td>' . esc_html(number_format($m['diff'], 2)) . '</td>';
				echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
				echo '<td><code>' . esc_html($m['payment_id']) . '</code></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '</div>';
		} else {
			echo '<div style="background:#edfaef;border:1px solid #00a32a;border-radius:4px;padding:15px;margin:10px 0;">';
			echo '<p style="color:#00a32a;margin:0;"><strong>' . esc_html__('No amount discrepancies found.', 'wc_uniwire_gateway') . '</strong></p>';
			echo '</div>';
		}
	}

	private static function render_orders_table($orders) {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Order', 'wc_uniwire_gateway') . '</th>';
		echo '<th>' . esc_html__('Date', 'wc_uniwire_gateway') . '</th>';
		echo '<th>' . esc_html__('Total', 'wc_uniwire_gateway') . '</th>';
		echo '<th>' . esc_html__('Status', 'wc_uniwire_gateway') . '</th>';
		echo '<th>' . esc_html__('Invoice ID', 'wc_uniwire_gateway') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($orders as $order) {
			$order_url = $order->get_edit_order_url();
			echo '<tr>';
			echo '<td><a href="' . esc_url($order_url) . '">#' . esc_html($order->get_id()) . '</a></td>';
			echo '<td>' . esc_html($order->get_date_created()->date('Y-m-d H:i')) . '</td>';
			echo '<td>' . esc_html($order->get_total() . ' ' . $order->get_currency()) . '</td>';
			echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
			echo '<td><code>' . esc_html($order->get_meta('_merchant_payment_id')) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
