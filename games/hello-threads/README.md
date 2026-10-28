# HELLO THREADS

Le « hello world » des threads dans JumPHP : une fractale calculée ligne par ligne par un pool de threads PHP
([TrueAsync](https://true-async.github.io)), pendant que la boucle du jeu continue de dessiner à 60 images
par seconde. `M` calcule la même image sur le thread principal pour comparer.

The "hello world" of threads in JumPHP: a fractal computed row by row by a pool of PHP threads (TrueAsync)
while the game loop keeps drawing at 60 FPS. `M` computes the same picture on the main thread to compare.

```bash
bin/jumphp install hello-threads
bin/jumphp run hello-threads        # PHP 8.6 ZTS + TrueAsync (image built the first time, ~15 min)
```

Contrôles / controls: Espace / Space = zone suivante / next spot, M = threads ↔ thread principal / main
thread, Échap / Escape = pause.

Dans le navigateur, le même code passe par un pool de Web Workers (un moteur PHP WebAssembly chacun).
In the browser the same code uses a pool of Web Workers (one PHP WebAssembly engine each).
