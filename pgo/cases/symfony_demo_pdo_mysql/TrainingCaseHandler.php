<?php

namespace symfony_demo_pdo_mysql;

use SDK\Build\PGO\Abstracts;
use SDK\Build\PGO\Config;
use SDK\Build\PGO\Interfaces;
use SDK\Build\PGO\PHP;
use SDK\Build\PGO\Tool;
use SDK\Exception;

class TrainingCaseHandler extends Abstracts\TrainingCase implements Interfaces\TrainingCase
{
	/** @var string */
	protected $base;

	/** @var ?Interfaces\Server */
	protected $nginx;

	/** @var ?Interfaces\Server\DB */
	protected $maria;

	/** @var mixed */
	protected $php;

	/** @var int */
	protected $max_runs = 4;

	public function __construct(Config $conf, ?Interfaces\Server $nginx, ?Interfaces\Server\DB $srv_db)
	{
		if (!$nginx) {
			throw new Exception("Invalid NGINX object");
		}
		if (!$srv_db) {
			throw new Exception("Invalid MariaDB object");
		}

		$this->conf = $conf;
		$this->base = $this->conf->getCaseWorkDir($this->getName());
		$this->nginx = $nginx;
		$this->maria = $srv_db;
		$this->php = $nginx->getPhp();

		if ("cache" === $this->conf->getScenario()) {
			$this->max_runs = 1;
		}
	}

	public function getName() : string
	{
		return __NAMESPACE__;
	}

	public function getJobFilename() : string
	{
		return $this->conf->getJobDir() . DIRECTORY_SEPARATOR . $this->getName() . ".txt";
	}

	protected function getToolFn() : string
	{
		return $this->conf->getToolsDir() . DIRECTORY_SEPARATOR . "composer.phar";
	}

	protected function getDemoVersion() : string
	{
		return $this->conf->getSectionItem($this->getName(), "symfony_demo_version");
	}

	protected function getDocroot() : string
	{
		foreach (array("public", "web") as $dir) {
			$docroot = $this->base . DIRECTORY_SEPARATOR . $dir;
			if (is_dir($docroot)) {
				return $docroot;
			}
		}

		return $this->base . DIRECTORY_SEPARATOR . "public";
	}

	protected function getBootstrapPaths() : array
	{
		return array(
			"/en/blog/",
			"/en/",
			"/blog/",
			"/",
		);
	}

	protected function getDatabaseName() : string
	{
		return $this->getName();
	}

	protected function getDatabaseUrl() : string
	{
		$user = rawurlencode($this->getDbUser());
		$pass = $this->getDbPass();
		$auth = $user;

		if ("" !== $pass) {
			$auth .= ":" . rawurlencode($pass);
		}

		return sprintf(
			'mysql://%s@%s:%d/%s?charset=utf8mb4',
			$auth,
			$this->getDbHost(),
			$this->getDbPort(),
			rawurlencode($this->getDatabaseName())
		);
	}

	/** @return array{body:?string,status:int,effective_url:?string} */
	protected function fetchBootstrapUrl(string $url) : array
	{
		$ret = array(
			"body" => NULL,
			"status" => 0,
			"effective_url" => NULL,
		);

		$c = curl_init($url);
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($c, CURLOPT_TIMEOUT, 60);
		curl_setopt($c, CURLOPT_USERAGENT, "PHP-SDK PGO symfony_demo_pdo_mysql");

		$body = curl_exec($c);
		if (false !== $body && !curl_errno($c)) {
			$ret["status"] = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
			$ret["effective_url"] = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
			if ($ret["status"] >= 200 && $ret["status"] < 400) {
				$ret["body"] = $body;
			}
		}

		if (PHP_VERSION_ID < 80500) {
			curl_close($c);
		}

		return $ret;
	}

	protected function runHostCommand(string $command, array $extra_env = array()) : void
	{
		$php = new PHP\CLI($this->conf);
		$exit_code = $php->exec($command, NULL, $extra_env);

		if (0 !== $exit_code) {
			throw new Exception("Command failed with exit code '$exit_code': $command");
		}
	}

	protected function runConsoleCommand(string $args, string $env = "prod") : void
	{
		$extra_env = array(
			"APP_DEBUG" => "0",
			"APP_ENV" => $env,
		);
		$console = $this->base . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "console";
		$this->runHostCommand($console . " " . $args . " --env=$env --no-interaction", $extra_env);
	}

	protected function updateFileContents(string $filename, string $search, string $replace) : void
	{
		if (!file_exists($filename)) {
			throw new Exception("Path '$filename' doesn't exist.");
		}

		$contents = file_get_contents($filename);
		if (false === $contents) {
			throw new Exception("Failed to read '$filename'.");
		}

		if (false !== strpos($contents, $replace)) {
			return;
		}

		if (false === strpos($contents, $search)) {
			throw new Exception("Failed to update '$filename', expected marker not found.");
		}

		$contents = str_replace($search, $replace, $contents);
		if (strlen($contents) !== file_put_contents($filename, $contents)) {
			throw new Exception("Failed to write '$filename'.");
		}
	}

	protected function writeLocalEnv() : void
	{
		$lines = array(
			"APP_ENV=prod",
			"APP_DEBUG=0",
			"APP_SECRET=" . bin2hex(random_bytes(16)),
			'MAILER_DSN=null://null',
			sprintf('DEFAULT_URI=http://%s:%d', $this->getHttpHost(), $this->getHttpPort()),
			sprintf('DATABASE_URL="%s"', $this->getDatabaseUrl()),
		);

		$env_file = $this->base . DIRECTORY_SEPARATOR . ".env.local";
		$contents = implode(PHP_EOL, $lines) . PHP_EOL;
		if (strlen($contents) !== file_put_contents($env_file, $contents)) {
			throw new Exception("Failed to write '$env_file'.");
		}
	}

	protected function prepareTrainingAssets() : void
	{
		$this->updateFileContents(
			$this->base . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "base.html.twig",
			"{% block importmap %}{{ importmap('app') }}{% endblock %}",
			"{% block importmap %}{# Disabled for deterministic PGO training. #}{% endblock %}"
		);

		$this->updateFileContents(
			$this->base . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "admin" . DIRECTORY_SEPARATOR . "layout.html.twig",
			"    {{ importmap(['app', 'admin']) }}",
			"    {# Disabled for deterministic PGO training. #}"
		);

		$this->updateFileContents(
			$this->base . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "packages" . DIRECTORY_SEPARATOR . "asset_mapper.yaml",
			"        missing_import_mode: strict",
			"        missing_import_mode: warn"
		);
	}

	protected function emitLogTail(string $label, string $filename, int $max_lines = 40) : void
	{
		if (!file_exists($filename)) {
			echo "$label log '$filename' was not found.\n";
			return;
		}

		$lines = file($filename, FILE_IGNORE_NEW_LINES);
		if (false === $lines || empty($lines)) {
			echo "$label log '$filename' is empty.\n";
			return;
		}

		$tail = array_slice($lines, -$max_lines);
		echo "Last " . count($tail) . " line(s) from $label log '$filename':\n";
		echo implode("\n", $tail), "\n";
	}

	protected function dumpBootstrapDiagnostics() : void
	{
		echo "Collecting bootstrap diagnostics for " . $this->getName() . ".\n";

		$this->emitLogTail(
			"PHP",
			$this->php->getRootDir() . DIRECTORY_SEPARATOR . "pgo_run_error.log"
		);
		$this->emitLogTail(
			"Symfony",
			$this->base . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . "dev.log"
		);
		$this->emitLogTail(
			"NGINX",
			$this->conf->getSrvDir("nginx") . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "error.log"
		);
	}

	protected function setupDatabase() : void
	{
		$this->maria->up();

		try {
			$db_name = $this->getDatabaseName();
			$this->maria->query("DROP DATABASE IF EXISTS " . $db_name);
			$this->maria->query("CREATE DATABASE " . $db_name . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

			$this->runConsoleCommand('doctrine:schema:create', 'prod');
			$this->runConsoleCommand('doctrine:fixtures:load', 'dev');
		} finally {
			$this->maria->down(true);
		}
	}

	protected function setupDist() : void
	{
		if (!file_exists($this->base . DIRECTORY_SEPARATOR . "composer.json")) {
			echo "Setting up in '{$this->base}'\n";
			$ver = $this->getDemoVersion();

			if (is_dir($this->base)) {
				$this->rm($this->base);
			}

			$this->runHostCommand(
				$this->getToolFn()
				. " create-project --no-interaction --no-progress --prefer-dist --no-scripts symfony/symfony-demo "
				. $this->base . " " . $ver
			);
		}

		$this->getHttpPort();
		$this->getHttpHost();
		$this->writeLocalEnv();
		$this->prepareTrainingAssets();
		$this->setupDatabase();

		$vars = array(
			$this->conf->buildTplVarName($this->getName(), "docroot") => str_replace("\\", "/", $this->getDocroot()),
		);
		$tpl_fn = $this->conf->getCasesTplDir($this->getName()) . DIRECTORY_SEPARATOR . "nginx.partial.conf";
		$this->nginx->addServer($tpl_fn, $vars);
	}

	/** @return void */
	public function setupUrls()
	{
		$this->maria->up();
		$this->nginx->up();

		try {
			$s = false;
			$url = NULL;
			foreach ($this->getBootstrapPaths() as $path) {
				$probe = "http://" . $this->getHttpHost() . ":" . $this->getHttpPort() . $path;
				$res = $this->fetchBootstrapUrl($probe);
				if (NULL !== $res["body"]) {
					$s = $res["body"];
					$url = $res["effective_url"] ?: $probe;
					break;
				}

				echo "Bootstrap probe failed for '$probe' with HTTP status " . $res["status"] . ".\n";
			}

			if (NULL === $url || false === $s) {
				$this->dumpBootstrapDiagnostics();
				throw new Exception("Failed to determine a bootstrap URL for " . $this->getName() . ".");
			}

			echo "Using bootstrap URL '$url'.\n";
			echo "Generating training urls.\n";

			$lst = array();
			if (preg_match_all(", href=\"([^\"]+)\",", $s, $m)) {
				foreach ($m[1] as $u) {
					if (strlen($u) >= 2 && "/" == $u[0] && "/" != $u[1] && !in_array(substr($u, -3), array("css", "xml", "ico"))) {
						$ur = "http://" . $this->getHttpHost() . ":" . $this->getHttpPort() . $u;
						if (!in_array($ur, $lst) && $this->probeUrl($ur)) {
							$lst[] = $ur;
						}
					}
				}
			}

			if (empty($lst)) {
				printf("\033[31m WARNING: Training URL list is empty, check the regex and the possible previous error messages!\033[0m\n");
			}

			$fn = $this->getJobFilename();
			$s = implode("\n", $lst);
			if (strlen($s) !== file_put_contents($fn, $s)) {
				throw new Exception("Couldn't write '$fn'.");
			}
		} finally {
			$this->nginx->down(true);
			$this->maria->down(true);
		}
	}

	public function prepareInit(Tool\PackageWorkman $pw, bool $force = false) : void
	{
	}

	public function init() : void
	{
		echo "Initializing " . $this->getName() . ".\n";

		$this->setupDist();
		$this->setupUrls();

		echo $this->getName() . " initialization done.\n";
		echo $this->getName() . " site configured to run under " . $this->getHttpHost() . ":" . $this->getHttpPort() . "\n";
	}
}
