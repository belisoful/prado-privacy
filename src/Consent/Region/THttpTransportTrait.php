<?php

/**
 * THttpTransportTrait class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent\Region;

/**
 * THttpTransportTrait trait.
 *
 * THttpTransportTrait is the package's one HTTP client: a GET through PHP's `http` stream wrapper
 * with a timeout. It needs no extension beyond `openssl` for https. Tests override
 * {@see transport()} or point it at a server on the loopback.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
trait THttpTransportTrait
{
	/**
	 * Sends a GET request and returns the status and the body. A transport failure (no connection,
	 * a timeout, a refused TLS handshake) is status 0 with a null body.
	 * @param string $url the request URL
	 * @param string[] $headers the request header lines, such as `Accept: application/json`
	 * @param float $timeout the timeout in seconds
	 * @return array{0: int, 1: ?string} the HTTP status code and the response body
	 */
	protected function transport(string $url, array $headers, float $timeout): array
	{
		$context = \stream_context_create(['http' => [
			'method' => 'GET',
			'header' => \implode("\r\n", $headers) . "\r\n",
			'timeout' => $timeout,
			'ignore_errors' => true,
		]]);
		$stream = @\fopen($url, 'r', false, $context);
		if ($stream === false) {
			return [0, null];
		}
		// The http wrapper keeps the response's status line and headers in the stream's metadata.
		$meta = \stream_get_meta_data($stream);
		$body = (string) \stream_get_contents($stream);
		\fclose($stream);
		$status = 0;
		foreach ((array) $meta['wrapper_data'] as $header) {
			if (\preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $header, $match)) {
				$status = (int) $match[1];
			}
		}
		return [$status, $body];
	}
}
