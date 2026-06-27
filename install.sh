#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# TeleMusic Bot — install.sh  (Ubuntu 24.04 LTS / Noble recommended)
# Run from the MusicBot root directory: bash install.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$ROOT_DIR/bin"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
info()    { echo -e "${GREEN}[✔]${NC} $*"; }
warn()    { echo -e "${YELLOW}[!]${NC} $*"; }
die()     { echo -e "${RED}[✘]${NC} $*"; exit 1; }
section() { echo -e "\n${CYAN}━━━ $* ━━━${NC}"; }

echo ""
echo "  TeleMusic Bot — Installer"
echo "  Root: $ROOT_DIR"
echo ""

# ── 0. Root warning ───────────────────────────────────────────────────────────
if [[ "$EUID" -eq 0 ]]; then
  warn "Running as root. Continuing with COMPOSER_ALLOW_SUPERUSER=1."
  export COMPOSER_ALLOW_SUPERUSER=1
fi

# ── 1. OS check ───────────────────────────────────────────────────────────────
section "System check"
if [[ -f /etc/os-release ]]; then
  . /etc/os-release
  OS_ID="${ID:-unknown}"
  OS_VERSION="${VERSION_ID:-unknown}"
  info "OS: $PRETTY_NAME"
  if [[ "$OS_ID" == "ubuntu" && "${VERSION_ID%%.*}" -lt 24 ]]; then
    warn "Ubuntu $VERSION_ID detected. Ubuntu 24.04+ is strongly recommended."
    warn "The phptgcalls Rust extension may fail to compile on older Ubuntu."
    warn "Continuing anyway — press Ctrl-C within 5 seconds to abort."
    sleep 5
  fi
fi

# ── 2. PHP check ──────────────────────────────────────────────────────────────
section "PHP"
command -v php >/dev/null 2>&1 || die "PHP not found. Install PHP 8.4:
  sudo add-apt-repository ppa:ondrej/php
  sudo apt update
  sudo apt install -y php8.4 php8.4-cli php8.4-common php8.4-curl \\
       php8.4-mbstring php8.4-gmp php8.4-xml php8.4-intl"

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
PHP_VER="$PHP_MAJOR.$PHP_MINOR"

if [[ "$PHP_MAJOR" -lt 8 ]] || { [[ "$PHP_MAJOR" -eq 8 ]] && [[ "$PHP_MINOR" -lt 4 ]]; }; then
  die "PHP 8.4+ required, found $PHP_VER.

  Install PHP 8.4 on Ubuntu:
    sudo add-apt-repository ppa:ondrej/php
    sudo apt update
    sudo apt install -y php8.4 php8.4-cli php8.4-common php8.4-curl \\
         php8.4-mbstring php8.4-gmp php8.4-xml php8.4-intl
    sudo update-alternatives --set php /usr/bin/php8.4"
fi
info "PHP $PHP_VER"

# Check required extensions
MISSING_EXTS=()
for EXT in curl json openssl mbstring gmp; do
  php -m 2>/dev/null | grep -qi "^$EXT$" || MISSING_EXTS+=("php${PHP_VER}-${EXT}")
done
if [[ ${#MISSING_EXTS[@]} -gt 0 ]]; then
  die "Missing PHP extensions. Install them:
  sudo apt install -y ${MISSING_EXTS[*]}"
fi
info "PHP extensions OK (curl, json, openssl, mbstring, gmp)"

# ── 3. Composer ───────────────────────────────────────────────────────────────
section "Composer"

# Resolve which composer binary to use — prefer /usr/local/bin/composer (fresh)
# over system composer which may be old
COMPOSER=""
for candidate in /usr/local/bin/composer /usr/local/bin/composer2 /usr/bin/composer composer; do
  if command -v "$candidate" >/dev/null 2>&1; then
    _ver=$("$candidate" --version --no-interaction 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1 || true)
    _major=${_ver%%.*}
    if [[ -n "$_major" ]] && [[ "$_major" -ge 2 ]]; then
      # Check plugin-api version >= 2.5
      _plugin_ver=$("$candidate" --version --no-interaction 2>/dev/null | grep -oP 'Plugin API \K[\d.]+' || echo "0")
      _plugin_major=${_plugin_ver%%.*}
      _plugin_minor=$(echo "$_plugin_ver" | cut -d. -f2)
      if [[ "$_plugin_major" -gt 2 ]] || { [[ "$_plugin_major" -eq 2 ]] && [[ "$_plugin_minor" -ge 5 ]]; }; then
        COMPOSER="$candidate"
        break
      fi
    fi
  fi
done

if [[ -z "$COMPOSER" ]]; then
  warn "No Composer 2.5+ found — installing fresh Composer..."
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  EXPECTED_SIG=$(php -r "echo file_get_contents('https://composer.github.io/installer.sig');")
  ACTUAL_SIG=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
  [[ "$EXPECTED_SIG" == "$ACTUAL_SIG" ]] || die "Composer installer signature mismatch — aborting for security."
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
  COMPOSER=/usr/local/bin/composer
fi

COMPOSER_VER=$("$COMPOSER" --version --no-interaction 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
info "Composer $COMPOSER_VER at $COMPOSER"

# Helper: run composer cleanly (suppress deprecation noise from old system libs)
run_composer() {
  local dir="$1"; shift
  COMPOSER_ALLOW_SUPERUSER=1 \
  php -d error_reporting=E_ERROR \
    "$COMPOSER" --working-dir="$dir" \
    --no-interaction \
    "$@" \
    2>&1 | grep -v "^Deprecation Notice:" \
         | grep -v "^PHP Deprecated:" \
         | grep -v "^Deprecated:" \
         | grep -v "Do not run Composer as root" \
         | grep -v "Composer plugins have been disabled" \
         || true
}

# ── 4. Main PHP dependencies ──────────────────────────────────────────────────
section "PHP dependencies"
run_composer "$ROOT_DIR" install --prefer-dist --optimize-autoloader
info "PHP dependencies installed"

# ── 5. yt-dlp ────────────────────────────────────────────────────────────────
section "yt-dlp"
if command -v yt-dlp >/dev/null 2>&1; then
  warn "yt-dlp already installed at $(command -v yt-dlp) — updating..."
  yt-dlp -U --quiet 2>/dev/null || true
else
  if command -v pip3 >/dev/null 2>&1; then
    pip3 install -q yt-dlp
  elif command -v pip >/dev/null 2>&1; then
    pip install -q yt-dlp
  else
    warn "pip not found — downloading yt-dlp binary directly..."
    YTDLP_TARGET="/usr/local/bin/yt-dlp"
    if [[ ! -w "/usr/local/bin" ]]; then
      mkdir -p "$HOME/bin"
      YTDLP_TARGET="$HOME/bin/yt-dlp"
      warn "Installing to $YTDLP_TARGET — add '$HOME/bin' to your PATH."
    fi
    curl -sSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp" \
      -o "$YTDLP_TARGET"
    chmod +x "$YTDLP_TARGET"
  fi
fi
command -v yt-dlp >/dev/null 2>&1 && info "yt-dlp $(yt-dlp --version)" || die "yt-dlp install failed."

# ── 6. ffmpeg ─────────────────────────────────────────────────────────────────
section "ffmpeg"
if command -v ffmpeg >/dev/null 2>&1; then
  info "ffmpeg $(ffmpeg -version 2>&1 | head -1 | grep -oP 'ffmpeg version \K\S+')"
else
  warn "ffmpeg not found — installing..."
  if command -v apt-get >/dev/null 2>&1; then
    apt-get install -y -q ffmpeg
    info "ffmpeg installed"
  else
    warn "Could not auto-install ffmpeg. Install manually:"
    warn "  Debian/Ubuntu : sudo apt install ffmpeg"
    warn "  macOS         : brew install ffmpeg"
  fi
fi

# ── 7. Rust / Cargo ───────────────────────────────────────────────────────────
section "Rust toolchain"
if command -v cargo >/dev/null 2>&1; then
  info "Rust $(rustc --version)"
  rustup update stable --quiet 2>/dev/null || true
else
  info "Installing Rust (stable)..."
  curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --quiet
  # shellcheck source=/dev/null
  source "$HOME/.cargo/env"
  info "Rust $(rustc --version) installed"
fi

# Ensure cargo is in PATH for this session
[[ -f "$HOME/.cargo/env" ]] && source "$HOME/.cargo/env"
command -v cargo >/dev/null 2>&1 || die "cargo not found after Rust install. Run: source \$HOME/.cargo/env"

# ── 8. Clang (required by ext-php-rs) ────────────────────────────────────────
section "Clang"
if ! command -v clang >/dev/null 2>&1; then
  warn "Clang not found — installing..."
  if command -v apt-get >/dev/null 2>&1; then
    apt-get install -y -q clang libclang-dev
  else
    die "Please install clang manually: sudo apt install clang libclang-dev"
  fi
fi
info "$(clang --version | head -1)"

# ── 9. phptgcalls Rust extension ──────────────────────────────────────────────
# taknone/phptgcalls and taknone/liveproto are now real composer dependencies
# of MusicBot itself (see composer.json) and were already installed by the
# main "PHP dependencies" step above — there is no separate clone anymore.
#
# However: composer only auto-runs a package's own post-install-cmd when
# that package IS the root project. Since phptgcalls is a dependency here,
# its "cd ext && bash install.sh" hook (which builds the native Rust
# extension) does NOT fire automatically — we have to trigger it ourselves.
section "phptgcalls Rust extension"
PHPTGCALLS_DIR="$ROOT_DIR/vendor/taknone/phptgcalls"

if [[ ! -d "$PHPTGCALLS_DIR" ]]; then
  die "vendor/taknone/phptgcalls not found — did the main composer install succeed?"
fi

if [[ -f "$PHPTGCALLS_DIR/ext/install.sh" ]]; then
  info "Building phptgcalls Rust extension (this takes several minutes)..."
  cd "$PHPTGCALLS_DIR/ext"
  bash install.sh || true
  cd "$ROOT_DIR"

  # ext/install.sh's final `cp` into the PHP extension dir requires root and
  # silently fails for non-root users. Verify the extension actually landed
  # there and self-heal with sudo if not (see prior fixes — same pattern).
  PHP_EXT_DIR=$(php-config --extension-dir 2>/dev/null || true)
  BUILT_SO="$PHPTGCALLS_DIR/ext/target/release/libphptgcalls.so"
  if [[ -n "$PHP_EXT_DIR" ]] && [[ -f "$BUILT_SO" ]] && [[ ! -f "$PHP_EXT_DIR/phptgcalls.so" ]]; then
    warn "phptgcalls.so missing from $PHP_EXT_DIR — copying with sudo..."
    SUDO=""
    [[ "$EUID" -ne 0 ]] && SUDO="sudo"
    $SUDO cp "$BUILT_SO" "$PHP_EXT_DIR/phptgcalls.so"
  fi

  NTGCALLS_SO="$PHPTGCALLS_DIR/ext/ntgcalls/lib/libntgcalls.so"
  if [[ -f "$NTGCALLS_SO" ]] && ! ldconfig -p 2>/dev/null | grep -q libntgcalls.so; then
    warn "libntgcalls.so not on system library path — installing with sudo..."
    SUDO=""
    [[ "$EUID" -ne 0 ]] && SUDO="sudo"
    $SUDO cp "$NTGCALLS_SO" /usr/lib/x86_64-linux-gnu/
    $SUDO ldconfig
  fi

  PHP_CONF_DIR=$(php --ini 2>/dev/null | grep "Scan for additional" | sed 's/.*: *//')
  if [[ -n "$PHP_CONF_DIR" ]] && [[ ! -f "$PHP_CONF_DIR/20-phptgcalls.ini" ]]; then
    SUDO=""
    [[ "$EUID" -ne 0 ]] && SUDO="sudo"
    echo "extension=phptgcalls.so" | $SUDO tee "$PHP_CONF_DIR/20-phptgcalls.ini" >/dev/null
  fi

  if php -m 2>/dev/null | grep -qi '^phptgcalls$'; then
    info "phptgcalls Rust extension built and loaded"
  else
    warn "phptgcalls Rust extension built, but PHP isn't loading it yet. Run 'php -m | grep -i phptgcalls' to debug."
  fi
else
  warn "No ext/install.sh found at $PHPTGCALLS_DIR/ext — skipping Rust build."
fi

# ── 10. Runtime directories ───────────────────────────────────────────────────
section "Directories"
mkdir -p "$ROOT_DIR/downloads" "$ROOT_DIR/cookies" "$ROOT_DIR/logs" "$ROOT_DIR/queue/commands" "$ROOT_DIR/queue/results"
info "Runtime directories OK"

# ── 11. .env setup ───────────────────────────────────────────────────────────
section ".env"
if [[ -f "$ROOT_DIR/.env" ]]; then
  warn ".env already exists — skipping copy (delete it to reset)"
else
  cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
  info ".env created from .env.example — edit it with your credentials"
fi

# Write absolute bin paths into .env
sed -i.bak \
  -e "s|PHPTGCALLS_BIN=.*|PHPTGCALLS_BIN=$PHPTGCALLS_DIR|" \
  -e "s|LIVEPROTO_BIN=.*|LIVEPROTO_BIN=$LIVEPROTO_DIR|" \
  "$ROOT_DIR/.env"
rm -f "$ROOT_DIR/.env.bak"
info "Bin paths written to .env"

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║       Installation complete!             ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════╝${NC}"
echo ""
echo "  Edit .env and fill in your credentials:"
echo "    BOT_TOKEN   → from @BotFather"
echo "    API_ID      → from https://my.telegram.org/apps"
echo "    API_HASH    → from https://my.telegram.org/apps"
echo "    SESSION     → your MTProto session string"
echo "    API_KEY     → from https://deadlinetech.site or @smaugxd"
echo ""
echo "  Start the bot:"
echo "    php bot.php"
echo ""
