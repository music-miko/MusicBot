# 🎵 TeleMusic Bot v1.0.0

PHP Telegram Video Chat music streaming bot — YouTube & Spotify.

Ported from [tosu4](https://github.com/damian-bots/tosu) (Python/Pyrogram) to PHP.

---

## Architecture

```
User → /play URL
         │
         ▼
  PlatformResolver
  ├── Spotify.php  ──→ API-2 (onegrab CDN + AES-CTR) → .ogg file
  │                └── Spotify Web API → yt_query → YouTube.php
  └── YouTube.php  ──→ API-1 (arcmusic job) → CDN → .opus/.mp4
                   └── yt-dlp fallback
         │
         ▼
  QueueManager (in-memory per chat)
         │
         ▼
  VideoCallManager ──→ phptgcalls subprocess (IPC via JSON stdin/stdout)
                             │
                         LiveProto (MTProto)
                             │
                       Telegram Group Call
```

---

## Requirements

- PHP 8.1+
- Extensions: `curl`, `json`, `openssl`, `mbstring`
- Composer
- `yt-dlp` in `$PATH` (fallback downloader)
- `ffmpeg` (for audio conversion)
- [phptgcalls](https://github.com/TakNone/phptgcalls) binary
- [LiveProto](https://github.com/TakNone/LiveProto) binary

---

## Installation

```bash
# 1. Clone and install PHP dependencies
git clone <your-repo>
cd MusicBot
composer install

# 2. Install yt-dlp
pip install yt-dlp
# or
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
chmod +x /usr/local/bin/yt-dlp

# 3. Build phptgcalls
git clone https://github.com/TakNone/phptgcalls
cd phptgcalls && composer install
cp -r . /path/to/MusicBot/bin/phptgcalls
cd ..

# 4. Build LiveProto
git clone https://github.com/TakNone/LiveProto
cd LiveProto && composer install
cp -r . /path/to/MusicBot/bin/liveproto
cd ..

# 5. Configure
cp .env.example .env
nano .env        # fill in BOT_TOKEN, API_ID, API_HASH, SESSION, API_KEY, etc.

# 6. Create required directories
mkdir -p downloads cookies logs bin

# 7. Start the bot
php bot.php
```

---

## Configuration

| Variable | Description | Required |
|---|---|---|
| `BOT_TOKEN` | From @BotFather | ✅ |
| `API_ID` | From my.telegram.org | ✅ |
| `API_HASH` | From my.telegram.org | ✅ |
| `SESSION` | String session for assistant account | ✅ |
| `API_URL` | API-1 URL (default: `https://api.arcmusic.fun`) | ✅ |
| `API_KEY` | API-1 key (from deadlinetech.site / @smaugxd) | ✅ |
| `API_URL2` | API-2 URL (default: `https://api.onegrab.fun`) | Optional |
| `API_KEY2` | API-2 key (Spotify CDN direct download) | Optional |
| `SPOTIFY_CLIENT_ID` | Spotify app credentials | Optional |
| `SPOTIFY_CLIENT_SECRET` | Spotify app credentials | Optional |
| `MONGO_URI` | MongoDB connection string | Optional |
| `LOGGER_ID` | Telegram channel ID for logs | Optional |

---

## Commands

| Command | Description |
|---|---|
| `/play <URL or query>` | Stream audio in voice chat |
| `/vplay <URL or query>` | Stream video in video chat |
| `/pause` | Pause playback |
| `/resume` | Resume playback |
| `/skip` | Skip to next track |
| `/stop` | Stop and leave voice chat |
| `/queue` | Show current queue |
| `/shuffle` | Shuffle queue |
| `/loop` | Toggle loop (off → track → queue) |
| `/seek 1:30` | Seek to position |
| `/volume 80` | Set volume (1–200) |
| `/ping` | Check bot latency |

---

## Supported URLs

- `https://youtube.com/watch?v=...`
- `https://youtu.be/...`
- `https://open.spotify.com/track/...`
- `https://open.spotify.com/playlist/...`
- `https://open.spotify.com/album/...`
- Plain search queries (e.g. `/play Bohemian Rhapsody`)

---

## Download Pipeline

### YouTube (API-1 → yt-dlp)

1. `POST /youtube/v2/download?api_key=&query=<id>&isVideo=false`  → `job_id`
2. `GET  /youtube/jobStatus?job_id=<id>` (poll until `status=success`) → CDN URL
3. Download from CDN to `downloads/<id>.opus`
4. Fallback: `yt-dlp -x --audio-format opus ...`

### Spotify (API-2 → Spotify API → YouTube search)

**Tier 1 — API-2 (direct CDN):**
1. `GET /api/get_url?url=<spotify_url>&api_key=<key>` → `{url, key, iv, ...}`
2. Download encrypted OGG from CDN
3. AES-128-CTR decrypt with `key` + `iv` (via PHP `openssl_decrypt`)

**Tier 2 — Spotify Web API (metadata only):**
1. Client credentials token from `accounts.spotify.com`
2. Fetch track metadata from `api.spotify.com/v1/tracks/<id>`
3. Build `yt_query = title + artist`, resolve via YouTube

**Tier 3 — URL slug search:**
- Last resort: extract search terms from Spotify URL path

---

## File Structure

```
MusicBot/
├── bot.php                    # Entry point
├── composer.json
├── .env.example
├── config/
│   └── config.php             # All configuration constants
├── src/
│   ├── Core/
│   │   ├── Bot.php            # Main polling loop
│   │   ├── TelegramApi.php    # Bot API wrapper
│   │   ├── VideoCallManager.php  # phptgcalls process manager
│   │   ├── QueueManager.php   # Per-chat track queue
│   │   └── Logger.php         # Monolog wrapper
│   ├── Platforms/
│   │   ├── YouTube.php        # YT metadata + API-1 + yt-dlp download
│   │   ├── Spotify.php        # API-2 + AES decrypt + Spotify API
│   │   └── PlatformResolver.php  # Routes input to correct platform
│   ├── Handlers/
│   │   ├── MessageHandler.php # Command routing
│   │   └── CallbackHandler.php  # Inline button routing
│   └── Commands/
│       ├── PlayCommand.php    # /play /vplay
│       ├── ControlCommands.php # pause/resume/skip/stop/shuffle/loop/seek/volume
│       ├── QueueCommand.php   # /queue
│       └── HelpCommand.php    # /help /start
├── downloads/                 # Downloaded audio/video files
├── cookies/                   # yt-dlp YouTube cookies
├── logs/                      # Bot logs
└── bin/                       # phptgcalls + liveproto binaries
```

---

## Roadmap (v2.0.0)

- [ ] Webhook mode (vs long-polling)
- [ ] Redis queue persistence
- [ ] More platforms (SoundCloud, Apple Music)
- [ ] Admin-only command restrictions
- [ ] Auto-leave when voice chat empty
- [ ] Thumbnail generation (GD/Imagick)
- [ ] `/speed` command
- [ ] Multi-language support

---

## License

MIT — See LICENSE
