<?php
// Keep modern SDK scripts on the bundled interpreter. Only PHP 7.2's
// packaging script requires the older runtime's curly-offset syntax.
$args = array_slice($argv, 1);
$runtime = __DIR__;
foreach ($args as $i => $arg) {
    $path = str_replace('\\', '/', $arg);
    if (preg_match('~(?:^|/)win32/build/mkdist\.php$~i', $path)
        && preg_match('/^7\.2(?:\.|$)/', $args[$i + 1] ?? '')) {
        $runtime = dirname(__DIR__) . '/php73';
        break;
    }
}
$command = array_merge([$runtime . '/php.exe', '-c', $runtime . '/php.ini',
    '-d', 'curl.cainfo=' . getenv('PHP_SDK_ROOT_PATH') . '/msys2/usr/ssl/cert.pem',
    '-d', 'extension_dir=' . $runtime . '/ext'], $args);
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
exit(is_resource($process) ? proc_close($process) : 1);
