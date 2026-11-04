<?php

/**
 * The router of the unit tests' local HTTP server. It answers like an IP geolocation API:
 * status `status` (200 by default); a raw `body` when given; otherwise JSON with the country
 * `cc`, the region `rc`, the requested path, and the Authorization and Accept headers it received.
 * `redirect` answers 302 to that location; `bytes` answers that many bytes; `drip` sends one byte
 * every `drip` seconds, `count` times (10 by default).
 */
if (isset($_GET['redirect'])) {
	header('Location: ' . $_GET['redirect'], true, 302);
	echo json_encode(['country_code' => 'DE']);
	return true;
}
if (isset($_GET['bytes'])) {
	header('Content-Type: application/json');
	echo str_repeat(' ', (int) $_GET['bytes'] - 21), json_encode(['country_code' => 'DE']);
	return true;
}
if (isset($_GET['drip'])) {
	header('Content-Type: application/json');
	header('Content-Length: ' . (int) ($_GET['count'] ?? 10));
	for ($i = (int) ($_GET['count'] ?? 10); $i > 0; $i--) {
		echo ' ';
		flush();
		usleep((int) ((float) $_GET['drip'] * 1000000));
	}
	return true;
}
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
