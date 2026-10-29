# L'Armure Sacrée d'Antiriad (antiriad-php)

Remake en PHP (JumPHP) de *The Sacred Armour of Antiriad* (Palace Software, 1986), version Amstrad CPC,
reconstruit à partir de la disquette originale avec [dsk2jumphp](https://github.com/davidnurdin/dsk2jumphp).

Les données du jeu original (`assets/rooms`, `sprites`, `hud`, `data`, musiques et sons) ne sont pas dans le
dépôt : on les régénère depuis sa propre disquette :

```bash
games/antiriad-php/tools/extract/extract.sh <disque.dsk> <dsk2jumphp> <cpc6128.rom> <amsdos.rom>
```

Depuis la racine du dépôt :

```bash
bin/jumphp install antiriad-php   # dépendances
bin/jumphp run antiriad-php       # jouer sur le bureau
bin/jumphp test antiriad-php      # vérifications + test automatique sans écran
bin/jumphp build antiriad-php     # version navigateur -> dist/web
```

Commandes : flèches pour marcher, haut / Z pour sauter (propulsion dans l'armure), Espace pour lancer /
tirer, bas pour s'accroupir / ramasser / entrer dans l'armure, E pour entrer / sortir de l'armure, Échap pour
la pause. Manette et écran tactile gérés.
