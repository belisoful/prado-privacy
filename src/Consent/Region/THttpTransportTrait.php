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
 * Redirects are not followed, so the request, its headers and the address in its URL go to the
 * configured host only. The timeout bounds the whole request, and a body above
 * {@see MAX_RESPONSE_BYTES} is a failure.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
trait THttpTransportTrait
{
	/** The largest response body read, in bytes. */
	public const MAX_RESPONSE_BYTES = 65536;

	/**
	 * Sends a GET request and returns the status and the body. A transport failure (no connection,
	 * a timeout, a refused TLS handshake, a body above {@see MAX_RESPONSE_BYTES}) is status 0 with
	 * a null body. A redirect is not followed: its 3xx status is returned.
	 * @param string $url the request URL
	 * @param string[] $headers the request header lines, such as `Accept: application/json`
	 * @param float $timeout the timeout of the whole request, in seconds
	 * @return array{0: int, 1: ?string} the HTTP status code and the response body
	 */
	protected function transport(string $url, array $headers, float $timeout): array
	{
		$deadline = \microtime(true) + $timeout;
		$context = \stream_context_create(['http' => [
			'method' => 'GET',
			'header' => \implode("\r\n", $headers) . "\r\n",
			'timeout' => $timeout,
			'ignore_errors' => true,
			'follow_location' => 0,
			'max_redirects' => 1,
		]]);
		$stream = @\fopen($url, 'r', false, $context);
		if ($stream === false) {
			return [0, null];
		}
		$body = '';
		while (!\feof($stream) && \strlen($body) <= self::MAX_RESPONSE_BYTES && ($remaining = $deadline - \microtime(true)) > 0) {
			\stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
			$body .= (string) \fread($stream, 8192);
		}
		// The http wrapper keeps the response's status line and headers in the stream's metadata.
		$meta = \stream_get_meta_data($stream);
		\fclose($stream);
		if (!$meta['eof'] || \strlen($body) > self::MAX_RESPONSE_BYTES) {
			return [0, null];
		}
		$status = 0;
		foreach ((array) $meta['wrapper_data'] as $header) {
			if (\preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $header, $match)) {
				$status = (int) $match[1];
			}
		}
		return [$status, $body];
	}
}
