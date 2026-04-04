<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middlewares.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'PGOJit\\App\\';
	if (0 !== strncmp($class, $prefix, strlen($prefix))) {
		return;
	}

	$relative = substr($class, strlen($prefix));
	$path = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

	if (file_exists($path)) {
		require_once $path;
	}
});
