<?php

// Writes the web page of a game: index.html (from engine/web/index.html), game.json (the manifest the future
// games portal reads), the runtime scripts, icon / thumbnail. Called by tools/stage-web.sh.
[, $game, $out, $webDir, $engineDir] = $argv;
require $game . '/vendor/autoload.php';

$config = JumPHP\Kernel\GameConfig::load($game)->all();
$engineInfo = is_file("$engineDir/engine.json") ? json_decode(file_get_contents("$engineDir/engine.json"), true) : [];
$hash = fn (string $file) => is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '0';

foreach (['jumphp.js', 'touch.js'] as $js) {
    copy("$webDir/$js", "$out/$js");
}
$icon = 'data:,';
foreach (['icon.png', 'thumbnail.png'] as $image) {
    if (is_file("$game/assets/$image")) {
        copy("$game/assets/$image", "$out/$image");
    }
}
if (is_file("$game/assets/icon.png")) {
    $icon = 'icon.png';
}

$manifest = [
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
    'thumbnail' => is_file("$game/assets/thumbnail.png") ? 'thumbnail.png' : null,
    'controls' => $config['controls'] ?? null,
    'engine' => ['name' => 'JumPHP', 'php' => $engineInfo['php'] ?? '8.3', 'network' => $engineInfo['network'] ?? null],
    'built' => gmdate('c'),
];
file_put_contents("$out/game.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
$html = strtr(file_get_contents("$webDir/index.html"), [
    '{{TITLE}}' => $h($config['title']),
    '{{DESCRIPTION}}' => $h($config['description']),
    '{{LANG}}' => $h($config['lang']),
    '{{WIDTH}}' => (string)(int)$config['width'],
    '{{HEIGHT}}' => (string)(int)$config['height'],
    '{{ICON}}' => $icon,
    '{{ENGINE_VERSION}}' => $h($engineInfo['php'] ?? ''),
    '{{WASM_SIZE}}' => (string)(is_file("$engineDir/php-web.wasm") ? filesize("$engineDir/php-web.wasm") : 0),
    '{{GAME_JSON}}' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
    '{{V_JS}}' => $hash("$out/jumphp.js"),
    '{{V_TOUCH}}' => $hash("$out/touch.js"),
    '{{V_NET}}' => $hash("$engineDir/php-wasm-network.js"),
]);
file_put_contents("$out/index.html", $html);
echo "web page: $out/index.html\n";
