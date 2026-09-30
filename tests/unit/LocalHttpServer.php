<?php

namespace belisoful\Privacy\Test\Unit;

/**
 * PHP's built-in server on a free loopback port, serving tests/unit/app/geo-router.php: the only
 * socket the unit suite opens, and only to the machine itself.
 */
class LocalHttpServer
{
	public const ROUTER = __DIR__ . '/app/geo-router.php';

	private static ?self $_instance = null;

	private function __construct(private $_process, private int $_port)
	{
	}

	public static function instance(): self
	{
		return self::$_instance ??= self::start();
	}

	private static function start(): self
	{
		$socket = \stream_socket_server('tcp://127.0.0.1:0');
		$port = (int) \substr((string) \stream_socket_get_name($socket, false), \strlen('127.0.0.1:'));
		\fclose($socket);
		$process = \proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, self::ROUTER],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		$deadline = \microtime(true) + 10;
		while (\microtime(true) < $deadline) {
			$probe = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
			if (\is_resource($probe)) {
				\fclose($probe);
				\register_shutdown_function(fn () => \proc_terminate($process));
				return new self($process, $port);
			}
			\usleep(50_000);
		}
		\proc_terminate($process);
		throw new \RuntimeException('The local HTTP server did not accept connections.');
	}

	public function url(string $path = '/'): string
	{
		return 'http://127.0.0.1:' . $this->_port . $path;
	}
}
