<?php
declare(strict_types=1);

namespace PGOJit\App;

final class ConfigRepository
{
	/** @var array<string,mixed> */
	private array $items;

	/** @param array<string,mixed> $items */
	public function __construct(array $items)
	{
		$this->items = $items;
	}

	public function bootstrap(int $iteration): void
	{
		$dynamic = array(
			'app' => array(
				'cachePrefix' => 'pgo_' . ($iteration % 7),
				'timezone' => ($iteration % 2 === 0) ? 'UTC' : 'Europe/Berlin',
			),
			'services' => array(
				'queue' => array(
					'workers' => 2 + ($iteration % 3),
					'retry' => 3 + ($iteration % 2),
				),
				'search' => array(
					'driver' => ($iteration % 2 === 0) ? 'memory' : 'database',
					'limit' => 25 + ($iteration % 5),
				),
			),
		);

		$this->items = array_replace_recursive($this->items, $dynamic);

		$services = $this->items['services'] ?? array();
		if (is_array($services)) {
			ksort($services);
			$this->items['services'] = $services;
		}
	}

	/** @return mixed */
	public function read(string $key, $default = null)
	{
		return $this->items[$key] ?? $default;
	}

	/** @return array<string,mixed> */
	public function all(): array
	{
		return $this->items;
	}
}
