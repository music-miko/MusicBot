#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# TeleMusic Bot — install.sh
# Run from the MusicBot root directory: bash install.sh
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Resolve the bot's root directory (wherever this script lives) ─────────────
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$ROOT_DIR/bin"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✔]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
die()   { echo -e "${RED}[✘]${NC} $*"; exit 1; }

echo ""
echo "  TeleMusic Bot — Installer"
echo "  Root: $ROOT_DIR"
echo ""

# ── 1. Check PHP ──────────────────────────────────────────────────────────────
info "Checking PHP..."
command -v php >/dev/null 2>&1 || die "PHP not found. Install PHP 8.1+ first."
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
[[ $(echo "$PHP_VER >= 8.1" | bc -l) -eq 1 ]] || die "PHP 8.1+ required, found $PHP_VER"
info "PHP $PHP_VER OK"

# Check required extensions
for EXT in curl json openssl mbstring; do
  php -m | grep -qi "^$EXT$" || die "PHP extension '$EXT' is missing. Install php-$EXT."
done
info "PHP extensions OK"

# ── 2. Check / install Composer ───────────────────────────────────────────────
info "Checking Composer..."
if ! command -v composer >/dev/null 2>&1; then
  warn "Composer not found — installing locally..."
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  php /tmp/composer-setup.php --quiet --install-dir="$ROOT_DIR" --filename=composer
  COMPOSER="$ROOT_DIR/composer"
else
  COMPOSER="composer"
fi
info "Composer OK"

# ── 3. Install main PHP dependencies ─────────────────────────────────────────
info "Installing PHP dependencies..."
cd "$ROOT_DIR"
"$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader
info "PHP dependencies installed"

# ── 4. Install yt-dlp ────────────────────────────────────────────────────────
info "Installing yt-dlp..."
if command -v yt-dlp >/dev/null 2>&1; then
  warn "yt-dlp already installed at $(command -v yt-dlp) — skipping"
else
  # Try pip first; fall back to direct binary download
  if command -v pip3 >/dev/null 2>&1; then
    pip3 install -q yt-dlp
  elif command -v pip >/dev/null 2>&1; then
    pip install -q yt-dlp
  else
    warn "pip not found — downloading yt-dlp binary directly..."
    YTDLP_TARGET="/usr/local/bin/yt-dlp"
    # If /usr/local/bin isn't writable, install to ~/bin
    if [[ ! -w "/usr/local/bin" ]]; then
      mkdir -p "$HOME/bin"
      YTDLP_TARGET="$HOME/bin/yt-dlp"
      warn "No write access to /usr/local/bin — installing to $YTDLP_TARGET"
      warn "Add '$HOME/bin' to your PATH if not already there."
    fi
    curl -sSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp" \
      -o "$YTDLP_TARGET"
    chmod +x "$YTDLP_TARGET"
  fi
fi
command -v yt-dlp >/dev/null 2>&1 && info "yt-dlp OK" || die "yt-dlp install failed."

# ── 5. Check ffmpeg ───────────────────────────────────────────────────────────
info "Checking ffmpeg..."
if command -v ffmpeg >/dev/null 2>&1; then
  info "ffmpeg OK"
else
  warn "ffmpeg not found. Install it:"
  warn "  Debian/Ubuntu : sudo apt install ffmpeg"
  warn "  macOS         : brew install ffmpeg"
  warn "  CentOS/RHEL   : sudo dnf install ffmpeg"
  warn "Continuing without ffmpeg — audio conversion may fail."
fi

# ── 6. Install phptgcalls into bin/ ──────────────────────────────────────────
info "Setting up phptgcalls..."
mkdir -p "$BIN_DIR"
PHPTGCALLS_DIR="$BIN_DIR/phptgcalls"

if [[ -d "$PHPTGCALLS_DIR/.git" ]]; then
  warn "phptgcalls already cloned — pulling latest..."
  git -C "$PHPTGCALLS_DIR" pull --quiet
else
  git clone --quiet https://github.com/TakNone/phptgcalls "$PHPTGCALLS_DIR"
fi

cd "$PHPTGCALLS_DIR"
"$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader
cd "$ROOT_DIR"
info "phptgcalls installed at $PHPTGCALLS_DIR"

# ── 7. Install LiveProto into bin/ ────────────────────────────────────────────
info "Setting up LiveProto..."
LIVEPROTO_DIR="$BIN_DIR/liveproto"

if [[ -d "$LIVEPROTO_DIR/.git" ]]; then
  warn "liveproto already cloned — pulling latest..."
  git -C "$LIVEPROTO_DIR" pull --quiet
else
  git clone --quiet https://github.com/TakNone/LiveProto "$LIVEPROTO_DIR"
fi

cd "$LIVEPROTO_DIR"
"$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader
cd "$ROOT_DIR"
info "liveproto installed at $LIVEPROTO_DIR"

# ── 8. Create required runtime directories ────────────────────────────────────
info "Creating runtime directories..."
mkdir -p "$ROOT_DIR/downloads" "$ROOT_DIR/cookies" "$ROOT_DIR/logs"
info "Directories OK"

# ── 9. Set up .env ───────────────────────────────────────────────────────────
if [[ -f "$ROOT_DIR/.env" ]]; then
  warn ".env already exists — skipping copy"
else
  cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
  info ".env created from .env.example"
fi

# ── 10. Patch .env bin paths to absolute paths ───────────────────────────────
# The config defaults are already correct (ROOT_DIR/bin/…), but if the user
# had set explicit paths in .env, we update them to the real locations.
sed -i.bak \
  -e "s|PHPTGCALLS_BIN=.*|PHPTGCALLS_BIN=$PHPTGCALLS_DIR|" \
  -e "s|LIVEPROTO_BIN=.*|LIVEPROTO_BIN=$LIVEPROTO_DIR|" \
  "$ROOT_DIR/.env"
rm -f "$ROOT_DIR/.env.bak"
info "Bin paths written to .env"

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}Installation complete!${NC}"
echo ""
echo "  Next step — edit .env and fill in your credentials:"
echo "    BOT_TOKEN   → from @BotFather"
echo "    API_ID      → from https://my.telegram.org/apps"
echo "    API_HASH    → from https://my.telegram.org/apps"
echo "    SESSION     → your MTProto session string"
echo "    API_KEY     → from https://deadlinetech.site or @smaugxd"
echo ""
echo "  Then start the bot:"
echo "    php bot.php"
echo ""
