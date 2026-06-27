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
    _minor=$(echo "$_ver" | cut -d. -f2)
    if [[ -n "$_major" ]] && [[ "$_major" -ge 2 ]]; then
      # Composer >= 2.5 always ships Plugin API >= 2.5, so the Composer
      # version itself is sufficient. Some builds don't print a "Plugin API"
      # line in plain `--version` output, so we no longer depend on parsing it.
      if [[ "$_major" -gt 2 ]] || { [[ "$_major" -eq 2 ]] && [[ "$_minor" -ge 5 ]]; }; then
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

# ── 9. phptgcalls ─────────────────────────────────────────────────────────────
section "phptgcalls"
mkdir -p "$BIN_DIR"
PHPTGCALLS_DIR="$BIN_DIR/phptgcalls"

if [[ -d "$PHPTGCALLS_DIR/.git" ]]; then
  warn "phptgcalls already cloned — pulling latest..."
  git -C "$PHPTGCALLS_DIR" pull --quiet
else
  info "Cloning phptgcalls..."
  git clone --quiet https://github.com/TakNone/phptgcalls "$PHPTGCALLS_DIR"
fi

# Install PHP deps for phptgcalls
run_composer "$PHPTGCALLS_DIR" install --prefer-dist --optimize-autoloader --no-scripts

# Build the Rust extension
if [[ -f "$PHPTGCALLS_DIR/ext/install.sh" ]]; then
  info "Building phptgcalls Rust extension (this takes several minutes)..."
  cd "$PHPTGCALLS_DIR/ext"
  bash install.sh
  cd "$ROOT_DIR"
  info "phptgcalls Rust extension built"
else
  warn "No ext/install.sh found in phptgcalls — skipping Rust build."
fi
info "phptgcalls ready at $PHPTGCALLS_DIR"

# ── 10. LiveProto ─────────────────────────────────────────────────────────────
section "LiveProto"
LIVEPROTO_DIR="$BIN_DIR/liveproto"

if [[ -d "$LIVEPROTO_DIR/.git" ]]; then
  warn "LiveProto already cloned — pulling latest..."
  git -C "$LIVEPROTO_DIR" pull --quiet
else
  info "Cloning LiveProto..."
  git clone --quiet https://github.com/TakNone/LiveProto "$LIVEPROTO_DIR"
fi

run_composer "$LIVEPROTO_DIR" install --prefer-dist --optimize-autoloader
info "LiveProto ready at $LIVEPROTO_DIR"

# ── 11. Runtime directories ───────────────────────────────────────────────────
section "Directories"
mkdir -p "$ROOT_DIR/downloads" "$ROOT_DIR/cookies" "$ROOT_DIR/logs"
info "Runtime directories OK"

# ── 12. .env setup ───────────────────────────────────────────────────────────
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
