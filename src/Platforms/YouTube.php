<?php
/**
 * src/Platforms/YouTube.php
 *
 * YouTube platform handler for TeleMusic Bot v1.0.0
 *
 * Download pipeline (mirrors tosu4/AnonXMusic/platforms/Youtube.py):
 *
 *   Stage 1 — API-1 job (arcmusic.fun)
 *     POST  /youtube/v2/download?api_key=&query=<video_id>&isVideo=true/false
 *     GET   /youtube/jobStatus?job_id=<id>   (poll until status=success)
 *     → Returns CDN URL → download file
 *
 *   Stage 2 — yt-dlp fallback (local)
 *     yt-dlp -x --audio-format opus -o <path> <url>
 *     (video: yt-dlp -f bestvideo+bestaudio -o <path> <url>)
 *
 * Track metadata is resolved first via YouTube Data API search or
 * youtube-search-python equivalent (youtubesearchphp/curlsearch).
 */

declare(strict_types=1);

namespace TeleMusic\Platforms;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TeleMusic\Core\Logger;

class YouTube
{
    private Client $http;
    private Logger $log;

    // API-1 constants (mirror tosu4's Python constants)
    private const V2_CREATE_RETRIES  = 3;
    private const V2_POLL_RETRIES    = 12;
    private const V2_POLL_SLEEP      = 3;    // seconds between polls
    private const CDN_RETRIES        = 5;
    private const CDN_RETRY_DELAY    = 2;
    private const DOWNLOAD_TIMEOUT   = 120;  // seconds
    private const CHUNK_SIZE         = 1048576; // 1 MB

    // Regex patterns
    private const YT_URL_REGEX = '/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';
    private const YT_ID_REGEX  = '/^[a-zA-Z0-9_-]{11}$/';

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
        $this->log  = Logger::getInstance();
        if (!is_dir(DOWNLOAD_DIR)) {
            mkdir(DOWNLOAD_DIR, 0755, true);
        }
    }

    // ── Public interface ─────────────────────────────────────────────────────

    /**
     * Resolve track metadata from a YouTube URL or search query.
     *
     * Returns:
     * [
     *   'title'        => string,
     *   'artist'       => string,
     *   'duration_min' => string,  // "3:45"
     *   'duration_sec' => int,
     *   'thumb'        => string,
     *   'vidid'        => string,  // 11-char video ID
     *   'link'         => string,  // https://youtu.be/<id>
     *   'platform'     => 'youtube',
     * ]
     */
    public function resolveTrack(string $input): ?array
    {
        $videoId = $this->extractVideoId($input);
        if ($videoId) {
            return $this->metadataFromId($videoId);
        }
        // Treat as search query
        return $this->search($input);
    }

    /**
     * Download audio for a video ID. Returns local file path or null on failure.
     *
     * @param string $videoId  11-char YouTube video ID
     * @param bool   $isVideo  Download video+audio instead of audio-only
     */
    public function download(string $videoId, bool $isVideo = false): ?string
    {
        $outPath = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $videoId . ($isVideo ? '.mp4' : '.opus');

        // Already cached
        if (file_exists($outPath) && filesize($outPath) > 0) {
            $this->log->info("[YouTube] Cache hit: $outPath");
            return $outPath;
        }

        // Stage 1: API-1
        if (API_URL && API_KEY) {
            $cdnUrl = $this->api1Download($videoId, $isVideo);
            if ($cdnUrl) {
                $path = $this->downloadFromCdn($cdnUrl, $outPath);
                if ($path) return $path;
            }
        }

        // Stage 2: yt-dlp fallback
        $this->log->info("[YouTube] Falling back to yt-dlp for $videoId");
        return $this->ytdlpDownload($videoId, $isVideo, $outPath);
    }

    // ── API-1: job-based download ─────────────────────────────────────────────

    private function api1Download(string $videoId, bool $isVideo): ?string
    {
        $jobId = $this->api1CreateJob($videoId, $isVideo);
        if (!$jobId) {
            $this->log->warning("[YouTube][API-1] Failed to create job for $videoId");
            return null;
        }
        return $this->api1PollJob($jobId);
    }

    private function api1CreateJob(string $videoId, bool $isVideo): ?string
    {
        $endpoint = rtrim(API_URL, '/') . '/youtube/v2/download';
        $params   = [
            'api_key' => API_KEY,
            'query'   => $videoId,
            'isVideo' => $isVideo ? 'true' : 'false',
        ];

        for ($attempt = 1; $attempt <= self::V2_CREATE_RETRIES; $attempt++) {
            try {
                $res  = $this->http->get($endpoint, ['query' => $params, 'timeout' => 15]);
                $data = json_decode((string) $res->getBody(), true);

                if (($data['status'] ?? '') !== 'queued') {
                    $this->log->warning("[YouTube][API-1] create_job status={$data['status']} (attempt $attempt)");
                    sleep(1);
                    continue;
                }
                $jobId = $data['job_id'] ?? null;
                if ($jobId) {
                    $this->log->info("[YouTube][API-1] Job created: $jobId");
                    return $jobId;
                }
            } catch (GuzzleException $e) {
                $this->log->error("[YouTube][API-1] create_job attempt $attempt: " . $e->getMessage());
                sleep(1);
            }
        }
        return null;
    }

    private function api1PollJob(string $jobId): ?string
    {
        $endpoint = rtrim(API_URL, '/') . '/youtube/jobStatus';

        for ($attempt = 1; $attempt <= self::V2_POLL_RETRIES; $attempt++) {
            try {
                $res  = $this->http->get($endpoint, [
                    'query'   => ['job_id' => $jobId],
                    'timeout' => 15,
                ]);
                $data = json_decode((string) $res->getBody(), true);

                $status = $data['status'] ?? '';
                if ($status === 'success') {
                    $url = $data['url'] ?? $data['download_url'] ?? null;
                    if ($url) {
                        $this->log->info("[YouTube][API-1] Job done: $url");
                        return $url;
                    }
                }
                if ($status === 'failed') {
                    $this->log->warning("[YouTube][API-1] Job failed: $jobId");
                    return null;
                }
                $this->log->debug("[YouTube][API-1] Poll attempt $attempt: status=$status");
                sleep(self::V2_POLL_SLEEP);
            } catch (GuzzleException $e) {
                $this->log->error("[YouTube][API-1] poll attempt $attempt: " . $e->getMessage());
                sleep(self::V2_POLL_SLEEP);
            }
        }
        $this->log->warning("[YouTube][API-1] Job timed out: $jobId");
        return null;
    }

    // ── CDN download ──────────────────────────────────────────────────────────

    private function downloadFromCdn(string $url, string $outPath): ?string
    {
        for ($attempt = 1; $attempt <= self::CDN_RETRIES; $attempt++) {
            try {
                $res = $this->http->get($url, [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'sink'    => $outPath,
                ]);
                if (file_exists($outPath) && filesize($outPath) > 0) {
                    $this->log->info("[YouTube][CDN] Downloaded: $outPath");
                    return $outPath;
                }
            } catch (GuzzleException $e) {
                $this->log->error("[YouTube][CDN] Attempt $attempt: " . $e->getMessage());
                if (file_exists($outPath)) unlink($outPath);
                sleep(self::CDN_RETRY_DELAY);
            }
        }
        return null;
    }

    // ── yt-dlp fallback ───────────────────────────────────────────────────────

    private function ytdlpDownload(string $videoId, bool $isVideo, string $outPath): ?string
    {
        $ytUrl  = "https://youtu.be/$videoId";
        $cookie = $this->cookieFile();

        if ($isVideo) {
            $format = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best';
            $cmd = sprintf(
                'yt-dlp -f %s --merge-output-format mp4 -o %s %s %s 2>&1',
                escapeshellarg($format),
                escapeshellarg($outPath),
                $cookie ? '--cookies ' . escapeshellarg($cookie) : '',
                escapeshellarg($ytUrl)
            );
        } else {
            $cmd = sprintf(
                'yt-dlp -x --audio-format opus --audio-quality 0 -o %s %s %s 2>&1',
                escapeshellarg($outPath),
                $cookie ? '--cookies ' . escapeshellarg($cookie) : '',
                escapeshellarg($ytUrl)
            );
        }

        exec($cmd, $output, $code);
        if ($code !== 0) {
            $this->log->error("[YouTube][yt-dlp] Failed (code $code): " . implode("\n", $output));
            return null;
        }
        if (file_exists($outPath) && filesize($outPath) > 0) {
            $this->log->info("[YouTube][yt-dlp] Downloaded: $outPath");
            return $outPath;
        }
        return null;
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    private function metadataFromId(string $videoId): ?array
    {
        // Try yt-dlp --dump-json for metadata (no download)
        $url = "https://youtu.be/$videoId";
        $cmd = sprintf('yt-dlp --dump-json --no-playlist %s 2>/dev/null', escapeshellarg($url));
        $json = shell_exec($cmd);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                return $this->normalizeMetadata($data);
            }
        }

        // Fallback: construct minimal metadata from ID
        return [
            'title'        => $videoId,
            'artist'       => '',
            'duration_min' => '0:00',
            'duration_sec' => 0,
            'thumb'        => "https://img.youtube.com/vi/$videoId/hqdefault.jpg",
            'vidid'        => $videoId,
            'link'         => "https://youtu.be/$videoId",
            'platform'     => 'youtube',
        ];
    }

    private function search(string $query): ?array
    {
        $cmd = sprintf(
            'yt-dlp "ytsearch1:%s" --dump-json --no-playlist 2>/dev/null',
            str_replace('"', '\\"', $query)
        );
        $json = shell_exec($cmd);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                return $this->normalizeMetadata($data);
            }
        }
        return null;
    }

    private function normalizeMetadata(array $data): array
    {
        $dur = (int) ($data['duration'] ?? 0);
        return [
            'title'        => $data['title'] ?? 'Unknown',
            'artist'       => $data['uploader'] ?? $data['channel'] ?? '',
            'duration_min' => $this->secondsToMin($dur),
            'duration_sec' => $dur,
            'thumb'        => $data['thumbnail'] ?? "https://img.youtube.com/vi/{$data['id']}/hqdefault.jpg",
            'vidid'        => $data['id'] ?? '',
            'link'         => $data['webpage_url'] ?? "https://youtu.be/{$data['id']}",
            'platform'     => 'youtube',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isValidUrl(string $input): bool
    {
        return (bool) preg_match(self::YT_URL_REGEX, $input);
    }

    private function extractVideoId(string $input): ?string
    {
        // Direct 11-char ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
            return $input;
        }
        if (preg_match(self::YT_URL_REGEX, $input, $m)) {
            return $m[1];
        }
        return null;
    }

    private function cookieFile(): ?string
    {
        if (!is_dir(COOKIES_DIR)) return null;
        $files = glob(COOKIES_DIR . '/*.txt');
        if (empty($files)) return null;
        return $files[array_rand($files)];
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
