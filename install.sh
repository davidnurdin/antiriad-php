#!/bin/sh
# JumPHP's installer: the `jumphp` command (tools/jumphp-cli) in ~/.local/bin, on Linux, macOS or Windows (WSL).
#   curl -fsSL https://jumphp.net/install.sh | sh                  the command
#   curl -fsSL https://jumphp.net/install.sh | sh -s -- my-game    the command, then a new project in ./my-game
# Then: jumphp new <folder> (a project: the wizard checks git and Docker, and offers to install them).
# It changes nothing else than ~/.local/bin/jumphp and, if that folder is not in the PATH, one line of the shell's
# profile (said). JUMPHP_BIN_DIR: another folder. JUMPHP_REF: another branch or tag of JumPHP (default main).
set -eu

REPO="https://raw.githubusercontent.com/davidnurdin/jumPhp/${JUMPHP_REF:-main}"
BIN="${JUMPHP_BIN_DIR:-$HOME/.local/bin}"

say()  { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

case "$(uname -s)" in
  Linux) if grep -qi microsoft /proc/version 2>/dev/null; then SYSTEM="Windows (WSL)"; else SYSTEM=Linux; fi ;;
  Darwin) SYSTEM=macOS ;;
  MINGW*|MSYS*|CYGWIN*) die "on Windows, JumPHP runs in WSL: in PowerShell, irm https://jumphp.net/install.ps1 | iex" ;;
  *) SYSTEM="$(uname -s)" ;;
esac
say "JumPHP for $SYSTEM"
command -v curl >/dev/null 2>&1 || die "curl is needed"
command -v bash >/dev/null 2>&1 || die "bash is needed"

mkdir -p "$BIN"
tmp="$(mktemp)"
curl -fsSL -A jumphp-install -o "$tmp" "$REPO/tools/jumphp-cli" || die "could not download the jumphp command"
grep -q '^CLI_VERSION=' "$tmp" || die "the download is not the jumphp command"
install -m 755 "$tmp" "$BIN/jumphp" 2>/dev/null || { cp "$tmp" "$BIN/jumphp" && chmod 755 "$BIN/jumphp"; }
rm -f "$tmp"
say "installed: $BIN/jumphp"

case ":$PATH:" in
  *":$BIN:"*) ;;
  *)
    case "${SHELL:-}" in
      */zsh) profile="$HOME/.zshrc" ;;
      */bash) profile="$HOME/.bashrc" ;;
      *) profile="$HOME/.profile" ;;
    esac
    if ! grep -qs "$BIN" "$profile"; then
      printf '\n# the jumphp command (JumPHP)\nexport PATH="%s:$PATH"\n' "$BIN" >> "$profile"
      say "$BIN added to the PATH in $profile (new terminals)"
    fi
    PATH="$BIN:$PATH"; export PATH ;;
esac

for tool in git docker; do
  command -v "$tool" >/dev/null 2>&1 || warn "$tool is not installed yet: the project's wizard offers to install it"
done

if [ $# -gt 0 ]; then
  say "a new project: $1"
  # the wizard asks questions: on the terminal, not on the piped script
  if [ -r /dev/tty ]; then exec "$BIN/jumphp" new "$1" </dev/tty; else exec "$BIN/jumphp" new "$1"; fi
fi
cat <<EOF

Next:
  jumphp new my-game        a new project (a game in PHP, on the desktop and in the browser)
  cd my-game && jumphp help its commands, from any of its folders
(open a new terminal first if \`jumphp\` is not found)
EOF
