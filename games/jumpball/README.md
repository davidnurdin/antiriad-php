# JUMPBALL PHP

Un jeu de course en 3D écrit en PHP. Une balle roule toute seule sur une piste néon sans fin, de plus en plus
vite. Il faut se diriger et sauter pour éviter les trous et les blocs, et ramasser les gemmes au passage.

- **Vraie 3D sur les deux cibles** (`JumPHP\Gl`, extension PHP-OpenGL : OpenGL sur le bureau, WebGL dans le
  navigateur) : piste éclairée, ciel en shader avec un soleil rétro, ombre de la balle, post-traitement
  (vignette, lignes). Le HUD et les menus sont dessinés en 2D par SDL par-dessus.
- **Sans OpenGL** (pas de GPU, `JUMPHP_GL=0`) : le même jeu en 3D logicielle (faces remplies, ordre du peintre).

La piste est générée à partir d'une graine : trous sur toute la largeur, voies manquantes, blocs, chemin
étroit qui tourne, îles, plateformes surélevées. L'espace entre deux obstacles grandit avec la vitesse, pour
qu'un saut retombe toujours quelque part. Toutes les 250 m, les couleurs changent.

```bash
bin/jumphp install jumpball
bin/jumphp run jumpball                                          # bureau
bin/jumphp build jumpball && bin/jumphp serve jumpball           # navigateur : http://localhost:8080
bin/jumphp test jumpball                                         # le bot joue 600 frames sans écran
```

Commandes : flèches, A/D ou stick pour se diriger. Espace, Haut ou A pour sauter (maintenir pour ressauter dès
l'atterrissage). Échap ou Start pour la pause. Sur téléphone : un joystick rond sur la moitié gauche de l'écran, et le bouton JUMP.
