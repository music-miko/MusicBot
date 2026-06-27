# TeleMusic Bot

Telegram Video Chat Music Streaming Bot written in PHP.  
Streams audio from YouTube and Spotify into Telegram group video chats.

---

## Requirements

| Requirement | Version |
|---|---|
| OS | **Ubuntu 24.04 LTS (Noble)** — strongly recommended |
| PHP | 8.4+ |
| Composer | 2.5+ |
| Rust | stable (auto-installed) |
| Clang | 14+ (auto-installed) |
| yt-dlp | latest |
| ffmpeg | any recent |

> **Ubuntu 22.04 is not supported.** The `phptgcalls` Rust extension
> (`ext-php-rs`) fails to compile against the PHP 8.4 headers shipped
> on Jammy. Use Ubuntu 24.04 or run in a Docker container.

---

## Quick install on Ubuntu 24.04

### 1. Install PHP 8.4

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4 php8.4-cli php8.4-common \
     php8.4-curl php8.4-mbstring php8.4-gmp \
     php8.4-xml php8.4-intl php8.4-dom
sudo update-alternatives --set php /usr/bin/php8.4
php -v   # should show PHP 8.4.x
```

### 2. Install Composer 2.5+

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer --version   # should show 2.5+
```

### 3. Clone and install the bot

```bash
git clone https://github.com/your-repo/MusicBot
cd MusicBot
bash install.sh
```

The installer will automatically:
- Verify PHP 8.4+ and required extensions
- Install PHP dependencies via Composer
- Install yt-dlp
- Install ffmpeg
- Install Rust (stable) if not present
- Install Clang if not present
- Clone and build phptgcalls (Rust extension, takes ~5 min)
- Clone and install LiveProto
- Create your `.env` from `.env.example`

### 4. Configure credentials

Edit `.env` and fill in:

```env
BOT_TOKEN=        # from @BotFather
API_ID=           # from https://my.telegram.org/apps
API_HASH=         # from https://my.telegram.org/apps
SESSION=          # MTProto string session for the assistant account
API_KEY=          # from https://deadlinetech.site or @smaugxd
```

### 5. Start the bot

```bash
php bot.php
```

---

## Docker (alternative — works on any OS)

If you're not on Ubuntu 24.04, use Docker:

```bash
docker run -it --rm \
  -v $(pwd):/app \
  -w /app \
  ubuntu:24.04 \
  bash -c "apt update -q && apt install -y git curl php8.4-cli && bash install.sh"
```

---

## Troubleshooting

### `ext-php-rs` compile errors
You are on Ubuntu 22.04 (Jammy). Upgrade to Ubuntu 24.04 or use Docker.

### `composer-plugin-api` version mismatch
Your Composer is too old. Install a fresh one:
```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

### `php8.4-zlib` / `php8.4-fileinfo` not found
These are bundled inside `php8.4-common` — do not install them separately.

### `php8.4-openssl` not found
OpenSSL is compiled into PHP itself — do not install it as a separate package.

### Deprecation notices from Composer
These come from the old system Composer at `/usr/bin/composer`. Make sure
`/usr/local/bin/composer` (the fresh install) takes priority in your PATH:
```bash
hash -r
which composer   # should show /usr/local/bin/composer
```

### `cargo: command not found` after Rust install
```bash
source "$HOME/.cargo/env"
```

---

## Project structure

```
MusicBot/
├── bot.php               # Entry point
├── composer.json
├── install.sh            # Full installer
├── .env.example          # Credentials template
├── config/
│   └── config.php        # All configuration constants
├── src/
│   ├── Commands/         # Bot command handlers
│   ├── Core/             # Bot, Logger, Queue, Telegram API, VideoCallManager
│   ├── Handlers/         # Message and callback handlers
│   └── Platforms/        # YouTube, Spotify, PlatformResolver
├── bin/
│   ├── phptgcalls/       # Rust-based Telegram calls library (auto-cloned)
│   └── liveproto/        # PHP MTProto client (auto-cloned)
├── downloads/            # Temporary audio files
├── cookies/              # yt-dlp cookies
└── logs/                 # Application logs
```
