<?php
/**
 * Unit test: the callback signature header check.
 *
 * Runs under plain PHP (no WordPress needed):
 *   docker run --rm -v "$PWD":/src -w /src php:8.3-cli php tests/test-webhook-signature.php
 *
 * Also runs via WP-CLI next to the other suites:
 *   ./.superset/test.sh webhook-signature
 *
 * Exit code is non-zero when any case fails.
 */

// The build renames the "Merchant" class prefix to the provider name, so load the
// file by path and find the class by suffix instead of hard-coding its name.
$tws_class = null;
foreach (get_declared_classes() as $declared) {
	if (substr($declared, -strlen('_Webhook_Signature')) === '_Webhook_Signature') {
		$tws_class = $declared;
		break;
	}
}
if (!$tws_class) {
	require_once dirname(__DIR__) . '/includes/class-merchant-webhook-signature.php';
	foreach (get_declared_classes() as $declared) {
		if (substr($declared, -strlen('_Webhook_Signature')) === '_Webhook_Signature') {
			$tws_class = $declared;
			break;
		}
	}
}

$tws_token = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
$tws_ts = '1794312000';
$tws_body = '{"callback_id": "3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f", "callback_status": "invoice_complete"}';
$tws_hex = 'f06511a6f3fe5310c70b645d3568a42e730d33627aa89bccac45f3626192bf0c';
$tws_now = (int) $tws_ts;

$tws_check = function ($body, $ts, $sig, $token, $now) use ($tws_class) {
	return $tws_class::check($body, $ts, $sig, $token, $now);
};

// label => [body, timestamp header, signature header, token, now, expected result]
$tws_cases = [
	'test vector passes'                  => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now, 'ok'],
	'changed body fails'                  => [$tws_body . ' ', $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now, 'signature_mismatch'],
	'changed timestamp header fails'      => [$tws_body, '1794312001', 'v1=' . $tws_hex, $tws_token, $tws_now, 'signature_mismatch'],
	'timestamp 301 s old fails'           => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now + 301, 'timestamp_outside_window'],
	'timestamp 299 s old passes'          => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now + 299, 'ok'],
	'timestamp exactly 300 s old passes'  => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now + 300, 'ok'],
	'timestamp 301 s in future fails'     => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now - 301, 'timestamp_outside_window'],
	'timestamp 299 s in future passes'    => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token, $tws_now - 299, 'ok'],
	'missing timestamp header fails'      => [$tws_body, null, 'v1=' . $tws_hex, $tws_token, $tws_now, 'missing_timestamp_header'],
	'empty timestamp header fails'        => [$tws_body, '', 'v1=' . $tws_hex, $tws_token, $tws_now, 'missing_timestamp_header'],
	'missing signature header fails'      => [$tws_body, $tws_ts, null, $tws_token, $tws_now, 'missing_signature_header'],
	'empty signature header fails'        => [$tws_body, $tws_ts, '', $tws_token, $tws_now, 'missing_signature_header'],
	'non-numeric timestamp fails'         => [$tws_body, '1794312000a', 'v1=' . $tws_hex, $tws_token, $tws_now, 'bad_timestamp_format'],
	'negative timestamp fails'            => [$tws_body, '-1794312000', 'v1=' . $tws_hex, $tws_token, $tws_now, 'bad_timestamp_format'],
	'empty token fails'                   => [$tws_body, $tws_ts, 'v1=' . $tws_hex, '', $tws_now, 'missing_token'],
	'null token fails'                    => [$tws_body, $tws_ts, 'v1=' . $tws_hex, null, $tws_now, 'missing_token'],
	'empty-token HMAC is not accepted'    => [$tws_body, $tws_ts, 'v1=' . hash_hmac('sha256', $tws_ts . '.' . $tws_body, ''), '', $tws_now, 'missing_token'],
	'v1=bad,v1=<good> passes'             => [$tws_body, $tws_ts, 'v1=bad,v1=' . $tws_hex, $tws_token, $tws_now, 'ok'],
	'v1=bad, v1=<good> (space) passes'    => [$tws_body, $tws_ts, 'v1=bad, v1=' . $tws_hex, $tws_token, $tws_now, 'ok'],
	'only bad v1= values fail'            => [$tws_body, $tws_ts, 'v1=bad,v1=worse', $tws_token, $tws_now, 'signature_mismatch'],
	'value without v1= prefix fails'      => [$tws_body, $tws_ts, $tws_hex, $tws_token, $tws_now, 'bad_signature_format'],
	'v0= prefix fails'                    => [$tws_body, $tws_ts, 'v0=' . $tws_hex, $tws_token, $tws_now, 'bad_signature_format'],
	'uppercase hex fails'                 => [$tws_body, $tws_ts, 'v1=' . strtoupper($tws_hex), $tws_token, $tws_now, 'signature_mismatch'],
	'wrong token fails'                   => [$tws_body, $tws_ts, 'v1=' . $tws_hex, $tws_token . 'x', $tws_now, 'signature_mismatch'],
];

$tws_failures = 0;
$tws_total = 0;

if (!$tws_class) {
	echo "FAIL  class *_Webhook_Signature not found\n";
	$tws_failures++;
} else {
	echo "Class under test: {$tws_class}\n";
	foreach ($tws_cases as $label => $case) {
		$tws_total++;
		list($body, $ts, $sig, $token, $now, $expected) = $case;
		$got = $tws_check($body, $ts, $sig, $token, $now);
		$bool = $tws_class::is_valid($body, $ts, $sig, $token, $now);
		$ok = $got === $expected && $bool === ($expected === 'ok');
		$tws_failures += $ok ? 0 : 1;
		printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : " (expected {$expected}, got {$got})");
	}

	// The legacy body signature must be masked in everything that is logged.
	$tws_signed_body = '{"callback_id": "abc", "signature": "' . $tws_hex . '", "callback_status": "invoice_complete"}';
	$redacted_raw = $tws_class::redact_payload($tws_signed_body);
	$redacted_data = $tws_class::redact_data(json_decode($tws_signed_body, true));
	$redaction_checks = [
		'raw payload: signature masked'        => strpos($redacted_raw, $tws_hex) === false && strpos($redacted_raw, '"signature": "********"') !== false,
		'raw payload: other fields kept'       => strpos($redacted_raw, '"callback_id": "abc"') !== false,
		'raw payload without signature intact' => $tws_class::redact_payload($tws_body) === $tws_body,
		'decoded data: signature masked'       => $redacted_data['signature'] === '********',
		'decoded data: other fields kept'      => $redacted_data['callback_id'] === 'abc',
		'decoded data without signature intact' => $tws_class::redact_data(['a' => 1]) === ['a' => 1],
		'raw update_invoice: order_key masked'  => $tws_class::redact_payload('{"action":"update_invoice","order_key":"wc_order_SeCrEt"}') === '{"action":"update_invoice","order_key":"********"}',
	];
	foreach ($redaction_checks as $label => $ok) {
		$tws_total++;
		$tws_failures += $ok ? 0 : 1;
		printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
	}
}

echo $tws_failures ? "RESULT: {$tws_failures} of {$tws_total} cases FAILED\n" : "RESULT: all {$tws_total} cases passed\n";

if (class_exists('WP_CLI')) {
	WP_CLI::halt($tws_failures ? 1 : 0);
}
if (!defined('ABSPATH')) {
	exit($tws_failures ? 1 : 0);
}
