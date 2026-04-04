<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

use PGOJit\App\Application;
use PGOJit\App\MiddlewareQueue;

function hot_path(int $seed): int
{
	$total = 0;
	$buffer = range(1, 16);

	for ($outer = 0; $outer < 16; $outer++) {
		foreach ($buffer as $index => $value) {
			$tmp = ($value + $seed + $outer + $index) ^ (($seed << ($index % 5)) & 0xffff);

			if (($tmp & 1) === 0) {
				$total += ($tmp >> 1) + ($index * 3);
			} else {
				$total -= ($tmp << 1) - $outer;
			}

			$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
		}

		$buffer[$outer % 16] = ($total ^ $seed) & 0xffff;
		sort($buffer);
	}

	return $total ^ $seed;
}

function vmTracingWorkload(int $iterations = 60000): int
{
	$checksum = 0;

	for ($i = 0; $i < $iterations; $i++) {
		$checksum ^= hot_path($i + 1);
	}

	return $checksum;
}

function buildConfig(int $seed): array
{
	return array(
		'app' => array(
			'name' => 'jit-app-' . ($seed % 5),
			'debug' => false,
			'locale' => ($seed % 2 === 0) ? 'en_US' : 'de_DE',
		),
		'features' => array(
			'assets' => true,
			'auth' => ($seed % 3) !== 0,
			'csrf' => true,
			'json' => true,
		),
		'plugins' => array(
			'DebugKit' => $seed % 11 === 0,
			'Bake' => $seed % 13 === 0,
			'Authentication' => true,
			'Authorization' => true,
		),
		'routes' => array(
			'/' => 'Pages::display',
			'/health' => 'Health::index',
			'/api/articles' => 'Articles::index',
			'/api/articles/view' => 'Articles::view',
		),
		'services' => array(
			'cache' => array('engine' => 'File', 'duration' => 3600 + $seed),
			'mailer' => array('transport' => 'smtp', 'host' => '127.0.0.1'),
			'queue' => array('workers' => 2 + ($seed % 4), 'retry' => 3),
		),
	);
}

function frameworkWorkload(int $iterations = 220): int
{
	$checksum = 0;

	for ($i = 0; $i < $iterations; $i++) {
		$app = new Application(buildConfig($i), $i);
		$app->bootstrap();

		$queue = $app->middleware(new MiddlewareQueue());
		foreach (array(0, 1, 2, 3, 4, 5) as $index) {
			$queue->seek($index);
			$current = $queue->current();
			$checksum += strlen(get_class($current));
		}

		$request = array(
			'path' => ($i % 2 === 0) ? '/api/articles' : '/health',
			'query' => array('page' => $i % 10, 'q' => 'term-' . ($i % 17)),
			'headers' => array(
				'Accept' => ($i % 2 === 0) ? 'application/json' : 'text/html',
				'X-Trace-Seed' => (string)$i,
			),
			'attributes' => array(
				'userId' => $i % 9,
				'roles' => ($i % 4 === 0) ? array('admin', 'editor') : array('user'),
			),
		);

		$response = $app->dispatch($request, $queue);
		$checksum += $app->getPlugins()->count();
		$checksum += (int)$response['status'];
		$checksum += count((array)$response['headers']);
		$checksum += strlen((string)$response['body']);
	}

	return $checksum;
}

echo (vmTracingWorkload() ^ frameworkWorkload()), PHP_EOL;
