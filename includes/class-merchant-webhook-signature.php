<?php

	// No ABSPATH guard here on purpose: this file only declares a class, has no side
	// effects and no WordPress dependency, so tests/test-webhook-signature.php can
	// load it under plain PHP.

	/**
	 * Verifies the signature headers that Uniwire sends with every callback.
	 *
	 * X-Uniwire-Timestamp: <unix seconds>
	 * X-Uniwire-Signature: v1=<hex>[,v1=<hex>...]
	 *
	 * <hex> is the lowercase hex HMAC-SHA256 of "<timestamp>.<raw request body>",
	 * keyed with the profile callback token.
	 */
	class Uniwire_Webhook_Signature {

		/** Header names as sent by the platform. */
		const TIMESTAMP_HEADER = 'X-Uniwire-Timestamp';
		const SIGNATURE_HEADER = 'X-Uniwire-Signature';

		/** The same headers as PHP exposes them in $_SERVER. */
		const TIMESTAMP_SERVER_KEY = 'HTTP_X_UNIWIRE_TIMESTAMP';
		const SIGNATURE_SERVER_KEY = 'HTTP_X_UNIWIRE_SIGNATURE';

		/** Maximum allowed difference between the header timestamp and the local clock, in seconds. */
		const TOLERANCE = 300;

		/** Signature scheme prefix in the signature header. */
		const SCHEME_PREFIX = 'v1=';

		/** Replacement for secret values in logged data. */
		const REDACTED = '********';

		/**
		 * Top-level body fields masked before logging: the legacy callback signature,
		 * and the order key that the checkout page sends with "update_invoice".
		 */
		const REDACTED_FIELDS = ['signature', 'order_key'];

		/** Result codes of check(). */
		const OK = 'ok';
		const MISSING_TOKEN = 'missing_token';
		const MISSING_TIMESTAMP = 'missing_timestamp_header';
		const MISSING_SIGNATURE = 'missing_signature_header';
		const BAD_TIMESTAMP = 'bad_timestamp_format';
		const BAD_SIGNATURE = 'bad_signature_format';
		const OUTSIDE_WINDOW = 'timestamp_outside_window';
		const MISMATCH = 'signature_mismatch';

		/**
		 * Check the signature headers of a callback.
		 *
		 * @param string $payload Raw request body, exactly as read from php://input.
		 * @param mixed $timestamp Value of the timestamp header (null when absent).
		 * @param mixed $signature Value of the signature header (null when absent).
		 * @param mixed $token Callback token.
		 * @param int $now Current unix time.
		 * @param int $tolerance Allowed clock difference in seconds.
		 *
		 * @return string One of the result constants; self::OK when the callback is valid.
		 */
		public static function check($payload, $timestamp, $signature, $token, $now, $tolerance = self::TOLERANCE): string
		{
			if (!is_string($token) || $token === '') {
				return self::MISSING_TOKEN;
			}
			if (!is_string($timestamp) || $timestamp === '') {
				return self::MISSING_TIMESTAMP;
			}
			if (!is_string($signature) || trim($signature) === '') {
				return self::MISSING_SIGNATURE;
			}
			if (!ctype_digit($timestamp)) {
				return self::BAD_TIMESTAMP;
			}

			$candidates = [];
			foreach (explode(',', $signature) as $part) {
				$part = trim($part);
				if (strpos($part, self::SCHEME_PREFIX) === 0) {
					$candidates[] = substr($part, strlen(self::SCHEME_PREFIX));
				}
			}
			if (!$candidates) {
				return self::BAD_SIGNATURE;
			}

			if (abs((int) $now - (int) $timestamp) > (int) $tolerance) {
				return self::OUTSIDE_WINDOW;
			}

			$expected = hash_hmac('sha256', $timestamp . '.' . (string) $payload, $token);
			$matched = false;
			foreach ($candidates as $candidate) {
				if (hash_equals($expected, $candidate)) {
					$matched = true;
				}
			}

			return $matched ? self::OK : self::MISMATCH;
		}

		/**
		 * Boolean form of check().
		 */
		public static function is_valid($payload, $timestamp, $signature, $token, $now, $tolerance = self::TOLERANCE): bool
		{
			return self::check($payload, $timestamp, $signature, $token, $now, $tolerance) === self::OK;
		}

		/**
		 * Mask the REDACTED_FIELDS string values in a raw JSON body before it is logged.
		 *
		 * @param string $payload
		 *
		 * @return string
		 */
		public static function redact_payload($payload): string
		{
			$names = implode('|', array_map(function ($name) {
				return preg_quote($name, '/');
			}, self::REDACTED_FIELDS));
			$redacted = preg_replace(
				'/("(?:' . $names . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/',
				'$1"' . self::REDACTED . '"',
				(string) $payload
			);

			return $redacted === null ? '' : $redacted;
		}

		/**
		 * Mask the REDACTED_FIELDS values in a decoded body before it is logged.
		 *
		 * @param mixed $data
		 *
		 * @return mixed
		 */
		public static function redact_data($data)
		{
			if (is_array($data)) {
				foreach (self::REDACTED_FIELDS as $name) {
					if (array_key_exists($name, $data)) {
						$data[$name] = self::REDACTED;
					}
				}
			}

			return $data;
		}
	}
