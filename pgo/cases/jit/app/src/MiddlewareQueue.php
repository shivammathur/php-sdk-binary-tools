<?php
declare(strict_types=1);

namespace PGOJit\App;

final class MiddlewareQueue implements \Countable, \SeekableIterator
{
	/** @var array<int,MiddlewareInterface> */
	private array $items = array();

	private int $position = 0;

	public function add(MiddlewareInterface $middleware): self
	{
		$this->items[] = $middleware;
		return $this;
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function current(): MiddlewareInterface
	{
		return $this->items[$this->position];
	}

	public function key(): int
	{
		return $this->position;
	}

	public function next(): void
	{
		$this->position++;
	}

	public function rewind(): void
	{
		$this->position = 0;
	}

	public function valid(): bool
	{
		return isset($this->items[$this->position]);
	}

	public function seek($offset): void
	{
		$offset = (int)$offset;
		if (!isset($this->items[$offset])) {
			throw new \OutOfBoundsException('Middleware index ' . $offset . ' is not available.');
		}

		$this->position = $offset;
	}

	/** @param array<string,mixed> $request */
	public function through(array $request): array
	{
		$runner = function (int $index, array $state) use (&$runner): array {
			if (!isset($this->items[$index])) {
				return array(
					'status' => 200,
					'headers' => array(
						'X-Attributes' => (string)count($state['attributes'] ?? array()),
						'X-Path' => (string)($state['path'] ?? '/'),
					),
					'body' => json_encode($state, JSON_THROW_ON_ERROR),
				);
			}

			return $this->items[$index]->handle(
				$state,
				static function (array $nextState) use (&$runner, $index): array {
					return $runner($index + 1, $nextState);
				}
			);
		};

		return $runner(0, $request);
	}
}
