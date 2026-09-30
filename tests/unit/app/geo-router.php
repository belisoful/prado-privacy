<?php

/**
 * The router of the unit tests' local HTTP server. It answers like an IP geolocation API:
 * status `status` (200 by default); a raw `body` when given; otherwise JSON with the country
 * `cc`, the region `rc`, the requested path, and the Authorization and Accept headers it received.
 */
http_response_code((int) ($_GET['status'] ?? 200));
if (isset($_GET['body'])) {
	header('Content-Type: text/plain');
	echo $_GET['body'];
	return true;
}
header('Content-Type: application/json');
echo json_encode([
	'country_code' => $_GET['cc'] ?? 'DE',
	'region_code' => $_GET['rc'] ?? '',
	'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
	'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
	'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
]);
return true;
