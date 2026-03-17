<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
	exit;
}

final class Uniwire_Gateway_Block extends AbstractPaymentMethodType {

	protected $name = 'wc_uniwire_gateway';

	private $gateway;

	public function initialize() {
		$this->settings = get_option('woocommerce_wc_uniwire_gateway_settings', []);
		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway = isset($gateways['wc_uniwire_gateway']) ? $gateways['wc_uniwire_gateway'] : null;
	}

	public function is_active() {
		return $this->gateway && filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN);
	}

	public function get_payment_method_script_handles() {
		$asset_path = dirname(__FILE__, 3) . '/assets/js/blocks/merchant-block.asset.php';
		$version = file_exists($asset_path) ? require($asset_path) : ['dependencies' => [], 'version' => '1.0.0'];

		wp_register_script(
			'merchant-plugin-block',
			plugins_url('assets/js/blocks/merchant-block.js', dirname(__FILE__, 2)),
			$version['dependencies'] ?? [],
			$version['version'] ?? '1.0.0',
			true
		);

		return ['merchant-plugin-block'];
	}

	public function get_payment_method_data() {
		return [
			'title'       => $this->get_setting('title'),
			'description' => $this->get_setting('description'),
			'supports'    => $this->get_supported_features(),
		];
	}
}
