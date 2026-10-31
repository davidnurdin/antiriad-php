# TODO

## Apple apps: signing, TestFlight, the App Store (needs the Apple developer account)

`bin/jumphp apple <game>` (docs/apple.md) builds the macOS app (.dmg, Apple silicon + Intel), the iPhone / iPad
app (.ipa) and the simulator's app on GitHub's Macs, and tries them there; they are signed ad hoc. With the account
(99 $ a year):

- macOS: Developer ID certificate in the repository's secrets, `codesign --options runtime` + `notarytool` +
  `stapler` in the workflow: opens without the right click
- iOS: distribution certificate + provisioning profile (or automatic signing with an App Store Connect API key),
  the .ipa signed, uploaded to TestFlight (`xcrun altool --upload-app` / the App Store Connect API), then the store
  listing (screenshots: the simulator's, as check.sh takes them)
- a playground "Apple" section (bundle id, as the Android one) when the App Store is reached

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

## Full native apps in PHP: every system's APIs, an optional HTML / JS / CSS layer, an IDE (likely, by David)

Beyond games: any kind of app written in PHP, with the same packaging (`bin/jumphp android`, `export:standalone`,
later iOS).

- every system's APIs reachable from PHP through FFI: Android (JNI / NDK: sensors, camera, notifications, files,
  sharing...), Windows (Win32, WinRT), Linux (D-Bus, GTK...), macOS / iOS (Objective-C runtime, UIKit / AppKit),
  as PHP classes generated or written over them, the same names everywhere when the systems agree
- an option to embed an HTML / JS / CSS layer for the app's visual interface (the system's web view: Android
  WebView, WebView2, WebKitGTK, WKWebView), talking with the PHP side (calls and events both ways); without it, the
  interface drawn natively (SDL / OpenGL, or the system's widgets through FFI)
- in the long run: an IDE (a software, itself a JumPHP app) to develop every kind of app: the project, the code,
  run / test / package for each system, Claude inside

## Cleaning

- the GitHub pre-release `standalone-1460857eb89286a9`: its Linux micro needs AVX-512 (built for GitHub's
  processor, before docker/standalone/spc-target); no key points to it any more
