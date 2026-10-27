# Build, Docker, déploiement

Seul Docker est nécessaire. Images utilisées :

| Image | Rôle | Construite par |
|---|---|---|
| `jumphp/desktop:dev` | PHP 8.3 CLI + extensions SDL (mêmes forks et commits que le moteur wasm) + GD, composer | `bin/jumphp setup` (`docker/desktop/Dockerfile`) |
| moteur wasm (sortie fichiers, pas d'image) | `build/engine/php-web.{mjs,wasm}`, `php-wasm-network.js`, `engine.json` | `bin/jumphp engine:build` (`docker/engine-wasm`, buildx bake, cache `.cache/buildx-engine`) |
| `emscripten/emsdk:3.1.35` | `file_packager` (données du jeu) | téléchargée |
| `jumphp/relay:dev` | serveur relais php-wasm-network | `bin/jumphp relay up` (`docker/relay`) |
| `dunglas/frankenphp:1.12.7-alpine` | serveur web local (FrankenPHP : Caddy + PHP, prêt pour le portail) | téléchargée |
| `ghcr.io/puppeteer/puppeteer` | Chrome sans écran pour `bin/jumphp webshot` | téléchargée |

## Le moteur WebAssembly (`docker/engine-wasm`)

Repris de php-wasm-sdl : `emscripten/emsdk:3.1.35`, PHP-8.3.0 (SAPI embed, statique), extensions
`davidnurdin/php-sdl`, `kea/php-sdl-image`, `kea/php-sdl-mixer`, `kea/php-sdl-ttf` (leurs `config.m4`
remplacés pour lier les ports emscripten `-sUSE_SDL*=2`), `wasmnet` de php-wasm-network ; GD (PNG),
mbstring, bcmath, ctype, zlib, tokenizer. Tous les dépôts sont épinglés par commit (`ARG *_REF`, les
mêmes dans `docker/desktop/Dockerfile` et `docker/relay/Dockerfile`).

Points importants :
- `CFLAGS="-O2 ..."` au configure : sans lui PHP compile en `-O0` (7 à 12 fois plus lent).
- `PKG_CONFIG_PATH` passé comme variable de configure : `emconfigure` remplace celui de l'environnement.
- édition de liens `-O3`, LTO, `MODULARIZE`/`EXPORT_ES6`, `-lidbfs.js`, `--js-library library_wasmnet.js`,
  pas d'ASYNCIFY ni de threads (donc pas d'en-têtes COOP/COEP).
- `source/phpw.c` : `phpw_run(code)` évalue le code puis donne `jumphp_frame()` à `emscripten_set_main_loop`.
- `php-wasm-network.js` (côté navigateur du réseau) est livré avec le moteur : toujours du même commit
  que l'extension.

Changer de version : modifier les `ARG` (moteur, desktop, relais) et `engine/composer.json`
(`davidnurdin/php-wasm-network`), puis `bin/jumphp engine:build` et `bin/jumphp setup`.

## Build navigateur d'un jeu (`bin/jumphp build <jeu>`)

1. `tools/stage-web.sh` (image desktop) : copie propre du jeu dans `.build/<jeu>` (sans `vendor`, `var`,
   `dist`, `tests`), `composer install --no-dev` avec `COMPOSER_MIRROR_PATH_REPOS=1` (le moteur est copié,
   pas lié), container précompilé (`tools/dump-container.php`), page (`tools/render-web.php` :
   `index.html`, `game.json`, scripts).
2. `tools/package-web.sh` (image emsdk) : `file_packager` (`.build/<jeu>` → `/game` dans le navigateur,
   LZ4, cache IndexedDB), injection du chargeur dans `php-web.mjs` juste après `run();` (le commentaire
   `--pre-js` disparaît en `-O3`), `version.json` (empreintes, pour invalider les caches).

Résultat `games/<jeu>/dist/web/` : `index.html`, `jumphp.js`, `touch.js`, `php-wasm-network.js`,
`php-web.mjs`, `php-web.wasm` (~7,5 Mo, commun à tous les jeux), `php-web.data` (le jeu), `game.json`,
`version.json`. N'importe quel hébergement statique convient (aucun en-tête particulier) ; `index.html`
et `version.json` ne doivent pas être mis en cache longtemps.

## Bureau

`bin/jumphp run <jeu>` lance `php app.php` dans l'image desktop avec : votre utilisateur, le dépôt monté sur
`/workspace`, X11 (`DISPLAY`, `/tmp/.X11-unix`, `XAUTHORITY`) ou Wayland (`WAYLAND_DISPLAY`,
`JUMPHP_X11=1` pour forcer X11), le son par la socket PulseAudio / PipeWire (`$XDG_RUNTIME_DIR/pulse/native`),
`/dev/dri` (GPU) et `--network host` (relais local). Sans GPU, SDL passe en rendu logiciel.
`bin/jumphp doctor` vérifie tout ça. `bin/jumphp export:desktop <jeu>` produit une image autonome
`jumphp/game-<jeu>`.

## Déploiement

`bin/jumphp deploy <jeu>` : `rsync` de `dist/web/` vers `$JUMPHP_DEPLOY_TARGET/<jeu>/` (par exemple
`user@serveur:/var/www/jeux`, avec votre agent SSH). Le relais en production : le dossier
`server/` de php-wasm-network (Caddy en TLS + relais + coturn) ; les jeux utilisent alors
`net_url: wss://votre-domaine/ws` dans `game.yaml` ou `?net=` dans l'URL.
