# Publier L'Armure Sacrée d'Antiriad (web, Android, Google Play)

Document pour les humains et les IA qui reprennent ce projet. À lire avant de toucher à l'Android, au site ou à la Play Console.
Les règles générales du moteur sont dans `AGENTS.md` (racine du dépôt) et `games/antiriad-php/AGENTS.md`.

## 1. Vue d'ensemble

| Cible | Où | Comment |
|---|---|---|
| Web (jeu jouable) | https://jumphp.net/antiriad-php/ (redirige vers /antiriad-php/jouer) | le playground publie `games/antiriad-php/` à chaque push sur `main` |
| Page du jeu (site) | https://jumphp.net/antiriad-php/ | `games/antiriad-php/site/index.html` |
| Confidentialité | https://jumphp.net/antiriad-php/#confidentialite | section de `site/index.html` (pas de page séparée : le playground ne sert que `index.html`) |
| App Android | `io.github.davidnurdin.antiriad_php` | Trusted Web Activity (TWA) qui ouvre le jeu dans Chrome plein écran |
| Google Play | Play Console, compte Duch'Inc. (développeur 4621944160072167336), appli 4972338030688135653 | piste interne puis production |

Le jeu lui-même n'est pas dans l'app : l'app est une coquille qui ouvre `https://jumphp.net/antiriad-php/jouer?app=1`. Une mise à jour du jeu est donc en ligne tout de suite, sans nouvelle version Android. Une nouvelle version Android n'est nécessaire que si l'icône, le nom, l'URL de départ, le paquet ou le lanceur changent.

## 2. Fichiers et clés

- `games/antiriad-php/android/twa-manifest.json` : description de l'app (dans git). `appVersionName` / `appVersionCode` = version Android, à faire monter à chaque build envoyé à Google Play.
- `games/antiriad-php/dist/android/` : `antiriad-php-<version>.aab` (à envoyer à Google Play), `.apk` (à installer sur un téléphone), `assetlinks.json`. `dist/` n'est pas dans git.
- Clé d'upload : `~/.jumphp/android/keys/io.github.davidnurdin.antiriad_php.keystore` et `.env` (mot de passe). **Hors git. À sauvegarder** (sans elle, plus de mises à jour signées avec la même clé ; Google Play a sa propre clé de signature, gérée par Google).
- Empreinte SHA-256 de la clé d'upload : `C7:6D:A8:24:77:A8:62:CA:19:4F:F3:31:05:92:00:26:DC:16:A1:19:BD:9E:B9:4C:65:B3:F8:E8:59:A7:34:91`.
- Empreinte SHA-256 de la clé de signature Google Play (Play Console > Configuration > Signature de l'appli) : `94:65:91:9E:52:80:B3:90:B6:EB:98:65:4D:E5:11:DD:D8:D9:BC:C9:33:97:96:C7:29:FA:22:EA:14:B9:B7:F4`.
- `https://jumphp.net/.well-known/assetlinks.json` (le playground) contient les **deux** empreintes. Sans elles, Android n'ouvre pas l'app en plein écran et affiche un sélecteur d'app.
- SDK Android : `~/.jumphp/android-sdk` (licence acceptée par l'utilisateur, jamais par une IA).

## 3. Construire l'app

```bash
cd /home/david/Projets/JumPHPGames/antiriad/repo
bin/jumphp twa antiriad-php --url https://jumphp.net/antiriad-php/ --package io.github.davidnurdin.antiriad_php
```

- Sortie : `games/antiriad-php/dist/android/antiriad-php-<version>.aab` et `.apk`.
- Chaque build incrémente la version (`BUMP=1` par défaut). Un build raté incrémente quand même le numéro : ce n'est pas grave, mais Google Play refuse un code déjà envoyé.
- `tools/twa.sh` fait trois choses utiles à connaître :
  1. après `bubblewrap update`, il ajoute dans `LauncherActivity.java` une redéfinition de `createTwaLauncher()` qui renvoie `new TwaLauncher(this, "com.android.chrome")`. Sans elle, Android propose de choisir le navigateur, et l'app se ferme si on ferme le sélecteur (voir §7) ;
  2. il accepte la licence du SDK seulement si `ACCEPT_LICENSE=1` (jamais par une IA) ;
  3. il crée un lien `bin` dans le SDK (exigé par Bubblewrap).
- `tools/twa-manifest.cjs` met `minSdkVersion` à 24 (androidbrowserhelper 2.7 l'exige).

## 4. Tester sur un téléphone

Le téléphone de l'utilisateur est un Xiaomi (Android 14, Chrome 156). Débogage USB activé, autorisation acceptée.

```bash
adb devices -l
adb shell pm get-app-links io.github.davidnurdin.antiriad_php   # doit afficher « jumphp.net: verified »
adb logcat -c; adb shell am force-stop io.github.davidnurdin.antiriad_php
adb shell am start -n io.github.davidnurdin.antiriad_php/.LauncherActivity
sleep 10; adb logcat -d -v time | grep -E "TwaLauncher|ResolverActivity|Abort background"
adb exec-out screencap -p > /tmp/x.png   # ou dans var/debug/, pas dans git
```

- Une APK signée avec la clé d'upload ne se met pas par-dessus une installation venue de Google Play (`INSTALL_FAILED_UPDATE_INCOMPATIBLE`) : désinstaller d'abord (la version Play sera alors à réinstaller depuis Play).
- `adb shell pm list users` : un espace clone (XSpace, utilisateur 999) qui contient un second Chrome fait apparaître le sélecteur ; `pm remove-user` efface cet espace et ses apps. Ne jamais le faire sans demander.

## 5. Google Play, pas à pas

Chemins de la Play Console (à ajouter à `https://play.google.com/console/u/0/developers/4621944160072167336/app/4972338030688135653/`) :

1. **Déclarations** (Contenu de l'appli) : publicités (aucune), accès à l'appli (aucune restriction), public cible (18 ans et plus, ne pas dire « pour enfants »), classification du contenu (questionnaire IARC : catégorie Jeu ; violence fictive, pixel art, pas de gore, pas de drogue, pas de jeux d'argent, pas de contenu généré par les utilisateurs, pas de localisation, pas d'achats), **sécurité des données** (aucune donnée collectée, aucune partagée), **politique de confidentialité** (https://github.com/davidnurdin/antiriad-php/blob/main/games/antiriad-php/PRIVACY.md — jumphp ne sert pas site/index.html), **ID de publicité** (non utilisé).
2. **Fiche du Play Store** (français) : titre, description courte et longue, icône 512, bannière 1024×500, captures téléphone (`games/antiriad-php/store/`), catégorie Jeu, gratuit.
3. **Piste interne** : téléverser le `.aab`, notes de version en `<fr-FR>...</fr-FR>` (balises sur leur propre ligne), testeurs via la liste de diffusion.
4. **Production** : même `.aab` (ou un plus récent), notes de version, pays par défaut, déploiement à 100 % ou progressif, puis « Envoyer pour examen ». Les déclarations doivent être toutes vertes avant l'envoi.

Pièges rencontrés :
- la description courte ou longue vide après une saisie automatisée : taper au clavier, ou vérifier le texte après coup ;
- « Importez au moins 1 ressource » : l'image est dans la bibliothèque mais pas attachée à la fiche (ajouter depuis le panneau de la bibliothèque) ;
- une liste de testeurs : taper l'adresse puis Entrée pour créer la puce, puis « Enregistrer » et confirmer ;
- le lien d'invitation d'un testeur ne marche qu'une fois connecté avec le bon compte et après avoir cliqué « Devenir testeur ».

## 6. Mettre le site à jour (ce qui est en ligne)

- Commit dans `main` puis `git push` : le playground publie tout seul après le push, mais **pas toujours** (un `sync_project` a déjà répondu « unchanged » alors qu'il avait publié). Vérifier avec `get_project` (`published_commit`) ou `curl -sL https://jumphp.net/antiriad-php/ | grep <texte>`.
- Appeler `sync_project` (MCP `jumphp`) après le push si le texte attendu n'apparaît pas.
- `site/index.html` contient la section « confidentialité » (`id="confidentialite"`). `site/privacy.html` n'est pas servi (404) : ne pas s'y fier.

## 7. Le problème du lancement (et sa solution)

Symptôme : l'icône s'affiche, puis l'app se ferme. Cause constatée dans `adb logcat` :
- la TWA envoie un intent `VIEW` vers `https://jumphp.net/...` ;
- Android ouvre le sélecteur « Ouvrir avec » car il y a deux Chrome (un dans l'espace clone XSpace) ;
- quand le sélecteur passe au premier plan, l'app ne peut plus lancer d'activité (« Abort background activity starts ») et se termine.

Solutions appliquées :
1. `createTwaLauncher()` avec le paquet `com.android.chrome` (dans `tools/twa.sh`) ;
2. suppression de l'espace clone (`adb shell pm remove-user 999`, validé par l'utilisateur) ;
3. l'app v7 s'ouvre alors sur le titre du jeu (vérifié sur téléphone).

Ce qui n'a PAS marché : `getProviderPackage()` (n'existe pas dans la bibliothèque, erreur de compilation) ; chercher le problème seulement côté site (le site et Chrome fonctionnent, `jumphp.net/antiriad-php/jouer` s'ouvre directement).

Pour vérifier une classe de la bibliothèque : `javap -p -cp <classes> <classe>` dans l'image `jumphp/twa:dev` (JDK 17 inclus, `--entrypoint /opt/jdk/bin/javap`). Les classes sont dans `~/.jumphp/gradle/caches/…/androidbrowserhelper-2.7.4.aar`.

## 8. Ce qu'il ne faut pas faire

- Ne jamais dire que le jeu est « pour enfants » (l'utilisateur ne le veut pas : cela réduit la visibilité).
- Ne pas accepter la licence Android, ni cocher les conditions de Google, ni répondre à un questionnaire de classification à la place de l'utilisateur sans le lui dire.
- Ne pas envoyer en production sans confirmation explicite de l'utilisateur pour CET envoi.
- Ne jamais mettre dans git : les clés, les mots de passe, le contenu de `dist/`, `var/`, les captures de débogage, les jetons MCP.
- Ne pas `pm remove-user` ni `adb uninstall` sans demander (perte de données).
