<?php
/**
 * src/Platforms/PlatformResolver.php
 *
 * Detects which platform a URL/query belongs to (YouTube or Spotify)
 * and routes to the correct platform handler. Returns a unified track array.
 *
 * For Spotify tracks with no direct file, automatically resolves audio
 * via YouTube search (mirrors tosu4's stream.py behavior).
 */

declare(strict_types=1);

namespace TeleMusic\Platforms;

use TeleMusic\Core\Logger;

class PlatformResolver
{
    private YouTube $youtube;
    private Spotify $spotify;
    private Logger  $log;

    public function __construct()
    {
        $this->youtube = new YouTube();
        $this->spotify = new Spotify();
        $this->log     = Logger::getInstance();
    }

    /**
     * Resolve a user's input (URL or search query) to a list of tracks ready to stream.
     *
     * Single track → array with one element.
     * Playlist/album → array with multiple elements.
     *
     * Each element:
     * [
     *   'title', 'artist', 'duration_min', 'duration_sec',
     *   'thumb', 'vidid', 'link', 'file', 'platform',
     *   'is_video' => false (audio default)
     * ]
     */
    public function resolve(string $input, bool $isVideo = false): array
    {
        $input = trim($input);

        // ── Spotify ───────────────────────────────────────────────────────────
        if ($this->spotify->isValidUrl($input)) {
            return $this->resolveSpotify($input, $isVideo);
        }

        // ── YouTube URL or search query ───────────────────────────────────────
        return $this->resolveYouTube($input, $isVideo);
    }

    // ── Spotify resolution ────────────────────────────────────────────────────

    private function resolveSpotify(string $url, bool $isVideo): array
    {
        $this->log->info("[Resolver] Spotify URL: $url");

        if ($this->spotify->isPlaylist($url)) {
            $tracks = $this->spotify->resolvePlaylist($url);
            if (empty($tracks)) {
                $this->log->warning("[Resolver] Spotify playlist empty: $url");
                return [];
            }
            return array_map(fn($t) => $this->ensureFile($t, $isVideo), $tracks);
        }

        // Single track
        $track = $this->spotify->resolveTrack($url);
        if (!$track) {
            $this->log->warning("[Resolver] Spotify track failed: $url");
            return [];
        }
        return [$this->ensureFile($track, $isVideo)];
    }

    // ── YouTube resolution ────────────────────────────────────────────────────

    private function resolveYouTube(string $input, bool $isVideo): array
    {
        $this->log->info("[Resolver] YouTube: $input");

        $meta = $this->youtube->resolveTrack($input);
        if (!$meta) {
            $this->log->warning("[Resolver] YouTube metadata failed: $input");
            return [];
        }

        $file = $this->youtube->download($meta['vidid'], $isVideo);
        if (!$file) {
            $this->log->warning("[Resolver] YouTube download failed: {$meta['vidid']}");
            return [];
        }

        $meta['file']     = $file;
        $meta['is_video'] = $isVideo;
        return [$meta];
    }

    // ── Ensure file (resolve Spotify via YouTube if needed) ───────────────────

    /**
     * If track has no local file (Spotify Tier 2/3 fallback), download via YouTube.
     */
    private function ensureFile(array $track, bool $isVideo): array
    {
        $track['is_video'] = $isVideo;

        if (!empty($track['file']) && file_exists($track['file'])) {
            return $track;
        }

        // Spotify without direct file → search YouTube
        $query = $track['yt_query'] ?? ($track['title'] . ' ' . $track['artist']);
        $this->log->info("[Resolver] Spotify→YouTube search: $query");

        $ytMeta = $this->youtube->resolveTrack($query);
        if (!$ytMeta) {
            $this->log->warning("[Resolver] YouTube search failed for: $query");
            $track['file'] = null;
            return $track;
        }

        $file = $this->youtube->download($ytMeta['vidid'], $isVideo);
        $track['file']   = $file;
        $track['vidid']  = $track['vidid'] ?: $ytMeta['vidid'];
        $track['thumb']  = $track['thumb']  ?: $ytMeta['thumb'];

        return $track;
    }
}
