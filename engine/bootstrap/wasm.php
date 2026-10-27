<?php

// Browser entry point. The page (engine/web/jumphp.js) evaluates its prelude (define('JUMPHP_WASM', true), ...)
// then requires this file. phpw.c (docker/engine-wasm) then calls jumphp_frame() at each requestAnimationFrame.
// The game files are at /game (emscripten file_packager), the player's files at /game/var (IndexedDB).
chdir('/game');
require '/game/vendor/autoload.php';

try {
    $GLOBALS['app'] = \JumPHP\Kernel\Application::boot('/game');
} catch (\Throwable $e) {
    \JumPHP\Kernel\Application::stderr('[jumphp] BOOT FAILED: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n"
        . $e->getTraceAsString() . "\n");
    return;
}

// declared only when the boot worked (a conditional declaration runs at execution time, not at compile time):
// without it, phpw_run() returns and the page shows the error
if (isset($GLOBALS['app'])) {
    function jumphp_frame(): void
    {
        $GLOBALS['app']->frame();
    }
}
