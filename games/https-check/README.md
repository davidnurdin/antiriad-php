# https-check

hello-world that checks HTTPS when its title screen opens, where JumPHP games run without a browser: the native
Android app (`bin/jumphp android`) and the standalone executables (`bin/jumphp export:standalone`). It prints on
stderr PHP's OpenSSL version, an `ssl://jumphp.net:443` connection (what online play's `wss://` uses) and an
`$engine->http()` request to `https://jumphp.net`, all with the certificates verified.

```bash
bin/jumphp install https-check
bin/jumphp export:standalone https-check --linux --windows
JUMPHP_SCREENSHOT=/tmp/s.png JUMPHP_MAX_FRAMES=240 JUMPHP_REALTIME=1 games/https-check/dist/standalone/https-check-linux-x86_64
bin/jumphp android https-check --local     # then: adb install ...; adb logcat -s JumPHP
```

Expected:

```
[https-check] openssl OpenSSL 3.x ...
[https-check] ssl:// OK
[https-check] https GET 200 ... bytes
```
