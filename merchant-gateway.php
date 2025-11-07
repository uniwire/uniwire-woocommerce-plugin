<?php
	/*
	Plugin Name:  CryptoChill Payment Gateway
	Plugin URI:   https://cryptochill.com/
	Description:  A payment gateway that allows your customers to pay with cryptocurrency
	Version:      0.4
	Author:       Cryptochill
	License:      GPLv3+
	License URI:  https://www.gnu.org/licenses/gpl-3.0.html
	Text Domain:  cryptochill
	Domain Path:  /languages
	Requires Plugins: woocommerce

	WC requires at least: 3.0.9
	WC tested up to: 8.6.0

	CryptoChill is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	any later version.

	CryptoChill is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with CryptoChill WooCommerce. If not, see https://www.gnu.org/licenses/gpl-3.0.html.
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
		define('__' . 'MERCHANT_TITLE' . '__', 'Cryptochill');
	}

	if (!isset($GLOBALS['CryptoChill_SDK_JS'])) {
		$GLOBALS['CryptoChill_SDK_JS'] = 'https://static.cryptochill.com/static/js/sdk2.js';
	}

	if (@$GLOBALS['CryptoChill_SDK_JS'] === '__' . 'VITE_SDK_JS' . '__') {
		unset($GLOBALS['CryptoChill_SDK_JS']);
	}
	function cryptochill_init_gateway()
	{
		// If WooCommerce is available, initialise WC parts.
//		$site_plugins    = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
//    $network_plugins = get_network_option( 'active_sitewide_plugins', array() );

		if (class_exists('WooCommerce')) {
			require_once 'class-wc-merchant-gateway.php';
			add_action('init', 'cryptochill_wc_register_blockchain_status');
			add_action('init', 'cryptochill_wc_check_currency_support');
			add_filter('woocommerce_valid_order_statuses_for_payment', 'cryptochill_wc_status_valid_for_payment', 10, 2);
			add_action('cryptochill_check_orders', 'cryptochill_wc_check_orders');
			add_filter('woocommerce_payment_gateways', 'cryptochill_wc_add_merchant_class');
			add_filter('wc_order_statuses', 'cryptochill_wc_add_status');
			add_action('woocommerce_admin_order_data_after_order_details', 'cryptochill_order_meta_general');
			add_action('woocommerce_order_details_after_order_table', 'cryptochill_order_meta_general');
			add_filter('woocommerce_email_order_meta_fields', 'cryptochill_custom_woocommerce_email_order_meta_fields', 10, 3);

			add_action('before_woocommerce_init', function () {
				if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
				}
			});
		}
	}

	add_action('plugins_loaded', 'cryptochill_init_gateway');


	// Setup cron job.


	function cryptochill_cron_add_minute($schedules)
	{
		// Adds once every 5 minutes to the existing schedules.
		$schedules['every_5_minutes'] = [
			'interval' => 60 * 5,
			'display'  => __('Once Every 5 Minutes')
		];

		return $schedules;
	}

	add_filter('cron_schedules', 'cryptochill_cron_add_minute');

	function cryptochill_activation()
	{

		if (!class_exists('WooCommerce')) {
			$message = __('WooCommerce is not installed!', 'cryptochill');

//            wp_die($message);
			return;
		}

		if (!in_array(get_woocommerce_currency(), SUPPORTED_MERCHANT_CURRENCIES)) {
			deactivate_plugins(plugin_basename(__FILE__));
			$message = sprintf(__('Your shop currency (%s) is not supported by CryptoChill!  Sorry about that.', 'cryptochill'), get_woocommerce_currency());
			wp_die($message);
		}

		if (!wp_next_scheduled('cryptochill_check_orders')) {
			wp_schedule_event(time(), 'every_minute', 'cryptochill_check_orders');
		}
	}

	register_activation_hook(__FILE__, 'cryptochill_activation');

	function cryptochill_deactivation()
	{
		wp_clear_scheduled_hook('cryptochill_check_orders');
	}

	register_deactivation_hook(__FILE__, 'cryptochill_deactivation');

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

	function cryptochill_wc_add_merchant_class($gateways)
	{
		$gateways[] = 'WC_Cryptochill_Gateway';

		return $gateways;
	}

	function cryptochill_wc_check_orders()
	{
		// WC_Cryptochill_Gateway->id = 'cryptochill'
		$gateway = WC()->payment_gateways()->payment_gateways()['cryptochill'];

		return $gateway->check_orders();
	}

	function cryptochill_currency_admin_notice__error()
	{
		$class = 'notice notice-error';
		$message = sprintf(__('Your shop currency (%s) is not supported bu CryptoChill!', 'cryptochill'), get_woocommerce_currency());
		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
	}

	function cryptochill_wc_check_currency_support()
	{
		if (!in_array(get_woocommerce_currency(), SUPPORTED_MERCHANT_CURRENCIES)) {
			add_action('admin_notices', 'cryptochill_currency_admin_notice__error');
		}
	}


	/**
	 * Register new status with ID "wc-blockchainpending" and label "CryptoChill Pending"
	 */
	function cryptochill_wc_register_blockchain_status()
	{
		register_post_status('wc-blockchainpending', [
			'label'                     => __('Blockchain Pending', 'cryptochill'),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Blockchain pending <span class="count">(%s)</span>', 'Blockchain pending <span class="count">(%s)</span>'),
		]);
	}

	/**
	 * Register wc-blockchainpending status as valid for payment.
	 */
	function cryptochill_wc_status_valid_for_payment($statuses, $order)
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
	function cryptochill_wc_add_status($wc_statuses_arr)
	{
		$new_statuses_arr = [];

		// Add new order status after payment pending.
		foreach ($wc_statuses_arr as $id => $label) {
			$new_statuses_arr[$id] = $label;

			if ('wc-pending' === $id) {  // after "Payment Pending" status.
				$new_statuses_arr['wc-blockchainpending'] = __('Blockchain Pending', 'cryptochill');
			}
		}

		return $new_statuses_arr;
	}


	/**
	 * Add order CryptoChill meta after General and before Billing
	 *
	 * @param WC_Order $order WC order instance
	 */
	function cryptochill_order_meta_general($order)
	{
		if ($order->get_payment_method() == 'cryptochill') {

			if (!defined('MERCHANT_SITE_URL')) {
				die('No environment configured MERCHANT_SITE_URL');
			}
			?>
            <br class="clear"/>
            <h3>Payment Data</h3>
            <div class="">
                <p>CryptoChill ID:
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
	 * Add CryptoChill meta to WC emails
	 *
	 * @param array $fields indexed list of existing additional fields.
	 * @param bool $sent_to_admin If should sent to admin.
	 * @param WC_Order $order WC order instance
	 *
	 * @return array
	 */
	function cryptochill_custom_woocommerce_email_order_meta_fields($fields, $sent_to_admin, $order)
	{
		if ($order->get_payment_method() == 'cryptochill') {
			$fields['merchant_commerce_reference'] = [
				'label' => __('CryptoChill Payment ID #'),
				'value' => $order->get_meta('_merchant_payment_id'),
			];
		}

		return $fields;
	}
