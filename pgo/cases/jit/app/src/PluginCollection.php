<?php
declare(strict_types=1);

namespace PGOJit\App;

final class PluginCollection implements \Countable, \IteratorAggregate
{
	/** @var array<string,array<string,mixed>> */
	private array $plugins = array();

	/** @param array<string,mixed> $metadata */
	public function add(string $name, array $metadata): void
	{
		$this->plugins[$name] = $metadata;
	}

	public function count(): int
	{
		return count($this->plugins);
	}

	public function getIterator(): \Traversable
	{
		return new \ArrayIterator($this->plugins);
	}
}
