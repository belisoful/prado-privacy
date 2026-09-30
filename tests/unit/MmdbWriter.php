<?php

namespace belisoful\Privacy\Test\Unit;

/**
 * Writes a minimal MaxMind DB (format 2.0, IPv4, 24-bit records) so the real maxmind-db/reader runs
 * in the unit suite without a downloaded database. Supports the data types a GeoIP2-style record
 * needs: maps, arrays, strings, and unsigned integers.
 */
class MmdbWriter
{
	/**
	 * @param array<string, array> $networks IPv4 CIDR => record
	 * @return string the database bytes
	 */
	public static function build(array $networks): string
	{
		$nodes = [[null, null]];
		$data = '';
		foreach ($networks as $cidr => $record) {
			[$ip, $length] = \explode('/', $cidr);
			$bits = \str_pad(\decbin((int) \ip2long($ip)), 32, '0', STR_PAD_LEFT);
			$offset = \strlen($data);
			$data .= self::encode($record);
			$node = 0;
			for ($i = 0; $i < (int) $length - 1; $i++) {
				$side = (int) $bits[$i];
				if (!\is_int($nodes[$node][$side])) {
					$nodes[] = [null, null];
					$nodes[$node][$side] = \count($nodes) - 1;
				}
				$node = $nodes[$node][$side];
			}
			$nodes[$node][(int) $bits[(int) $length - 1]] = ['data', $offset];
		}
		$count = \count($nodes);
		$tree = '';
		foreach ($nodes as [$left, $right]) {
			foreach ([$left, $right] as $record) {
				$value = match (true) {
					\is_int($record) => $record,
					\is_array($record) => $count + 16 + $record[1],
					default => $count,
				};
				$tree .= \substr(\pack('N', $value), 1);
			}
		}
		$metadata = [
			'binary_format_major_version' => ['uint16', 2],
			'binary_format_minor_version' => ['uint16', 0],
			'build_epoch' => ['uint64', 1790683200],
			'database_type' => 'Test-City',
			'description' => ['en' => 'prado-privacy test database'],
			'ip_version' => ['uint16', 4],
			'languages' => [['en']],
			'node_count' => ['uint32', $count],
			'record_size' => ['uint16', 24],
		];
		return $tree . \str_repeat("\0", 16) . $data . "\xAB\xCD\xEFMaxMind.com" . self::encode($metadata);
	}

	private static function encode(mixed $value): string
	{
		if (\is_string($value)) {
			return \chr((2 << 5) | \strlen($value)) . $value;
		}
		if (\is_array($value) && \count($value) === 2 && \in_array($value[0] ?? null, ['uint16', 'uint32', 'uint64'], true) && \is_int($value[1])) {
			$bytes = \ltrim(\pack('J', $value[1]), "\0");
			return match ($value[0]) {
				'uint16' => \chr((5 << 5) | \strlen($bytes)),
				'uint32' => \chr((6 << 5) | \strlen($bytes)),
				'uint64' => \chr(\strlen($bytes)) . \chr(9 - 7),
			} . $bytes;
		}
		if (\is_array($value) && \count($value) === 1 && \array_key_exists(0, $value) && \is_array($value[0])) {
			// [[...]] marks an array
			$out = \chr(\count($value[0])) . \chr(11 - 7);
			foreach ($value[0] as $item) {
				$out .= self::encode($item);
			}
			return $out;
		}
		$out = \chr((7 << 5) | \count($value));
		foreach ($value as $key => $item) {
			$out .= self::encode((string) $key) . self::encode($item);
		}
		return $out;
	}
}
