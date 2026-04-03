<?php

namespace symfony_demo;

use SDK\Build\PGO\Abstracts;
use SDK\Build\PGO\Interfaces;
use SDK\Build\PGO\Config;
use SDK\Build\PGO\PHP;
use SDK\Exception;
use SDK\Build\PGO\Tool;

class TrainingCaseHandler extends Abstracts\TrainingCase implements Interfaces\TrainingCase
{
	/** @var string */
	protected $base;

	/** @var ?Interfaces\Server $nginx */
	protected $nginx;

	/** @var mixed */
	protected $php;

	/** @var int */
	protected $max_runs = 4;

	public function __construct(Config $conf, ?Interfaces\Server $nginx, ?Interfaces\Server\DB $srv_db)
	{
		if (!$nginx) {
			throw new Exception("Invalid NGINX object");
		}

		$this->conf = $conf;
		$this->base = $this->conf->getCaseWorkDir($this->getName());
		$this->nginx = $nginx;
		$this->php = $nginx->getPhp();
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
			"/blog/",
			"/",
		);
	}

	protected function setupDist() : void
	{
		if (!file_exists($this->base . DIRECTORY_SEPARATOR . "composer.json")) {
			echo "Setting up in '{$this->base}'\n";
			$php = new PHP\CLI($this->conf);

			if (is_dir($this->base)) {
				$this->rm($this->base);
			}

			$php->exec($this->getToolFn() . " create-project --no-interaction symfony/symfony-demo " . $this->base);
		}

		$port = $this->getHttpPort();
		$host = $this->getHttpHost();

		$vars = array(
			$this->conf->buildTplVarName($this->getName(), "docroot") => str_replace("\\", "/", $this->getDocroot()),
		);
		$tpl_fn = $this->conf->getCasesTplDir($this->getName()) . DIRECTORY_SEPARATOR . "nginx.partial.conf";
		$this->nginx->addServer($tpl_fn, $vars);
	}

	/** @return void */
	public function setupUrls()
	{
		$this->nginx->up();

		$s = false;
		$url = NULL;
		foreach ($this->getBootstrapPaths() as $path) {
			$probe = "http://" . $this->getHttpHost() . ":" . $this->getHttpPort() . $path;
			$s = @file_get_contents($probe);
			if (false !== $s) {
				$url = $probe;
				break;
			}
		}
		if (NULL === $url || false === $s) {
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

		$this->nginx->down(true);

		$fn = $this->getJobFilename();
		$s = implode("\n", $lst);
		if (strlen($s) !== file_put_contents($fn, $s)) {
			throw new Exception("Couldn't write '$fn'.");
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
