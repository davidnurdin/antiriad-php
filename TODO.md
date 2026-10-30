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

Try a flag (e.g. `bin/jumphp export:standalone <game> --no-php`, and the same for the native apps) that builds the
game with the tools of [illegalstudio](https://github.com/illegalstudio/) instead of embedding PHP. Not looked at
yet:

- which of its projects fits, and what it does with PHP code (compiled to native code, or another runtime)
- whether it handles what a JumPHP game needs: the engine's code, the extensions (SDL, OpenGL, OpenAL, gd,
  openssl), closures, ReactPHP's event loop, Composer's autoload
- on which platforms (Linux, Windows, Android, iOS), the size and speed against the micro / embed PHP
- if only part of it works: as an option, PHP stays the default

## Cleaning

- the GitHub pre-release `standalone-1460857eb89286a9`: its Linux micro needs AVX-512 (built for GitHub's
  processor, before docker/standalone/spc-target); no key points to it any more
- `games/https-check`: the temporary HTTPS check (not in git)
