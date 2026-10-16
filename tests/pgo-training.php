<?php

/* Run with php -n tests/pgo-training.php. No Windows services or PGO tools needed. */
namespace SDK\Build\PGO\Tool {
	/* Replace only the external PGO commands; exercise the real cleanup and controller. */
	function shell_exec(string $command) : string
	{
		$GLOBALS['pgo_commands'][] = $command;
		if (preg_match('/^pgomgr \/clear (.+)$/', $command, $match)) {
			file_put_contents($match[1], '');
		} elseif (preg_match('/^pgosweep .+ (.+)$/', $command, $match)) {
			file_put_contents($match[1], 'startup counters');
		} else {
			throw new \RuntimeException("Unexpected external command: $command");
		}
		return '';
	}
}

namespace {
	use SDK\Build\PGO\Config;
	use SDK\Build\PGO\Controller;
	use SDK\Build\PGO\PHP\CLI;
	use SDK\Build\PGO\Tool\PackageWorkman;

	/* The SDK autoloader uses Windows namespace separators as paths. */
	spl_autoload_register(function ($class) {
		$file = dirname(__DIR__) . '/lib/php/libsdk/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	});

	function check(bool $condition, string $message) : void
	{
		if (!$condition) {
			throw new \RuntimeException($message);
		}
	}

	class TestConfig extends Config
	{
		public $root;

		public function getWorkDir() : string
		{
			return $this->root;
		}

		public function getCasesTplDir(?string $name = NULL) : string
		{
			return $this->root . '/empty-cases';
		}

		public function isInitialized()
		{
			return true;
		}
	}

	class TestPHP extends CLI
	{
		public $version = '8.5';
		public $ts = true;

		public function __construct(TestConfig $conf)
		{
			$this->conf = $conf;
			$this->scenario = $conf->getScenario();
			$this->php_root = $this->php_ext_root = $conf->root;
			$this->opcache_file_cache = $conf->root . '/file-cache';
		}

		public function getVersion(bool $short = false) : string
		{
			return $this->version;
		}

		public function isThreadSafe() : bool
		{
			return $this->ts;
		}
	}

	class TestController extends Controller
	{
		public function useConfig(Config $conf) : void
		{
			$this->conf = $conf;
		}

		public function getTestConfig() : Config
		{
			return $this->setupConfig('check_init');
		}

		protected function initWorkDirs() : void {}
		protected function prepareStandaloneTools(PackageWorkman $pw, bool $force = false) : void {}
		public function up() {}
		public function down(bool $force = false) {}
	}

	putenv('PHP_SDK_ROOT_PATH=' . dirname(__DIR__));
	$root = sys_get_temp_dir() . '/sdk-pgo-test-' . bin2hex(random_bytes(8));
	mkdir($root);
	$conf = new TestConfig(Config::MODE_CHECK_INIT);
	$conf->root = $root;
	$conf->importSectionFromDir('php', $conf->getTplDir('php'));
	$GLOBALS['pgo_commands'] = array();
	try {
		file_put_contents($root . '/php8ts.dll', '');
		file_put_contents($root . '/php8ts.pgd', 'old profiles');
		file_put_contents($root . '/stale.pgc', 'old counters');
		$php = new TestPHP($conf);
		$conf->addSrv(new class($php) {
			private $php;
			public function __construct($php) { $this->php = $php; }
			public function getName() { return 'nginx'; }
			public function getPhp() { return $this->php; }
			public function prepareInit($pw, $force) {}
			public function init() {}
		});
		$controller = new TestController('train', 'cache', NULL, true);
		check($controller->getTestConfig()->isJitDisabled(), '--no-jit was not propagated');
		check(!(new TestController('train', 'cache', NULL))->getTestConfig()->isJitDisabled(), 'JIT must use the template by default');
		$controller->useConfig($conf);
		ob_start();
		$controller->init();
		check(file_get_contents($root . '/php8ts.pgd') === '', 'Initialization must reset profiles');
		check(!file_exists($root . '/stale.pgc'), 'Initialization must remove stale counters');
		file_put_contents($root . '/php8ts.pgd', 'previous training profiles');
		foreach (array(false, true) as $no_jit) {
			$conf->setJitDisabled($no_jit);
			$controller->train();
			check(file_get_contents($root . '/php8ts.pgd') === 'previous training profiles', 'Training discarded earlier profiles');
			check(glob($root . '/*.pgc') === array(), 'Startup counters were not discarded');
		}
		$clears = array_filter($GLOBALS['pgo_commands'], function ($command) {
			return strpos($command, 'pgomgr /clear ') === 0;
		});
		check(count($clears) === 1, 'Profiles must be cleared once per cycle, not per pass');
		$controller->init();
		check(file_get_contents($root . '/php8ts.pgd') === '', 'A new cycle must reset profiles again');
		ob_end_clean();

		foreach (array('x86', 'x64') as $arch) {
			SDK\Config::setCurrentArchName($arch);
			foreach (array('default', 'cache') as $scenario) {
				$conf->setScenario($scenario);
				foreach (array('8.2', '8.3', '8.4', '8.5', '8.6', '8.7') as $version) {
					foreach (array(false, true) as $ts) {
						$php = new TestPHP($conf);
						$php->version = $version;
						$php->ts = $ts;
						foreach (array(true, false) as $no_jit) {
							$conf->setJitDisabled($no_jit);
							$file = $php->getIniFilename();
							$ini = parse_ini_file($file, false, INI_SCANNER_RAW);
							unlink($file);
							check($ini !== false, 'Invalid generated INI');
							check(($ini['opcache.enable'] ?? '0') === ($scenario === 'cache' ? '1' : '0'), 'OPcache configuration changed');
							if ($no_jit) {
								check($ini['opcache.jit'] === 'disable' && $ini['opcache.jit_buffer_size'] === '0', 'JIT was not disabled');
							} elseif ($scenario === 'cache' && version_compare($version, '8.4', '>=')) {
								check($ini['opcache.jit'] === 'tracing', 'JIT override leaked into the next pass');
								check($ini['opcache.jit_buffer_size'] === ($arch === 'x86' ? '16M' : '32M'), 'JIT buffer configuration changed');
							}
						}
					}
				}
			}
		}
		echo "PGO profile lifecycle and 96 generated INI configurations passed.\n";
	} finally {
		foreach (glob($root . '/*') as $file) {
			unlink($file);
		}
		rmdir($root);
	}
}
