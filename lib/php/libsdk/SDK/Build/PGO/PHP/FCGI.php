<?php

namespace SDK\Build\PGO\PHP;

use SDK\Build\PGO\Interfaces;
use SDK\Build\PGO\Abstracts;
use SDK\Build\PGO\Config as PGOConfig;
use SDK\{Exception, FileOps};
use SDK\Build\PGO\Tool\PackageWorkman;

class FCGI extends Abstracts\PHP implements Interfaces\PHP
{
	use FileOps;

	private const SHUTDOWN_TIMEOUT = 30;

	/** @var bool */
	protected $is_tcp;

	public function __construct(PGOConfig $conf, bool $is_tcp)
	{
		if (!$is_tcp) {
			throw new Exception("FCGI training other than through TCP is not implemented yet.");
		}

		$this->conf = $conf;
		$this->is_tcp = $is_tcp;
		$this->scenario = $conf->getScenario();
		$this->id = $this->getIdString();

		$this->setupPaths();
	}

	public function getExeFilename() : string
	{
		$exe = $this->getRootDir() . DIRECTORY_SEPARATOR . "php-cgi.exe";

		if (!file_exists($exe)) {
			throw new Exception("Path '$exe' doesn't exist.");
		}

		return $exe;
	}

	protected function createEnv() : array
	{
		$env = parent::createEnv();

		$fcgi_env = (array)$this->conf->getSectionItem("php", "fcgi:env");

		foreach ($fcgi_env as $k => $v) {
			$env[$k] = $v;
		}

		/*
		 * Run ZTS workers as independent processes. JIT code can embed the
		 * process-local TSRM TLS index and must not cross a process boundary.
		 */
		if ($this->isThreadSafe()) {
			foreach ($env as $k => $v) {
				if (0 === strcasecmp($k, "PHP_FCGI_CHILDREN")
					|| 0 === strcasecmp($k, "PHP_FCGI_CHILDREN_FOR_KID")) {
					unset($env[$k]);
				}
			}
		}

		return $env;
	}

	private function getWorkerCount() : int
	{
		if (!$this->isThreadSafe()) {
			return 1;
		}

		return max(1, (int)$this->conf->getSectionItem("php", "fcgi", "workers"));
	}

	public function prepareInit(PackageWorkman $pw, bool $force = false) : void
	{
	}

	public function init() : void
	{
/*		echo "Initializing PHP FCGI.\n";
echo "PHP FCGI initialization done.\n";*/
	}

	public function up() : void
	{
		echo "Starting PHP FCGI.\n";

		if ("cache" == $this->scenario) {
			if (file_exists($this->opcache_file_cache)) {
				$this->rm($this->opcache_file_cache);
			}
			if (!mkdir($this->opcache_file_cache)) {
				throw new Exception("Failed to create '{$this->opcache_file_cache}'");
			}
		}

		$exe  = $this->getExeFilename();
		$ini  = $this->getIniFilename();
		$host = $this->conf->getSectionItem("php", "fcgi", "host");
		$port = $this->conf->getSectionItem("php", "fcgi", "port");
		$workers = $this->getWorkerCount();

		$desc = array(
			0 => array("file", "php://stdin", "r"),
			1 => array("file", "php://stdout", "w"),
			2 => array("file", "php://stderr", "w"),
		);

		$processes = array();
		$env = $this->createEnv();
		for ($i = 0; $i < $workers; $i++) {
			$worker_port = $port + $i;
			$worker_options = "";

			if ("cache" === $this->scenario && $workers > 1) {
				$worker_cache = $this->opcache_file_cache . DIRECTORY_SEPARATOR . "worker-$i";
				if (!mkdir($worker_cache)) {
					throw new Exception("Failed to create '$worker_cache'");
				}

				$cache_id = escapeshellarg($this->id . "-worker-$i");
				$file_cache = escapeshellarg($worker_cache);
				$worker_options = " -d opcache.cache_id=$cache_id -d opcache.file_cache=$file_cache";
			}

			$cmd = "start /b $exe -n -c $ini$worker_options -b $host:$worker_port 2>&1";
			$p = proc_open($cmd, $desc, $pipes, $this->getRootDir(), $env);
			if (!is_resource($p)) {
				throw new Exception("Failed to start PHP FCGI worker on $host:$worker_port.");
			}
			$processes[] = $p;
		}

		/* Give some time, it might be slow on PGI enabled proc. */
		sleep(3);

		/*while(false !== ($s = fread($pipes[2], 1024))) {
			echo "$s";
		}*/

		foreach ($processes as $p) {
			$c = proc_close($p);
			if ($c) {
				throw new Exception("PHP FCGI process exited with code '$c'.");
			}
		}

		/* XXX for Opcache, setup also file cache. */

		echo "PHP FCGI started with $workers workers.\n";
	}

	public function down(bool $force = false) : void
	{
		echo "Stopping PHP FCGI.\n";

		exec("taskkill /f /im php-cgi.exe >nul 2>&1");

		/*
		 * Terminating a process is asynchronous on Windows. Wait until all
		 * workers are gone so the next pool cannot reattach to their Opcache
		 * shared memory.
		 */
		$deadline = microtime(true) + self::SHUTDOWN_TIMEOUT;
		while ($pids = $this->getProcessIds()) {
			if (microtime(true) >= $deadline) {
				throw new Exception(
					"Timed out waiting for PHP FCGI processes to stop: " . implode(", ", $pids)
				);
			}
			usleep(100000);
		}

		/* XXX Add cleanup interface. */
		if ("cache" == $this->scenario) {
			try {
				$this->rm($this->opcache_file_cache);
			} catch (\UnexpectedValueException $e) {
				echo $e->getMessage(), "\n";
			}
		}

		echo "PHP FCGI stopped.\n";
	}

	private function getProcessIds() : array
	{
		$output = array();
		$status = 0;
		exec('tasklist /fi "IMAGENAME eq php-cgi.exe" /fo csv /nh 2>nul', $output, $status);

		if ($status) {
			throw new Exception("Failed to query PHP FCGI processes.");
		}

		$pids = array();
		foreach ($output as $line) {
			$process = str_getcsv($line, ",", '"', "\\");
			if (isset($process[0], $process[1])
				&& 0 === strcasecmp($process[0], "php-cgi.exe")
				&& ctype_digit($process[1])) {
				$pids[] = $process[1];
			}
		}

		return $pids;
	}
}
