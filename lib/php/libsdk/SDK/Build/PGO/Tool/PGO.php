<?php

namespace SDK\Build\PGO\Tool;

use SDK\Exception;
use SDK\Build\PGO\Config as PGOConfig;
use SDK\Build\PGO\Interfaces;

class PGO
{
	/** @var Interfaces\PHP */
	protected $php;

	/** @var PGOConfig */
	protected $conf;

	/** @var int */
	protected $idx = 0;

	public function __construct(PGOConfig $conf, Interfaces\PHP $php)
	{
		$this->conf = $conf;
		$this->php = $php;		
	}

	protected function getPgcName(string $fname) : string
	{
		$bn = basename($fname, substr($fname, -4, 4));
		$dn = dirname($fname);

		return $dn . DIRECTORY_SEPARATOR . $bn . "!" . $this->idx . ".pgc";
	}

	protected function getPgcPattern(string $fname) : string
	{
		$bn = basename($fname, substr($fname, -4, 4));
		$dn = dirname($fname);

		return $dn . DIRECTORY_SEPARATOR . $bn . "!*.pgc";
	}

	protected function getPgdName(string $fname) : string
	{
		$bn = basename($fname, substr($fname, -4, 4));
		$dn = dirname($fname);

		return $dn . DIRECTORY_SEPARATOR . $bn . ".pgd";
	}

	/** @return array<string> */
	protected function getPgcFiles(string $fname) : array
	{
		$ret = glob($this->getPgcPattern($fname));
		if (!is_array($ret)) {
			return array();
		}

		sort($ret);

		return array_values(array_unique($ret));
	}

	protected function execTool(string $cmd, bool $allow_fail = false) : void
	{
		$out = array();
		$ret = 0;

		exec($cmd . " 2>&1", $out, $ret);
		if (0 !== $ret && !$allow_fail) {
			$msg = implode("\n", $out);
			if ("" === $msg) {
				$msg = "Command '{$cmd}' failed with exit code {$ret}.";
			}
			throw new Exception($msg);
		}
	}

	/** @return array<string> */
	protected function getWorkItems() : array
	{
		$exe = glob($this->php->getRootDir() . DIRECTORY_SEPARATOR . "*.exe");
		$dll = glob($this->php->getRootDir() . DIRECTORY_SEPARATOR . "*.dll");
		$dll = array_merge($dll, glob($this->php->getExtRootDir() . DIRECTORY_SEPARATOR . "php*.dll"));

		/* find out next index */
		$tpl = glob($this->php->getRootDir() . DIRECTORY_SEPARATOR . "php{7,8,}{ts,}.dll", GLOB_BRACE)[0];
		if (!$tpl) {
			throw new Exception("Couldn't find php7[ts].dll in the PHP root dir.");
		}
		do {
			if (!file_exists($this->getPgcName($tpl))) {
				break;
			}
			$this->idx++;
		} while (true);

		return array_unique(array_merge($exe, $dll));
	}

	public function dump(bool $merge = true) : void
	{
		$its = $this->getWorkItems();	

		foreach ($its as $base) {
			$pgc = $this->getPgcName($base);
			$pgd = $this->getPgdName($base);

			$this->execTool('pgosweep "' . $base . '" "' . $pgc . '"', true);

			if ($merge) {
				$pgcs = $this->getPgcFiles($base);
				foreach ($pgcs as $file) {
					$this->execTool('pgomgr /merge:1000 "' . $file . '" "' . $pgd . '"');
					/* File is already spent, no need to keep it.
						If seeing linker warnings about no pgc
						were found for some object - most
						likely the object were not included in
						any training scenario. */
					@unlink($file);
				}
			}
		}
	}

	public function waste() : void
	{
		$this->dump(false);
	}

	public function clean(bool $clean_pgc = true, bool $clean_pgd = true) : void
	{
		if ($clean_pgc) {
			$its = glob($this->php->getRootDir() . DIRECTORY_SEPARATOR . "*.pgc");
			$its = array_merge($its, glob($this->php->getExtRootDir() . DIRECTORY_SEPARATOR . "*.pgc"));
			$its = array_merge($its, glob($this->php->getExtRootDir() . DIRECTORY_SEPARATOR . "*" . DIRECTORY_SEPARATOR . "*.pgc"));
			foreach (array_unique($its) as $pgc) {
				unlink($pgc);
			}
		}

		if ($clean_pgd) {
			$its = glob($this->php->getRootDir() . DIRECTORY_SEPARATOR . "*.pgd");
			$its = array_merge($its, glob($this->php->getExtRootDir() . DIRECTORY_SEPARATOR . "*.pgd"));
			$its = array_merge($its, glob($this->php->getExtRootDir() . DIRECTORY_SEPARATOR . "*" . DIRECTORY_SEPARATOR . "*.pgd"));
			foreach (array_unique($its) as $pgd) {
				$this->execTool('pgomgr /clear "' . $pgd . '"', true);
			}
		}
	}
}
