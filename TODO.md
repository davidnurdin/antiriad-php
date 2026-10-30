# TODO

## A game as a native iPhone / iPad app (as `bin/jumphp android`)

The same design as the native Android app (docs/android-native.md): PHP 8.5 embed (static, no JIT) with JumPHP's
extensions, SDL2 (its Xcode project, UIKit), OpenGL ES (deprecated by Apple but still there; or SDL's Metal
renderer for the 2D), OpenAL Soft (CoreAudio), the on-screen controls drawn by the engine, the game in the app's
bundle.

- built on GitHub's macOS runners (Xcode is needed): `bin/jumphp ios <game>` as `bin/jumphp android`, a workflow
  in the project, the signing in its secrets, the build sent to TestFlight (App Store Connect API key)
- an Apple developer account is needed (99 $ a year), even to install it on one's own iPhone for more than 7 days
- App Store: interpreted code inside the app is allowed (guideline 2.5.2), not code downloaded later
- tested in the iOS simulator on GitHub's macOS runner (xcrun simctl, screenshots)

## Without PHP at run time: illegalstudio (to study)

Try a flag (e.g. `bin/jumphp export:standalone <game> --native`) that builds the game with
[elephc](https://github.com/illegalstudio/elephc) (illegalstudio) instead of embedding PHP: a compiler of a static
subset of PHP to native code, no Zend Engine (from its README, nothing tried yet).

What helps:
- modern PHP: classes, interfaces, traits, enums, closures, generators, namespaces, Fibers, Composer's autoload
  resolved when compiling; GD inside; C libraries through `extern` (its DOOM showcase: a game in compiled PHP)
- probably much faster than the interpreter, and a smaller executable

What blocks or needs work:
- platforms: executables for Linux x86_64 / ARM64 and macOS ARM64, static libraries for iOS; no Windows, no
  Android. So a variant for Linux (and maybe a lead for the iPhone app), not a replacement of PHP
- JumPHP's extensions do not exist there (php-sdl, PHP-OpenGL, PHP-OpenAL): the ~70 SDL / Mix / TTF / IMG functions
  the engine calls, and the GL / AL ones, declared again as `extern` with the same names (the engine barely
  changes); its C callbacks take no strings yet
- the engine's dynamic PHP: Symfony DependencyInjection (Reflection, autowiring: a container generated beforehand
  as plain PHP), laravel/serializable-closure (the worker threads: left out in this mode), `new $class()` for the
  scenes and ReactPHP to check
- the players' games are written freely (often by Claude): some will not compile, so the flag tries, falls back to
  PHP and says what blocked

First step: Pong or hello-world compiled by elephc on Linux, to see what passes and the speed gained. PHP stays
the default (Windows, Android).

## Cleaning

- the GitHub pre-release `standalone-1460857eb89286a9`: its Linux micro needs AVX-512 (built for GitHub's
  processor, before docker/standalone/spc-target); no key points to it any more
