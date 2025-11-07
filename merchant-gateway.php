<?php
	/*
	Plugin Name:  Uniwire Payment Gateway
	Plugin URI:   https://uniwire.com/
	Description:  A payment gateway that allows your customers to pay with cryptocurrency
	Version:      0.5
	Author:       Uniwire
	License:      GPLv3+
	License URI:  https://www.gnu.org/licenses/gpl-3.0.html
	Text Domain:  wc_uniwire_gateway
	Domain Path:  /languages
	Requires Plugins: woocommerce

	WC requires at least: 3.0.9
	WC tested up to: 8.6.0

	Uniwire is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	any later version.

	Uniwire is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Uniwire WooCommerce. If not, see https://www.gnu.org/licenses/gpl-3.0.html.
	*/
	if (!defined('SUPPORTED_MERCHANT_CURRENCIES')) {
		define('SUPPORTED_MERCHANT_CURRENCIES', [
			'USD',
			'EUR',
			'GBP',
			'AUD',
			'CHF',
		]);
	}

	if (!defined('__' . 'MERCHANT_SDK_VERSION' . '__')) {
		define('__' . 'MERCHANT_SDK_VERSION' . '__', 0);
	}

	if (!defined('__' . 'MERCHANT_TITLE' . '__')) {
		define('__' . 'MERCHANT_TITLE' . '__', 'Uniwire');
	}

	if (!isset($GLOBALS['Uniwire_SDK_JS'])) {
		$GLOBALS['Uniwire_SDK_JS'] = 'https://static.cryptochill.com/static/js/sdk2.js';
	}

	if (@$GLOBALS['Uniwire_SDK_JS'] === '__' . 'VITE_SDK_JS' . '__') {
		unset($GLOBALS['Uniwire_SDK_JS']);
	}
	function wc_uniwire_gateway_init_gateway()
	{
		// If WooCommerce is available, initialise WC parts.
//		$site_plugins    = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
//    $network_plugins = get_network_option( 'active_sitewide_plugins', array() );

		if (class_exists('WooCommerce')) {
			require_once 'class-wc-merchant-gateway.php';
			add_action('init', 'wc_uniwire_gateway_wc_register_blockchain_status');
			add_action('init', 'wc_uniwire_gateway_wc_check_currency_support');
			add_filter('woocommerce_valid_order_statuses_for_payment', 'wc_uniwire_gateway_wc_status_valid_for_payment', 10, 2);
			add_action('wc_uniwire_gateway_check_orders', 'wc_uniwire_gateway_wc_check_orders');
			add_filter('woocommerce_payment_gateways', 'wc_uniwire_gateway_wc_add_merchant_class');
			add_filter('wc_order_statuses', 'wc_uniwire_gateway_wc_add_status');
			add_action('woocommerce_admin_order_data_after_order_details', 'wc_uniwire_gateway_order_meta_general');
			add_action('woocommerce_order_details_after_order_table', 'wc_uniwire_gateway_order_meta_general');
			add_filter('woocommerce_email_order_meta_fields', 'wc_uniwire_gateway_custom_woocommerce_email_order_meta_fields', 10, 3);

			add_action('before_woocommerce_init', function () {
				if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
				}
			});
		}
	}

	add_action('plugins_loaded', 'wc_uniwire_gateway_init_gateway');


	// Setup cron job.


	function wc_uniwire_gateway_cron_add_minute($schedules)
	{
		// Adds once every 5 minutes to the existing schedules.
		$schedules['every_5_minutes'] = [
			'interval' => 60 * 5,
			'display'  => __('Once Every 5 Minutes')
		];

		return $schedules;
	}

	add_filter('cron_schedules', 'wc_uniwire_gateway_cron_add_minute');

	function wc_uniwire_gateway_activation()
	{

		if (!class_exists('WooCommerce')) {
			$message = __('WooCommerce is not installed!', 'wc_uniwire_gateway');

//            wp_die($message);
			return;
		}

		if (!in_array(get_woocommerce_currency(), SUPPORTED_MERCHANT_CURRENCIES)) {
			deactivate_plugins(plugin_basename(__FILE__));
			$message = sprintf(__('Your shop currency (%s) is not supported by Uniwire!  Sorry about that.', 'wc_uniwire_gateway'), get_woocommerce_currency());
			wp_die($message);
		}

		if (!wp_next_scheduled('wc_uniwire_gateway_check_orders')) {
			wp_schedule_event(time(), 'every_minute', 'wc_uniwire_gateway_check_orders');
		}
	}

	register_activation_hook(__FILE__, 'wc_uniwire_gateway_activation');

	function wc_uniwire_gateway_deactivation()
	{
		wp_clear_scheduled_hook('wc_uniwire_gateway_check_orders');
	}

	register_deactivation_hook(__FILE__, 'wc_uniwire_gateway_deactivation');

	if (!defined('CHECK_PLUGIN_DEPENDENCIES_PLUGIN_FILE')) {
		/**
		 * Path to the plugin's main file.
		 *
		 * Stores the path to the plugin's main file as a constant so we can refer to this file
		 * or the plugin's root directory later using `dirname( CHECK_PLUGIN_DEPENDENCIES_PLUGIN_FILE )`.
		 *
		 * @var string
		 */
		define('CHECK_PLUGIN_DEPENDENCIES_PLUGIN_FILE', __FILE__);
	}

	// Do not setup the plugin if a setup class with the same name was already defined.
	if (!class_exists('Check_Plugin_Dependencies\Check_Plugin_Dependencies')) {
		/**
		 * The file where the Autoloader class is defined.
		 */
		require_once __DIR__ . '/includes/Autoloader.php';
		spl_autoload_register([new \Check_Plugin_Dependencies\Autoloader(), 'autoload']);
		$check_plugin_dependencies = new \Check_Plugin_Dependencies\Check_Plugin_Dependencies();
		$check_plugin_dependencies->setup();
	}


	// WooCommerce

	function wc_uniwire_gateway_wc_add_merchant_class($gateways)
	{
		$gateways[] = 'WC_Uniwire_Gateway';

		return $gateways;
	}

	function wc_uniwire_gateway_wc_check_orders()
	{
		// WC_Uniwire_Gateway->id = 'wc_uniwire_gateway'
		$gateway = WC()->payment_gateways()->payment_gateways()['wc_uniwire_gateway'];

		return $gateway->check_orders();
	}

	function wc_uniwire_gateway_currency_admin_notice__error()
	{
		$class = 'notice notice-error';
		$message = sprintf(__('Your shop currency (%s) is not supported bu Uniwire!', 'wc_uniwire_gateway'), get_woocommerce_currency());
		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
	}

	function wc_uniwire_gateway_wc_check_currency_support()
	{
		if (!in_array(get_woocommerce_currency(), SUPPORTED_MERCHANT_CURRENCIES)) {
			add_action('admin_notices', 'wc_uniwire_gateway_currency_admin_notice__error');
		}
	}


	/**
	 * Register new status with ID "wc-blockchainpending" and label "Uniwire Pending"
	 */
	function wc_uniwire_gateway_wc_register_blockchain_status()
	{
		register_post_status('wc-blockchainpending', [
			'label'                     => __('Blockchain Pending', 'wc_uniwire_gateway'),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Blockchain pending <span class="count">(%s)</span>', 'Blockchain pending <span class="count">(%s)</span>'),
		]);
	}

	/**
	 * Register wc-blockchainpending status as valid for payment.
	 */
	function wc_uniwire_gateway_wc_status_valid_for_payment($statuses, $order)
	{
		$statuses[] = 'wc-blockchainpending';

		return $statuses;
	}

	/**
	 * Add registered status to list of WC Order statuses
	 *
	 * @param array $wc_statuses_arr Array of all order statuses on the website.
	 *
	 * @return array
	 */
	function wc_uniwire_gateway_wc_add_status($wc_statuses_arr)
	{
		$new_statuses_arr = [];

		// Add new order status after payment pending.
		foreach ($wc_statuses_arr as $id => $label) {
			$new_statuses_arr[$id] = $label;

			if ('wc-pending' === $id) {  // after "Payment Pending" status.
				$new_statuses_arr['wc-blockchainpending'] = __('Blockchain Pending', 'wc_uniwire_gateway');
			}
		}

		return $new_statuses_arr;
	}


	/**
	 * Add order Uniwire meta after General and before Billing
	 *
	 * @param WC_Order $order WC order instance
	 */
	function wc_uniwire_gateway_order_meta_general($order)
	{
		if ($order->get_payment_method() == 'wc_uniwire_gateway') {

			if (!defined('MERCHANT_SITE_URL')) {
				die('No environment configured MERCHANT_SITE_URL');
			}
			?>
            <br class="clear"/>
            <h3>Payment Data</h3>
            <div class="">
                <p>Uniwire ID:
					<?php
						$payment_path = 'public/payment/';
						if (strpos(MERCHANT_SITE_URL, 'cryptochill') !== false) {
							$payment_path = 'invoice/';
						}
						//                        TODO check old plugin _merchant_payment_id
					?>
                    <a target="_blank" href="<?php echo MERCHANT_SITE_URL . $payment_path . esc_html($order->get_meta('_merchant_payment_id')); ?>/"><?php echo esc_html($order->get_meta('_merchant_payment_id')); ?></a>
                </p>
            </div>
			<?php
		}
	}


	/**
	 * Add Uniwire meta to WC emails
	 *
	 * @param array $fields indexed list of existing additional fields.
	 * @param bool $sent_to_admin If should sent to admin.
	 * @param WC_Order $order WC order instance
	 *
	 * @return array
	 */
	function wc_uniwire_gateway_custom_woocommerce_email_order_meta_fields($fields, $sent_to_admin, $order)
	{
		if ($order->get_payment_method() == 'wc_uniwire_gateway') {
			$fields['merchant_commerce_reference'] = [
				'label' => __('Uniwire Payment ID #'),
				'value' => $order->get_meta('_merchant_payment_id'),
			];
		}

		return $fields;
	}
