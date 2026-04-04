<?php

namespace jit;

use SDK\Build\PGO\Abstracts;
use SDK\Build\PGO\Config;
use SDK\Build\PGO\Interfaces;
use SDK\Build\PGO\PHP;
use SDK\Build\PGO\Tool;
use SDK\Exception;

class TrainingCaseHandler extends Abstracts\TrainingCase implements Interfaces\TrainingCase
{
	/** @var int */
	protected $max_runs = 2;

	/** @var string */
	protected $base;

	/** @var mixed */
	protected $php;

	public function __construct(Config $conf, ?Interfaces\Server $srv_http, ?Interfaces\Server\DB $srv_db)
	{
		$this->conf = $conf;
		$this->base = $this->conf->getCaseWorkDir($this->getName());
		$this->php = new PHP\CLI($this->conf);
	}

	public function getName() : string
	{
		return __NAMESPACE__;
	}

	public function getJobFilename() : string
	{
		return $this->conf->getJobDir() . DIRECTORY_SEPARATOR . $this->getName() . ".txt";
	}

	protected function getSourceScriptFilename() : string
	{
		return $this->conf->getCasesTplDir($this->getName()) . DIRECTORY_SEPARATOR . "pgo_jit.php";
	}

	protected function getSourceAppDir() : string
	{
		return $this->conf->getCasesTplDir($this->getName()) . DIRECTORY_SEPARATOR . "app";
	}

	protected function getScriptFilename() : string
	{
		return $this->base . DIRECTORY_SEPARATOR . "pgo_jit.php";
	}

	protected function getAppDir() : string
	{
		return $this->base . DIRECTORY_SEPARATOR . "app";
	}

	protected function ensureDir(string $dir) : void
	{
		if (!is_dir($dir)) {
			if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
				throw new Exception("Failed to create '{$dir}'.");
			}
		}
	}

	protected function setupFixture() : void
	{
		$this->ensureDir($this->base);

		$src_script = $this->getSourceScriptFilename();
		if (!file_exists($src_script)) {
			throw new Exception("Source workload '{$src_script}' doesn't exist.");
		}

		$src_app = $this->getSourceAppDir();
		if (!is_dir($src_app)) {
			throw new Exception("Source app fixture '{$src_app}' doesn't exist.");
		}

		$dst_script = $this->getScriptFilename();
		$dst_app = $this->getAppDir();

		if (file_exists($dst_script) && !unlink($dst_script)) {
			throw new Exception("Failed to remove stale workload '{$dst_script}'.");
		}

		if (file_exists($dst_app) && !$this->rm($dst_app)) {
			throw new Exception("Failed to remove stale app fixture '{$dst_app}'.");
		}

		if (!copy($src_script, $dst_script)) {
			throw new Exception("Failed to copy workload '{$src_script}' to '{$dst_script}'.");
		}

		if (!$this->cp($src_app, $dst_app)) {
			throw new Exception("Failed to copy app fixture '{$src_app}' to '{$dst_app}'.");
		}
	}

	protected function setupCommands() : void
	{
		$script = $this->getScriptFilename();
		$job = $this->getJobFilename();

		$this->ensureDir(dirname($job));

		if (strlen($script) !== file_put_contents($job, $script)) {
			throw new Exception("Couldn't write '{$job}'.");
		}
	}

	public function prepareInit(Tool\PackageWorkman $pw, bool $force = false) : void
	{
	}

	public function init() : void
	{
		echo "Initializing " . $this->getName() . ".\n";

		$this->setupFixture();
		$this->setupCommands();

		echo $this->getName() . " initialization done.\n";
		echo $this->getName() . " JIT workload configured at " . $this->getScriptFilename() . "\n";
	}
}
