# JumPHP

[Français](#français) · [English](#english)

---

## Français

**Un framework PHP pour créer des jeux rétro 2D** (SDL2) qui tournent **sur le bureau** (PHP natif) et
**dans le navigateur** (PHP compilé en WebAssembly), avec exactement le même code.

Tiles et collisions au pixel (pentes, plateformes, cartes Tiled), entités et animations, caméra, son
(fichiers ou sons générés), menus, sauvegardes, traductions, clavier / manette / tactile, multijoueur en
ligne. **Tout passe par Docker** : la machine n'a besoin que de Docker (plus d'un écran et d'un serveur son
pour jouer sur le bureau). Jamais de PHP, d'emscripten ou de node à installer.

### Démarrage rapide

Démo en ligne : **https://davidnurdin.github.io/jumPhp/** (JUMPBALL en 3D WebGL, hello-webgl, hello-threads, Pong, casse-briques).

```bash
git clone https://github.com/davidnurdin/jumPhp.git && cd jumPhp
bin/jumphp setup              # images Docker (une fois, ~3 min)
bin/jumphp install pong       # dépendances du jeu
bin/jumphp run pong           # jouer sur le bureau (fenêtre + son)
```

Dans le navigateur :

```bash
bin/jumphp engine:build       # moteur WebAssembly (une fois par machine, ~25 min, en cache ensuite)
bin/jumphp build pong         # -> games/pong/dist/web (fichiers statiques)
bin/jumphp serve pong         # http://localhost:8080 (servi par FrankenPHP)
```

Pong : flèches + Entrée dans les menus, W / S (joueur 1), haut / bas (joueur 2), Échap ou P = pause,
F11 plein écran, F3 FPS. En ligne : `bin/jumphp relay up`, puis « En ligne » → « Partie rapide » dans deux
fenêtres (bureau et / ou navigateur).

### Créer un jeu

```bash
bin/jumphp new mon-jeu "MON JEU"   # copie de skeleton/ (un petit jeu de plateforme)
bin/jumphp install mon-jeu
bin/jumphp test mon-jeu            # lint, phpstan, phpunit, 600 frames sans écran
bin/jumphp shot mon-jeu 60         # capture PNG sans écran : games/mon-jeu/var/screenshots/shot.png
bin/jumphp run mon-jeu
```

Un jeu = des **scènes** (`src/Scene` : écran titre, partie, pause...) et des **entités** (`src/Entity`),
plus trois fichiers de configuration (`config/game.yaml`, `input.yaml`, `lang.yaml`). Aucun asset n'est
obligatoire : police bitmap intégrée, pixel art dans le code, sons synthétisés.

**Avec une IA** : [AGENTS.md](AGENTS.md) contient tout ce qu'il faut pour qu'un agent (Claude Code, etc.)
crée un jeu de A à Z sans rien connaître du projet : règles, commandes, API, recettes, vérifications. Le
casse-briques `games/breakout` a été écrit ainsi, par une IA qui n'avait lu que ce fichier.

### Contenu du dépôt

| Dossier | Contenu |
|---|---|
| `bin/jumphp` | la ligne de commande (`bin/jumphp help`) |
| `engine/` | le framework (paquet composer `jumphp/engine`, namespace `JumPHP\`) |
| `skeleton/` | le modèle de jeu copié par `bin/jumphp new` |
| `games/pong/` | jeu de référence : contre l'ordinateur, à deux sur un clavier, en ligne |
| `games/breakout/` | casse-briques écrit par une IA à partir d'AGENTS.md |
| `games/hello-threads/` | POC des threads (TrueAsync) : une fractale calculée par un pool de threads |
| `docker/` | moteur WebAssembly, runtimes bureau (PHP 8.3, et PHP 8.6 ZTS + TrueAsync), relais réseau, serveur web FrankenPHP |
| `tools/` | scripts utilisés par `bin/jumphp` (packaging, captures, contrôles) |
| `docs/` | documentation détaillée (français) |

### Commandes

| Commande | Rôle |
|---|---|
| `setup [async]` | construit les images Docker (`async` : aussi le runtime PHP 8.6 ZTS + TrueAsync) |
| `engine:build` | construit le moteur WebAssembly dans `build/engine/` |
| `new <jeu> ["Titre"]` | nouveau jeu depuis le squelette |
| `install <jeu>` / `composer <jeu> ...` | dépendances composer (paquets PHP purs uniquement) |
| `run <jeu>` | jouer sur le bureau (X11 ou Wayland, PulseAudio / PipeWire, GPU si disponible) |
| `test <jeu>` / `test engine` | contrôles du jeu / tests du moteur |
| `shot <jeu> [frames]` | capture d'écran sans écran |
| `build <jeu>` | version navigateur (`dist/web`, à poser sur n'importe quel hébergement statique) |
| `serve <jeu> [port]` | sert la version navigateur avec FrankenPHP |
| `webshot <jeu>` | teste la version navigateur dans un Chrome sans écran |
| `relay up\|down\|logs` | serveur multijoueur sur `ws://localhost:8090/ws` |
| `export:desktop <jeu>` | une image Docker qui lance le jeu |
| `deploy <jeu>` | rsync de `dist/web` vers `JUMPHP_DEPLOY_TARGET` (`.env`, voir `.env.dist`) |
| `pages [jeux...]` | construit les jeux (tous par défaut) et les publie avec une page d'accueil sur GitHub Pages (branche `gh-pages`, remplacée à chaque fois) |
| `lint` / `doctor` | contrôle de tout le dépôt / diagnostic de la machine |

`make run GAME=pong` etc. existe aussi (`Makefile`).

### Comment ça marche

- **Bureau** : PHP 8.3 CLI + extensions SDL2 / SDL_image / SDL_mixer / SDL_ttf, dans Docker, avec
  l'affichage et le son de l'hôte. Boucle ReactPHP, conteneur Symfony DI, événements Evenement.
- **Navigateur** : le même PHP 8.3 et les mêmes extensions compilés en WebAssembly avec emscripten
  ([php-wasm-sdl](https://github.com/davidnurdin/php-wasm-sdl)). Le navigateur appelle une frame PHP par
  `requestAnimationFrame`. Les sauvegardes vont dans IndexedDB. Le jeu est un dossier statique.
- **Réseau** : [php-wasm-network](https://github.com/davidnurdin/php-wasm-network) — même API en natif et
  dans le navigateur (WebSocket / WebRTC), avec son serveur relais.
- **Serveur web** : [FrankenPHP](https://frankenphp.dev) (Caddy + PHP). Les jeux sont statiques ; FrankenPHP
  est là pour accueillir plus tard la partie PHP du portail de jeux (comptes, scores...).
- **Threads** (optionnel) : `runtime: async` dans `game.yaml` fait tourner le jeu sur PHP 8.6 ZTS +
  [TrueAsync](https://true-async.github.io) : `$this->engine->tasks()` envoie le travail lourd dans de vrais
  threads, la frame n'attend jamais. Dans le navigateur, la même API passe par un pool de Web Workers (un
  moteur PHP WebAssembly chacun) ; sur le runtime par défaut, elle répartit le travail entre les frames. Voir [docs/async.md](docs/async.md) et `games/hello-threads`.

Contraintes du navigateur (détaillées dans [docs/navigateur.md](docs/navigateur.md)) : ne jamais bloquer la
frame (`sleep`, boucles infinies), entiers 32 bits, réseau uniquement via `JumPHP\Net` et `JumPHP\Http`, fichiers écrits
seulement dans `var/`.

### Documentation

- [AGENTS.md](AGENTS.md) : le guide complet (anglais), pour les humains comme pour les IA
- [docs/architecture.md](docs/architecture.md) : organisation du moteur, cycle d'une frame
- [docs/build.md](docs/build.md) : Docker, compilation du moteur, build navigateur, déploiement
- [docs/navigateur.md](docs/navigateur.md) : les pièges du WebAssembly
- [docs/tiles-collisions.md](docs/tiles-collisions.md) : cartes Tiled, masques, pentes, plateformes
- [docs/reseau.md](docs/reseau.md) : multijoueur en ligne, serveur relais, tests
- [docs/async.md](docs/async.md) : threads avec TrueAsync, runtime async, état du navigateur
- [docs/api-web.md](docs/api-web.md) : WebGL, mobile, cycle de vie de la page, HTTP asynchrone, PWA, manettes
- [docs/portage.md](docs/portage.md) : porter un jeu PHP/SDL existant vers JumPHP
- [docs/portail.md](docs/portail.md) : le futur site qui regroupera les jeux

---

## English

**A PHP framework to make retro 2D games** (SDL2) that run **on the desktop** (native PHP) and **in the
browser** (PHP compiled to WebAssembly), with exactly the same code.

Pixel-precise tiles and collisions (slopes, platforms, Tiled maps), entities and animations, camera, sound
(files or generated sounds), menus, saves, translations, keyboard / gamepad / touch, online multiplayer.
**Everything runs in Docker**: the machine only needs Docker (plus a display and a sound server to play on
the desktop). Never install PHP, emscripten or node.

### Quick start

Live demo: **https://davidnurdin.github.io/jumPhp/** (JUMPBALL in 3D WebGL, hello-webgl, hello-threads, Pong, Breakout).

```bash
git clone https://github.com/davidnurdin/jumPhp.git && cd jumPhp
bin/jumphp setup              # Docker images (once, ~3 min)
bin/jumphp install pong       # the game's dependencies
bin/jumphp run pong           # play on the desktop (window + sound)
```

In the browser:

```bash
bin/jumphp engine:build       # WebAssembly engine (once per machine, ~25 min, cached afterwards)
bin/jumphp build pong         # -> games/pong/dist/web (static files)
bin/jumphp serve pong         # http://localhost:8080 (served by FrankenPHP)
```

Pong: arrows + Enter in menus, W / S (player 1), Up / Down (player 2), Escape or P = pause, F11 fullscreen,
F3 FPS. Online: `bin/jumphp relay up`, then "Online" → "Quick match" in two windows (desktop and / or
browser).

### Make a game

```bash
bin/jumphp new my-game "MY GAME"   # copy of skeleton/ (a small platformer)
bin/jumphp install my-game
bin/jumphp test my-game            # lint, phpstan, phpunit, 600 headless frames
bin/jumphp shot my-game 60         # headless PNG: games/my-game/var/screenshots/shot.png
bin/jumphp run my-game
```

A game = **scenes** (`src/Scene`: title, play, pause...) and **entities** (`src/Entity`), plus three config
files (`config/game.yaml`, `input.yaml`, `lang.yaml`). No asset is required: built-in bitmap font, pixel art
in code, synthesized sounds.

**With an AI**: [AGENTS.md](AGENTS.md) holds everything an agent (Claude Code, etc.) needs to build a game
from scratch without knowing the project: rules, commands, API, recipes, checks. The `games/breakout` brick
breaker was written that way, by an AI that had only read this file.

### Repository

| Folder | Content |
|---|---|
| `bin/jumphp` | the command line (`bin/jumphp help`) |
| `engine/` | the framework (composer package `jumphp/engine`, namespace `JumPHP\`) |
| `skeleton/` | the game template copied by `bin/jumphp new` |
| `games/pong/` | reference game: against the computer, two players on one keyboard, online |
| `games/breakout/` | brick breaker written by an AI from AGENTS.md |
| `games/hello-threads/` | threads POC (TrueAsync): a fractal computed by a thread pool |
| `docker/` | WebAssembly engine, desktop runtimes (PHP 8.3, and PHP 8.6 ZTS + TrueAsync), network relay, FrankenPHP web server |
| `tools/` | scripts used by `bin/jumphp` (packaging, screenshots, checks) |
| `docs/` | detailed documentation (French) |

### Commands

| Command | What it does |
|---|---|
| `setup [async]` | builds the Docker images (`async`: also the PHP 8.6 ZTS + TrueAsync runtime) |
| `engine:build` | builds the WebAssembly engine into `build/engine/` |
| `new <game> ["Title"]` | new game from the skeleton |
| `install <game>` / `composer <game> ...` | composer dependencies (pure-PHP packages only) |
| `run <game>` | play on the desktop (X11 or Wayland, PulseAudio / PipeWire, GPU when available) |
| `test <game>` / `test engine` | game checks / engine tests |
| `shot <game> [frames]` | headless screenshot |
| `build <game>` | browser build (`dist/web`, put it on any static host) |
| `serve <game> [port]` | serves the browser build with FrankenPHP |
| `webshot <game>` | tests the browser build in headless Chrome |
| `relay up\|down\|logs` | multiplayer server on `ws://localhost:8090/ws` |
| `export:desktop <game>` | a Docker image that runs the game |
| `deploy <game>` | rsync `dist/web` to `JUMPHP_DEPLOY_TARGET` (`.env`, see `.env.dist`) |
| `pages [games...]` | builds the games (default: all) and publishes them with a home page on GitHub Pages (`gh-pages` branch, replaced each time) |
| `lint` / `doctor` | checks the whole repository / diagnoses the machine |

`make run GAME=pong` etc. also exists (`Makefile`).

### How it works

- **Desktop**: PHP 8.3 CLI + SDL2 / SDL_image / SDL_mixer / SDL_ttf extensions, in Docker, using the host's
  display and sound. ReactPHP loop, Symfony DI container, Evenement events.
- **Browser**: the same PHP 8.3 and extensions compiled to WebAssembly with emscripten
  ([php-wasm-sdl](https://github.com/davidnurdin/php-wasm-sdl)). The browser calls one PHP frame per
  `requestAnimationFrame`. Saves go to IndexedDB. The game is a static folder.
- **Network**: [php-wasm-network](https://github.com/davidnurdin/php-wasm-network) — same API natively and
  in the browser (WebSocket / WebRTC), with its relay server.
- **Web server**: [FrankenPHP](https://frankenphp.dev) (Caddy + PHP). Games are static; FrankenPHP is there
  to host the PHP side of the future games portal (accounts, scores...).
- **Threads** (optional): `runtime: async` in `game.yaml` runs the game on PHP 8.6 ZTS +
  [TrueAsync](https://true-async.github.io): `$this->engine->tasks()` sends heavy work to real threads, the
  frame never waits. In the browser the same API uses a pool of Web Workers (one PHP WebAssembly engine
  each); on the default runtime it spreads the work over the frames.
  See [docs/async.md](docs/async.md) (French), [AGENTS.md](AGENTS.md) §9 and `games/hello-threads`.

Browser constraints (see [AGENTS.md](AGENTS.md) §2): never block the frame (`sleep`, endless loops), 32-bit
integers, network only through `JumPHP\Net`, write files only into `var/`.

### Documentation

- [AGENTS.md](AGENTS.md): the complete guide (English), for humans and AIs alike
- [docs/](docs/): architecture, build, browser pitfalls, tiles and collisions, network, porting, portal (French)
