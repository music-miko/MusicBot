<?php
/**
 * src/Platforms/Spotify.php
 *
 * Spotify platform handler for TeleMusic Bot v1.0.0
 *
 * Three-tier resolution (mirrors tosu4/AnonXMusic/platforms/Spotify.py):
 *
 *   Tier 1 — API-2 (onegrab.fun / API_URL2 + API_KEY2)
 *     GET /api/get_url?url=<spotify_url>&api_key=<key>
 *     → returns { url, key, iv, title, artist, duration, thumbnail }
 *     → download encrypted OGG from CDN URL
 *     → AES-128-CTR decrypt with key+iv
 *     → save as .ogg
 *
 *   Tier 2 — Spotify Web API (client credentials)
 *     GET https://accounts.spotify.com/api/token (client_credentials)
 *     GET https://api.spotify.com/v1/tracks/<id>
 *     → metadata only; hands off to YouTube for audio
 *
 *   Tier 3 — URL-slug YouTube search
 *     Derives search query from Spotify URL slug, hands off to YouTube.
 *
 * For playlists/albums: returns array of track metadata.
 * stream.php resolves each via YouTube search if no direct CDN URL.
 */

declare(strict_types=1);

namespace TeleMusic\Platforms;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TeleMusic\Core\Logger;

class Spotify
{
    private Client $http;
    private Logger $log;
    private ?string $spotifyToken = null;
    private int $tokenExpiry = 0;

    private const REGEX = '#^(https?://)?([a-z0-9-]+\.)*spotify\.com/(track|playlist|album|artist)/[a-zA-Z0-9]+(\?.*)?$#i';
    private const CDN_RETRIES     = 5;
    private const CDN_RETRY_DELAY = 2;
    private const DOWNLOAD_TIMEOUT = 120;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
        $this->log  = Logger::getInstance();
        if (!is_dir(DOWNLOAD_DIR)) {
            mkdir(DOWNLOAD_DIR, 0755, true);
        }
    }

    // ── Validity ──────────────────────────────────────────────────────────────

    public function isValidUrl(string $url): bool
    {
        return (bool) preg_match(self::REGEX, trim($url));
    }

    // ── Main entry points ─────────────────────────────────────────────────────

    /**
     * Resolve a Spotify track URL and return metadata + downloaded file.
     *
     * Returns:
     * [
     *   'title'        => string,
     *   'artist'       => string,
     *   'duration_min' => string,
     *   'duration_sec' => int,
     *   'thumb'        => string,
     *   'vidid'        => string,   // Spotify track ID
     *   'link'         => string,   // original Spotify URL
     *   'file'         => ?string,  // local file path (or null if not direct download)
     *   'yt_query'     => ?string,  // YouTube search query (if no direct file)
     *   'platform'     => 'spotify',
     * ]
     */
    public function resolveTrack(string $url): ?array
    {
        // Tier 1: API-2
        if (API_URL2 && API_KEY2) {
            $result = $this->api2GetTrack($url);
            if ($result) return $result;
        }

        // Tier 2: Spotify Web API (metadata only)
        $metadata = $this->spotifyApiTrack($url);
        if ($metadata) {
            // No direct file; caller must use YouTube to get audio
            $metadata['file']     = null;
            $metadata['yt_query'] = $metadata['title'] . ' ' . $metadata['artist'];
            return $metadata;
        }

        // Tier 3: slug search
        return $this->slugSearch($url);
    }

    /**
     * Resolve a Spotify playlist/album/artist URL.
     * Returns array of track metadata arrays (same shape as resolveTrack).
     */
    public function resolvePlaylist(string $url): array
    {
        // Tier 1: API-2 playlist endpoint
        if (API_URL2 && API_KEY2) {
            $tracks = $this->api2GetPlaylist($url);
            if (!empty($tracks)) return $tracks;
        }

        // Tier 2: Spotify Web API
        return $this->spotifyApiPlaylist($url);
    }

    // ── Tier 1: API-2 ─────────────────────────────────────────────────────────

    private function api2GetTrack(string $url): ?array
    {
        $endpoint = rtrim(API_URL2, '/') . '/api/get_url';
        try {
            $res  = $this->http->get($endpoint, [
                'query'   => ['url' => $url, 'api_key' => API_KEY2],
                'timeout' => 20,
            ]);
            $data = json_decode((string) $res->getBody(), true);

            if (!isset($data['url'])) {
                $this->log->debug("[Spotify][API-2] No URL in response");
                return null;
            }

            $cdnUrl    = $data['url'];
            $aesKey    = $data['key']       ?? null;
            $aesIv     = $data['iv']        ?? null;
            $trackId   = $this->extractId($url);
            $outPath   = DOWNLOAD_DIR . '/' . ($trackId ?: md5($url)) . '.ogg';

            $filePath = $this->downloadAndDecrypt($cdnUrl, $aesKey, $aesIv, $outPath);

            $dur = (int) ($data['duration'] ?? 0);
            return [
                'title'        => $data['title']     ?? 'Unknown',
                'artist'       => $data['artist']    ?? '',
                'duration_min' => $this->secondsToMin($dur),
                'duration_sec' => $dur,
                'thumb'        => $data['thumbnail'] ?? '',
                'vidid'        => $trackId ?? '',
                'link'         => $url,
                'file'         => $filePath,
                'yt_query'     => null,
                'platform'     => 'spotify',
                '_source'      => 'api2',
            ];
        } catch (GuzzleException $e) {
            $this->log->warning("[Spotify][API-2] track error: " . $e->getMessage());
            return null;
        }
    }

    private function api2GetPlaylist(string $url): array
    {
        $endpoint = rtrim(API_URL2, '/') . '/api/get_playlist';
        try {
            $res  = $this->http->get($endpoint, [
                'query'   => ['url' => $url, 'api_key' => API_KEY2],
                'timeout' => 30,
            ]);
            $data = json_decode((string) $res->getBody(), true);
            $tracks = $data['tracks'] ?? [];
            if (empty($tracks)) return [];

            $result = [];
            $limit  = min(count($tracks), PLAYLIST_FETCH_LIMIT);
            for ($i = 0; $i < $limit; $i++) {
                $t   = $tracks[$i];
                $dur = (int) ($t['duration'] ?? 0);
                $result[] = [
                    'title'        => $t['title']     ?? 'Unknown',
                    'artist'       => $t['artist']    ?? '',
                    'duration_min' => $this->secondsToMin($dur),
                    'duration_sec' => $dur,
                    'thumb'        => $t['thumbnail'] ?? '',
                    'vidid'        => $t['id']        ?? '',
                    'link'         => $t['url']       ?? '',
                    'file'         => null,
                    'yt_query'     => ($t['title'] ?? '') . ' ' . ($t['artist'] ?? ''),
                    'platform'     => 'spotify',
                    '_source'      => 'api2_playlist',
                ];
            }
            return $result;
        } catch (GuzzleException $e) {
            $this->log->warning("[Spotify][API-2] playlist error: " . $e->getMessage());
            return [];
        }
    }

    // ── AES-128-CTR decryption (mirrors spotify_dl.go / Spotify.py) ──────────

    /**
     * Download from CDN and decrypt with AES-128-CTR if key+iv provided.
     * Returns local file path or null.
     */
    private function downloadAndDecrypt(string $url, ?string $hexKey, ?string $hexIv, string $outPath): ?string
    {
        // Download to temp file first
        $tmpPath = $outPath . '.enc';
        $downloaded = false;

        for ($attempt = 1; $attempt <= self::CDN_RETRIES; $attempt++) {
            try {
                $this->http->get($url, [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'sink'    => $tmpPath,
                ]);
                if (file_exists($tmpPath) && filesize($tmpPath) > 0) {
                    $downloaded = true;
                    break;
                }
            } catch (GuzzleException $e) {
                $this->log->error("[Spotify][CDN] Attempt $attempt: " . $e->getMessage());
                if (file_exists($tmpPath)) unlink($tmpPath);
                sleep(self::CDN_RETRY_DELAY);
            }
        }

        if (!$downloaded) {
            $this->log->error("[Spotify][CDN] Download failed after retries: $url");
            return null;
        }

        // No key = already plaintext OGG
        if (!$hexKey || !$hexIv) {
            rename($tmpPath, $outPath);
            return $outPath;
        }

        // AES-128-CTR decrypt
        $decrypted = $this->aesCtrDecrypt($tmpPath, $hexKey, $hexIv, $outPath);
        @unlink($tmpPath);

        if ($decrypted) {
            $this->log->info("[Spotify] Decrypted: $outPath");
            return $outPath;
        }
        return null;
    }

    /**
     * AES-128-CTR decrypt.
     *
     * Spotify uses AES-128-CTR with:
     *   key = 16-byte key (hex-encoded in API response)
     *   iv  = 16-byte nonce/counter (hex-encoded)
     *
     * PHP's openssl_decrypt with 'aes-128-ctr' handles this directly.
     */
    private function aesCtrDecrypt(string $inPath, string $hexKey, string $hexIv, string $outPath): bool
    {
        $key = hex2bin($hexKey);
        $iv  = hex2bin($hexIv);

        if (strlen($key) !== 16 || strlen($iv) !== 16) {
            $this->log->error("[Spotify][AES] Invalid key/iv lengths");
            return false;
        }

        $ciphertext = file_get_contents($inPath);
        if ($ciphertext === false) {
            $this->log->error("[Spotify][AES] Cannot read encrypted file: $inPath");
            return false;
        }

        // openssl_decrypt expects non-raw ciphertext with OPENSSL_RAW_DATA flag
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-128-ctr',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            $this->log->error("[Spotify][AES] Decryption failed: " . openssl_error_string());
            return false;
        }

        return file_put_contents($outPath, $plaintext) !== false;
    }

    // ── Tier 2: Spotify Web API ───────────────────────────────────────────────

    private function spotifyApiTrack(string $url): ?array
    {
        $token = $this->getSpotifyToken();
        if (!$token) return null;

        $trackId = $this->extractId($url);
        if (!$trackId) return null;

        try {
            $res  = $this->http->get("https://api.spotify.com/v1/tracks/$trackId", [
                'headers' => ['Authorization' => "Bearer $token"],
                'timeout' => 10,
            ]);
            $t = json_decode((string) $res->getBody(), true);
            return $this->normalizeSpotifyTrack($t);
        } catch (GuzzleException $e) {
            $this->log->warning("[Spotify][API] track $trackId: " . $e->getMessage());
            return null;
        }
    }

    private function spotifyApiPlaylist(string $url): array
    {
        $token = $this->getSpotifyToken();
        if (!$token) return [];

        $id   = $this->extractId($url);
        $type = $this->extractType($url);
        if (!$id || !$type) return [];

        try {
            if ($type === 'playlist') {
                return $this->fetchPlaylistTracks($token, $id);
            } elseif ($type === 'album') {
                return $this->fetchAlbumTracks($token, $id);
            } elseif ($type === 'artist') {
                return $this->fetchArtistTopTracks($token, $id);
            }
        } catch (GuzzleException $e) {
            $this->log->warning("[Spotify][API] $type $id: " . $e->getMessage());
        }
        return [];
    }

    private function fetchPlaylistTracks(string $token, string $playlistId): array
    {
        $res  = $this->http->get("https://api.spotify.com/v1/playlists/$playlistId/tracks", [
            'headers' => ['Authorization' => "Bearer $token"],
            'query'   => ['limit' => PLAYLIST_FETCH_LIMIT],
            'timeout' => 15,
        ]);
        $data  = json_decode((string) $res->getBody(), true);
        $items = $data['items'] ?? [];

        return array_filter(array_map(function ($item) {
            $t = $item['track'] ?? null;
            return $t ? $this->normalizeSpotifyTrack($t) : null;
        }, $items));
    }

    private function fetchAlbumTracks(string $token, string $albumId): array
    {
        // Get album thumbnail
        $albumRes = $this->http->get("https://api.spotify.com/v1/albums/$albumId", [
            'headers' => ['Authorization' => "Bearer $token"],
            'timeout' => 10,
        ]);
        $album     = json_decode((string) $albumRes->getBody(), true);
        $albumThumb = ($album['images'][0]['url'] ?? '');

        $res  = $this->http->get("https://api.spotify.com/v1/albums/$albumId/tracks", [
            'headers' => ['Authorization' => "Bearer $token"],
            'query'   => ['limit' => PLAYLIST_FETCH_LIMIT],
            'timeout' => 15,
        ]);
        $data  = json_decode((string) $res->getBody(), true);
        $items = $data['items'] ?? [];

        return array_map(function ($t) use ($albumThumb) {
            $meta = $this->normalizeSpotifyTrack($t);
            if (empty($meta['thumb'])) $meta['thumb'] = $albumThumb;
            return $meta;
        }, $items);
    }

    private function fetchArtistTopTracks(string $token, string $artistId): array
    {
        $res  = $this->http->get("https://api.spotify.com/v1/artists/$artistId/top-tracks", [
            'headers' => ['Authorization' => "Bearer $token"],
            'query'   => ['market' => 'US'],
            'timeout' => 10,
        ]);
        $data   = json_decode((string) $res->getBody(), true);
        $tracks = $data['tracks'] ?? [];
        return array_map(fn($t) => $this->normalizeSpotifyTrack($t), $tracks);
    }

    private function normalizeSpotifyTrack(array $t): array
    {
        $title   = $t['name'] ?? 'Unknown';
        $artists = implode(', ', array_column($t['artists'] ?? [], 'name'));
        $dur     = (int) (($t['duration_ms'] ?? 0) / 1000);
        $images  = $t['album']['images'] ?? $t['images'] ?? [];
        $thumb   = $images[0]['url'] ?? '';
        $trackId = $t['id'] ?? '';
        $link    = $t['external_urls']['spotify'] ?? '';

        return [
            'title'        => $artists ? "$title $artists" : $title,
            'clean_title'  => $title,
            'artist'       => $artists,
            'duration_min' => $this->secondsToMin($dur),
            'duration_sec' => $dur,
            'thumb'        => $thumb,
            'vidid'        => $trackId,
            'link'         => $link,
            'file'         => null,
            'yt_query'     => "$title $artists",
            'platform'     => 'spotify',
            '_source'      => 'spotify_api',
        ];
    }

    // ── Tier 3: URL-slug search ───────────────────────────────────────────────

    private function slugSearch(string $url): ?array
    {
        // e.g. https://open.spotify.com/track/something-title-1234567
        $slug = basename(parse_url($url, PHP_URL_PATH) ?? '');
        $slug = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $slug);
        $query = trim($slug);
        if (!$query) return null;

        $this->log->info("[Spotify][Tier3] Slug search: $query");
        return [
            'title'        => $query,
            'artist'       => '',
            'duration_min' => '0:00',
            'duration_sec' => 0,
            'thumb'        => '',
            'vidid'        => '',
            'link'         => $url,
            'file'         => null,
            'yt_query'     => $query,
            'platform'     => 'spotify',
            '_source'      => 'slug',
        ];
    }

    // ── Spotify OAuth ─────────────────────────────────────────────────────────

    private function getSpotifyToken(): ?string
    {
        if ($this->spotifyToken && time() < $this->tokenExpiry) {
            return $this->spotifyToken;
        }
        if (!SPOTIFY_CLIENT_ID || !SPOTIFY_CLIENT_SECRET) {
            return null;
        }
        try {
            $res  = $this->http->post('https://accounts.spotify.com/api/token', [
                'auth'        => [SPOTIFY_CLIENT_ID, SPOTIFY_CLIENT_SECRET],
                'form_params' => ['grant_type' => 'client_credentials'],
                'timeout'     => 10,
            ]);
            $data = json_decode((string) $res->getBody(), true);
            $this->spotifyToken = $data['access_token'] ?? null;
            $this->tokenExpiry  = time() + (int) ($data['expires_in'] ?? 3600) - 60;
            return $this->spotifyToken;
        } catch (GuzzleException $e) {
            $this->log->error("[Spotify] Token fetch failed: " . $e->getMessage());
            return null;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $parts = explode('/', trim($path, '/'));
        $last  = end($parts);
        return $last ? strtok($last, '?') : null;
    }

    private function extractType(string $url): ?string
    {
        if (preg_match('#/(track|playlist|album|artist)/#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public function isPlaylist(string $url): bool
    {
        return (bool) preg_match('#/(playlist|album|artist)/#', $url);
    }

    public function secondsToMin(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}
