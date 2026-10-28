# HELLO WEBGL

Démo de `JumPHP\Gl` : des cubes en 3D texturés (shaders GLSL écrits en PHP) sous un HUD 2D SDL, et des
shaders de post-traitement sur toute l'image (CRT, vague, 4 couleurs). La 3D passe par l'extension PHP-OpenGL :
OpenGL sur le bureau, WebGL dans le navigateur, le même code. Sans OpenGL (tests sans écran), la même scène
s'affiche en fil de fer (3D logicielle avec `Mat4`).

Le menu **OPENGL BRUT** montre les fonctions `gl*` appelées directement (un tore et un cube éclairés pixel par
pixel) et le son 3D OpenAL : le bip vient du cube qui tourne, à gauche ou à droite.

Le jeu montre aussi :

- la pause automatique quand l'onglet est masqué (`pause_scene`) ;
- la manette (sticks, gâchettes, vibration) ;
- la PWA : jeu installable et jouable hors ligne.

```bash
bin/jumphp install hello-webgl
bin/jumphp run hello-webgl                    # bureau (OpenGL)
bin/jumphp build hello-webgl && bin/jumphp serve hello-webgl   # navigateur : http://localhost:8080
```

Commandes : flèches ou sticks pour tourner et zoomer (LT / RT), Espace ou A pour changer de shader,
Échap ou Start pour la pause.
