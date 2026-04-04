<?php
declare(strict_types=1);

namespace PGOJit\App;

final class Application
{
	private ConfigRepository $config;

	private PluginCollection $plugins;

	private int $seed;

	/** @param array<string,mixed> $config */
	public function __construct(array $config, int $seed)
	{
		$this->config = new ConfigRepository($config);
		$this->plugins = new PluginCollection();
		$this->seed = $seed;
	}

	public function bootstrap(): void
	{
		$this->config->bootstrap($this->seed);

		$plugins = (array)$this->config->read('plugins', array());
		foreach ($plugins as $name => $enabled) {
			if (!$enabled) {
				continue;
			}

			$this->plugins->add((string)$name, array(
				'bootstrap' => true,
				'routePrefix' => strtolower((string)$name),
			));
		}

		$signature = hash('sha256', json_encode($this->config->all(), JSON_THROW_ON_ERROR), false);
		$this->plugins->add('Runtime', array(
			'bootstrap' => true,
			'signature' => substr($signature, 0, 12),
		));
	}

	public function getPlugins(): PluginCollection
	{
		return $this->plugins;
	}

	public function middleware(MiddlewareQueue $queue): MiddlewareQueue
	{
		return $queue
			->add(new ErrorHandlerMiddleware($this->config))
			->add(new AssetMiddleware($this->config))
			->add(new RoutingMiddleware($this->config))
			->add(new BodyParserMiddleware($this->config))
			->add(new AuthenticationMiddleware($this->config))
			->add(new CsrfProtectionMiddleware($this->config))
			->add(new AuthorizationMiddleware($this->config));
	}

	/** @param array<string,mixed> $request */
	public function dispatch(array $request, MiddlewareQueue $queue): array
	{
		return $queue->through($request);
	}
}
