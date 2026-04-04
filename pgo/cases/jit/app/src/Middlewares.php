<?php
declare(strict_types=1);

namespace PGOJit\App;

interface MiddlewareInterface
{
	/** @param array<string,mixed> $request */
	public function handle(array $request, \Closure $next): array;
}

abstract class AbstractMiddleware implements MiddlewareInterface
{
	protected ConfigRepository $config;

	public function __construct(ConfigRepository $config)
	{
		$this->config = $config;
	}
}

final class ErrorHandlerMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$request['attributes']['debug'] = (bool)($this->config->read('app')['debug'] ?? false);
		return $next($request);
	}
}

final class AssetMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$path = (string)($request['path'] ?? '/');
		$request['attributes']['assetKey'] = preg_replace('/[^a-z0-9]+/i', '-', strtolower($path));
		return $next($request);
	}
}

final class RoutingMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$routes = (array)$this->config->read('routes', array());
		$request['attributes']['route'] = $routes[$request['path'] ?? '/'] ?? 'Pages::missing';
		return $next($request);
	}
}

final class BodyParserMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$payload = array(
			'page' => $request['query']['page'] ?? 1,
			'search' => $request['query']['q'] ?? '',
			'accept' => $request['headers']['Accept'] ?? 'text/html',
		);
		$request['attributes']['payload'] = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		return $next($request);
	}
}

final class AuthenticationMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$user_id = (int)($request['attributes']['userId'] ?? 0);
		$request['attributes']['identity'] = array(
			'id' => $user_id,
			'name' => 'user-' . $user_id,
			'roles' => $request['attributes']['roles'] ?? array('guest'),
		);
		return $next($request);
	}
}

final class CsrfProtectionMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$path = (string)($request['path'] ?? '/');
		$request['attributes']['csrf'] = hash('sha256', $path . ':' . ($request['headers']['X-Trace-Seed'] ?? '0'), false);
		return $next($request);
	}
}

final class AuthorizationMiddleware extends AbstractMiddleware
{
	public function handle(array $request, \Closure $next): array
	{
		$identity = $request['attributes']['identity'] ?? array('roles' => array('guest'));
		$roles = (array)($identity['roles'] ?? array('guest'));
		$request['attributes']['authorized'] = in_array('admin', $roles, true) || in_array('editor', $roles, true);

		$response = $next($request);
		$response['headers']['X-Authorized'] = $request['attributes']['authorized'] ? '1' : '0';

		return $response;
	}
}
