# antiriad-php

- The Sacred Armour of Antiriad, sorti par Palace Software en 1986 sur Amstrad CPC, ZX Spectrum et Commodore 64. Aux États-Unis, il s'appelait Rad Warrior.
- Les graphismes sont de Dan Malone. La boîte contenait une petite BD qui racontait l'histoire.

Histoire

Une guerre nucléaire a détruit la civilisation. Les survivants sont redevenus des tribus primitives dans la jungle. Des envahisseurs ont profité de cette faiblesse pour asservir la Terre, depuis une base cachée dans un volcan.

Les tribus vénèrent une relique de l'ancien monde : l'armure sacrée. Son nom, « Antiriad », vient de l'inscription « ANTI-RAD » mal comprise : c'est en fait une combinaison anti-radiations. Tal, le guerrier choisi, doit la retrouver, la remettre en marche et détruire la base ennemie.

Gameplay

- Exploration écran par écran d'un grand monde ouvert : jungle, arbres géants, grottes, ruines souterraines, puis la base dans le volcan.
- Phase 1, Tal sans armure : il est fragile, saute, grimpe et lance des pierres. Il doit éviter ou tuer la faune mutante et les gardes ennemis.
- Phase 2, Tal trouve l'armure et monte dedans. Elle est vide au départ et il faut retrouver ses modules dispersés dans le monde :
  - les bottes anti-gravité, pour voler et atteindre de nouvelles zones ;
  - le canon à impulsions (pulsar), l'arme de tir ;
  - le bouclier anti-radiations (particle negator), pour traverser les zones irradiées ;
  - d'autres modules encore. La liste exacte diffère un peu selon les versions, donc à vérifier si tu veux une reproduction fidèle.
- Énergie : chaque tir et chaque vol vident la batterie de l'armure. Il faut la recharger à des bornes ou avec des cellules d'énergie. Un affichage montre l'énergie et les modules déjà trouvés.
- Sortir de l'armure est possible et même parfois nécessaire, par exemple pour passer par un conduit étroit. Pendant ce temps, Tal redevient vulnérable.
- Fin : Tal entre dans le volcan, atteint le cœur de la base et le détruit avec un engin à implosion, puis il doit s'enfuir.

Ambiance

- Graphismes très détaillés pour l'époque, avec une palette organique (verts et ocres pour la jungle, rouges pour le volcan).
- Une animation de Tal soignée et un vrai sentiment d'isolement.
- Une difficulté élevée et peu d'indications : le joueur apprend en explorant.

Proposition pour JumPHP

- Carte : environ 40 à 60 écrans (jungle, arbres, grottes, ruines, zone irradiée, volcan).
- Commandes : flèches pour bouger et sauter, une touche pour tirer ou lancer une pierre, une touche pour entrer ou sortir de l'armure, et en vol la touche haut pour monter.
- Interface : barre d'énergie, icônes des modules trouvés et vies.
- Progression : des zones bloquées par des modules (pas de bottes = pas de falaise, pas de bouclier = pas de zone irradiée), dans un esprit metroidvania.
- Ennemis : animaux mutants dans la jungle, droïdes volants, gardes armés et tourelles dans la base.

## A remake of an Amstrad CPC game: use dsk2jumphp

This game is the remake of an Amstrad CPC game, **with its original graphics, music, sounds, levels and
rules**, taken from its disc. Follow `AGENTS.md` section 12 to the letter:

- use **[dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp)** (headless CPC emulator, DSK / dump / Z80 /
  sound tools, the method): clone it **outside this repository**, `make -C <it> test`, link its Claude skill
  (`.claude/skills/dsk2jumphp`), read its `AGENTS.md` and `docs/method.md`. Do not write an emulator, a
  disassembler or a DSK reader yourself;
- **ask the user for the path of the game's `.dsk` on their computer** (never download a disc or CPC ROMs;
  ROMs only from the user's machine if the game needs the firmware);
- keep the extraction reproducible in `games/antiriad-php/tools/extract/` (scripts + README of the addresses + one
  script that rebuilds `assets/` from the .dsk path); **never commit the .dsk, ROMs or memory dumps**;
- the original's screen size, rules and timings (50 Hz); check it side by side with the emulator
  (`cpc -s` against `bin/jumphp shot`);
- if the extracted assets must not be public, keep them out of git (`.gitignore`) and give them to the
  playground as **private files** (Studio → the project → "Fichiers privés"): the game loads them, no
  repository holds them.
- Example: the Rainbow Islands remake, https://github.com/davidnurdin/rainbow-island (`tools/extract/`).

---

## The JumPHP playground

This project was made on the JumPHP playground: the game's page is https://jumphp.net/antiriad-php
(category: Remakes).

- The game is `games/antiriad-php/`. Read `AGENTS.md` (the JumPHP contract) first; replace the skeleton with the game
  described above; follow the definition of done (tests, screenshots you look at, browser build).
- **Publishing**: every push to `main` is online within a minute (GitHub webhook). The MCP tool
  `sync_project` (server `jumphp`) publishes at once and returns the errors: a commit with a PHP syntax error
  is not published (the previous version stays online).
- The playground loads the game file by file with its shared engine: Composer packages other than
  `jumphp/engine` are not available there.
- **The game's home page**: make `games/antiriad-php/site/` (AGENTS.md section 13: themed with the game's own graphics,
  SEO, a link to `play/`); it is the game's page on the playground. Also `games/antiriad-php/assets/thumbnail.png`.
- When the game is done: MCP `update_project` with `listed: true` shows it in the playground's directory.