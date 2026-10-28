# HELLO WEBGL

Démo de `JumPHP\Gl` : des cubes en 3D texturés (WebGL, shaders GLSL écrits en PHP) sous un HUD 2D SDL, et des
shaders de post-traitement sur toute l'image (CRT, vague, 4 couleurs). Sur desktop, sans WebGL, la même scène
s'affiche en fil de fer (3D logicielle avec `Mat4`).

Le jeu montre aussi :

- la pause automatique quand l'onglet est masqué (`pause_scene`) ;
- la manette (sticks, gâchettes, vibration) ;
- la PWA : jeu installable et jouable hors ligne.

```bash
bin/jumphp install hello-webgl
bin/jumphp run hello-webgl                    # desktop (fil de fer)
bin/jumphp build hello-webgl && bin/jumphp serve hello-webgl   # navigateur : http://localhost:8080
```

Commandes : flèches ou sticks pour tourner et zoomer (LT / RT), Espace ou A pour changer de shader,
Échap ou Start pour la pause.
