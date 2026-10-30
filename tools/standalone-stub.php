<?php
// A JumPHP game as one executable (bin/jumphp export:standalone): StaticPHP's micro (PHP with SDL, OpenGL, OpenAL)
// followed by this PHAR. SDL reads pictures and sounds from real files: the game is unpacked once, in the player's
// cache, in a folder of its own version; the saves and options go to the player's data folder (JUMPHP_VAR).
// JUMPHP_STANDALONE_DIR: another folder to unpack into.
Phar::mapPhar('jumphp-game.phar');

const JUMPHP_GAME_ID = '{{ID}}';
const JUMPHP_GAME_BUILD = '{{BUILD}}';

ini_set('memory_limit', '1G');
$windows = PHP_OS_FAMILY === 'Windows';
$home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir());
$cache = getenv('JUMPHP_STANDALONE_DIR') ?: ($windows
    ? (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()) . '\\JumPHP'
    : (getenv('XDG_CACHE_HOME') ?: "$home/.cache") . '/jumphp');
$data = $windows
    ? (getenv('APPDATA') ?: $home) . '\\' . JUMPHP_GAME_ID
    : (getenv('XDG_DATA_HOME') ?: "$home/.local/share") . '/' . JUMPHP_GAME_ID;
$root = $cache . DIRECTORY_SEPARATOR . JUMPHP_GAME_ID . '-' . JUMPHP_GAME_BUILD;

if (!is_file("$root/.unpacked")) {
    $tmp = "$root.tmp" . getmypid();
    $source = 'phar://jumphp-game.phar/game';
    @mkdir($tmp, 0777, true);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $to = $tmp . substr($file->getPathname(), strlen($source));
        if ($file->isDir()) {
            @mkdir($to, 0777, true);
        } elseif (!@copy($file->getPathname(), $to)) {
            fwrite(STDERR, "Cannot unpack the game into $tmp\n");
            exit(1);
        }
    }
    touch("$tmp/.unpacked");
    // another copy of the game unpacked it meanwhile: keep that one
    if (!@rename($tmp, $root)) {
        $rm = static function (string $dir) use (&$rm): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    is_dir("$dir/$name") && !is_link("$dir/$name") ? $rm("$dir/$name") : @unlink("$dir/$name");
                }
            }
            @rmdir($dir);
        };
        $rm($tmp);
    }
}

@mkdir($data, 0777, true);
if (getenv('JUMPHP_VAR') === false) {
    putenv("JUMPHP_VAR=$data");
}
chdir($root);
require "$root/vendor/autoload.php";
exit(JumPHP\Kernel\Application::boot($root)->run());
__HALT_COMPILER();
