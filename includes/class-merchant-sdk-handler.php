<?php

	if(!defined('ABSPATH')){
		exit;
	}

	/**
	 * Sends API requests to CryptoChill.
	 */
	class Cryptochill_SDK_Handler {

		/** @var string/array Log variable function. */
		public static $log;

		/**
		 * Call the $log variable function.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'.
		 *     emergency|alert|critical|error|warning|notice|info|debug
		 *
		 * @return mixed
		 */
		public static function log($message, $level = 'info'): mixed {
			return call_user_func(self::$log, $message, $level);
		}


		/** @var string CryptoChill API version. */
		public static $api_version = 'v1';


		/**
		 * Get the response from an API request.
		 *
		 * @param string $endpoint
		 * @param array $payload
		 * @param string $method
		 *
		 * @return array
		 */
		public static function send_request($endpoint, $payload = array(), $method = 'GET'){
			self::log('CryptoChill SDK Request Args for ' . $endpoint . ': ' . print_r($payload, true));


			if(!defined( 'MERCHANT_SITE_URL' )){
				self::log('NO ENV:  MERCHANT_SITE_URL');
				return array(false, 'No environment configured MERCHANT_SITE_URL');
			}

			$api_url = MERCHANT_SITE_URL.'sdk/';
			$url = $api_url . self::$api_version . '/' . $endpoint . '/';

			$args     = array(
				'method' => $method,
				'body'   => $payload,
				//				'headers' => array(
				//					'X-CC-KEY'       => self::$api_key,
				//					'X-CC-PAYLOAD'   => $b64,
				//					'X-CC-SIGNATURE' => $signature
				//				)
			);
			$response = wp_remote_request(esc_url_raw($url), $args);


			if(is_wp_error($response)){
				self::log('WP response error: ' . $response->get_error_message());

				return array(false, $response->get_error_message());
			} else{
				$result = json_decode($response['body'], true);

				if($result['result'] !== 'error'){
					return array(true, $result);
				} else{
					$msg = 'Error response from API: ' . $result['message'];
					self::log($msg);

					return array(false, $result['message']);
				}
			}
		}

	}
