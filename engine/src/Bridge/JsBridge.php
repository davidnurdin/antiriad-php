<?php

namespace JumPHP\Bridge;

use JumPHP\Kernel\Platform;

/**
 * Messages from the game to the web page (browser only): open a link, share, go back to the games portal,
 * or anything the page handles. Each message is one JSON line appended to /tmp/jumphp-bridge; the page
 * reads the file every 250 ms (engine/web/index.html) and calls window.JumPHP.onBridge(message) for types
 * it does not know. On desktop, send() does nothing and returns false.
 *
 *   $bridge->send('open', ['url' => 'https://example.org']);
 *   $bridge->send('quit');                      // the page goes back (?back=URL) or shows "thanks for playing"
 */
class JsBridge
{
    public const FILE = '/tmp/jumphp-bridge';

    public function send(string $type, array $data = []): bool
    {
        if (!Platform::isWasm()) {
            return false;
        }
        return file_put_contents(self::FILE, json_encode(['t' => $type] + $data) . "\n", FILE_APPEND) !== false;
    }
}
