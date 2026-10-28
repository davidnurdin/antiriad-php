<?php

// A game as a "remote" game (bin/jumphp build:remote, docs/remote.md): its files one by one in <out>/files/,
// <out>/manifest.json (what jumphp-files.js loads) and <out>/index.html (engine/web/index.html with the
// shared engine folder). A games site does the same with its own code: the format is in docs/remote.md.
//   php tools/remote-manifest.php <game dir> <out dir> [engine url = engine/] [runtime dir = build/runtime]
[, $game, $out, $engineUrl, $runtime] = $argv + [null, null, null, 'engine/', __DIR__ . '/../build/runtime'];
if (!$game || !$out || !is_file("$game/config/game.yaml")) {
    fwrite(STDERR, "usage: php tools/remote-manifest.php <game dir> <out dir> [engine url] [runtime dir]\n");
    exit(1);
}
if (!is_file("$runtime/version.json")) {
    fwrite(STDERR, "no runtime in $runtime: bin/jumphp runtime:build\n");
    exit(1);
}
require "$game/vendor/autoload.php";

// what a normal build leaves out too (tools/stage-web.sh)
const SKIP = '#^(vendor|dist|\.build|var|cache|tests|site|\.git[^/]*)(/|$)|^phpstan\.neon$|(^|/)core(\.[0-9]+)?$|(^|/)\.DS_Store$#';

$config = JumPHP\Kernel\GameConfig::load($game)->all();
$composer = json_decode((string)@file_get_contents("$game/composer.json"), true) ?: [];
$autoload = [];
foreach ($composer['autoload']['psr-4'] ?? ['App\\' => 'src/'] as $prefix => $dirs) {
    $autoload[$prefix] = array_values((array)$dirs);
}

$game = rtrim($game, '/');
@mkdir("$out/files", 0777, true);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($game, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = substr($file->getPathname(), strlen($game) + 1);
    if (!$file->isFile() || preg_match(SKIP, $path)) {
        continue;
    }
    @mkdir(dirname("$out/files/$path"), 0777, true);
    copy($file->getPathname(), "$out/files/$path");
    $files[] = ['path' => $path, 'hash' => substr(hash_file('sha256', $file->getPathname()), 0, 16), 'size' => $file->getSize()];
}
usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

$version = json_decode((string)file_get_contents("$runtime/version.json"), true);
$runtimeInfo = json_decode((string)@file_get_contents("$runtime/runtime.json"), true) ?: [];
$info = [
    'id' => $config['id'],
    'title' => $config['title'],
    'version' => (string)$config['version'],
    'description' => $config['description'],
    'width' => (int)$config['width'],
    'height' => (int)$config['height'],
    'online' => (bool)$config['online'],
    'lang' => $config['lang'],
    'net_url' => $config['net_url'],
    'touch' => $config['touch'] ?? null,
    'controls' => $config['controls'] ?? null,
];
$manifest = ['format' => 1, 'engine' => $runtimeInfo['version'] ?? null] + $info
    + ['base' => 'files/', 'autoload' => $autoload, 'files' => $files];
file_put_contents("$out/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
$html = strtr(file_get_contents("$runtime/page.html"), [
    '{{TITLE}}' => $h($config['title']),
    '{{DESCRIPTION}}' => $h($config['description']),
    '{{LANG}}' => $h($config['lang']),
    '{{WIDTH}}' => (string)(int)$config['width'],
    '{{HEIGHT}}' => (string)(int)$config['height'],
    '{{ICON}}' => is_file("$game/assets/icon.png") ? 'files/assets/icon.png' : 'data:,',
    '{{PWA_HEAD}}' => '',
    '{{ENGINE}}' => $h($engineUrl),
    '{{ENGINE_VERSION}}' => $h($runtimeInfo['php'] ?? ''),
    '{{WASM_SIZE}}' => (string)(int)($runtimeInfo['wasm_size'] ?? filesize("$runtime/php-web.wasm")),
    '{{GAME_JSON}}' => json_encode($info + ['remote' => ['manifest' => 'manifest.json']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
    '{{V_JS}}' => $version['jumphp.js'] ?? '0',
    '{{V_TOUCH}}' => $version['touch.js'] ?? '0',
    '{{V_NET}}' => $version['php-wasm-network.js'] ?? '0',
]);
file_put_contents("$out/index.html", $html);
printf("remote game: %s (%d files)\n", $out, count($files));
