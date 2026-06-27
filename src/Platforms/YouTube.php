<?php
/**
 * src/Platforms/YouTube.php
 *
 * YouTube platform handler for TeleMusic Bot.
 *
 * Metadata / search-by-ID (mirrors TgMusicBot's src/core/dl/youtube_search.go):
 *   1. If input is a video ID/URL → search YouTube for that exact ID first,
 *      and only accept a search result whose returned video ID matches.
 *   2. If no search result matches, fetch the title via YouTube's oEmbed
 *      endpoint and search again using that title (oEmbed never returns
 *      duration/thumbnail directly, so this is just a better search seed).
 *   3. If input is plain text → straight top-result search (no ID to match).
 *
 *   This mirrors TgMusicBot's getInfo(): try the ID directly, then retry
 *   the search using the oEmbed title as a second pass, before giving up.
 *
 * Download pipeline (mirrors tosu4/AnonXMusic/platforms/Youtube.py):
 *   Stage 1 — API-1 job queue (arcmusic.fun)
 *     GET  /youtube/v2/download?api_key=&query=<video_id>&isVideo=true/false
 *          → { status: "queued", job_id }
 *     GET  /youtube/jobStatus?job_id=<id>
 *          → { status: "success", job: { status: "done", result: { public_url } } }
 *     (poll until job.status == "done")
 *     → public_url may be a relative path (resolved against API_URL) or
 *       an absolute CDN URL — both are handled.
 *     → stream-download the resolved URL to disk.
 *
 *   Stage 2 — yt-dlp fallback (local)
 *     yt-dlp -x --audio-format opus -o <path> <url>
 *     (video: yt-dlp -f bestvideo+bestaudio -o <path> <url>)
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

    // API-1 job-queue constants
    private const V2_CREATE_RETRIES  = 3;
    private const V2_POLL_RETRIES    = 12;
    private const V2_POLL_SLEEP      = 3;    // seconds between polls
    private const CDN_RETRIES        = 5;
    private const CDN_RETRY_DELAY    = 2;
    private const DOWNLOAD_TIMEOUT   = 120;  // seconds
    private const CHUNK_SIZE         = 1048576; // 1 MB

    // Search constants
    private const SEARCH_RESULT_LIMIT = 10;
    private const SEARCH_TIMEOUT      = 7;

    // Regex patterns
    private const YT_URL_REGEX = '/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';
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
     * Resolve track metadata from a YouTube URL, video ID, or search query.
     *
     * Returns:
     * [
     *   'title'        => string,
     *   'artist'       => string,    // channel name
     *   'duration_min' => string,    // "3:45"
     *   'duration_sec' => int,
     *   'thumb'        => string,
     *   'vidid'        => string,    // 11-char video ID
     *   'link'         => string,    // https://youtube.com/watch?v=<id>
     *   'views'        => string,
     *   'platform'     => 'youtube',
     * ]
     */
    public function resolveTrack(string $input): ?array
    {
        $videoId = $this->extractVideoId($input);

        if ($videoId) {
            // Step 1: search for the ID itself (titles/URLs containing the ID
            // often surface the exact video as the top hit).
            foreach ([$videoId, $input] as $seed) {
                $results = $this->searchRaw($seed, self::SEARCH_RESULT_LIMIT);
                foreach ($results as $r) {
                    if ($r['vidid'] === $videoId) {
                        return $r;
                    }
                }
            }

            // Step 2: oEmbed title → search again, matching by ID.
            $title = $this->oEmbedTitle($videoId);
            if ($title) {
                $results = $this->searchRaw($title, self::SEARCH_RESULT_LIMIT);
                foreach ($results as $r) {
                    if ($r['vidid'] === $videoId) {
                        return $r;
                    }
                }
            }

            // Step 3: give up trying to match search results — build minimal
            // metadata directly from the ID so download can still proceed.
            $this->log->warning("[YouTube] Video ID extracted but no matching search result: $videoId");
            return [
                'title'        => $title ?: $videoId,
                'artist'       => '',
                'duration_min' => '0:00',
                'duration_sec' => 0,
                'thumb'        => "https://i.ytimg.com/vi/$videoId/hqdefault.jpg",
                'vidid'        => $videoId,
                'link'         => "https://www.youtube.com/watch?v=$videoId",
                'views'        => '',
                'platform'     => 'youtube',
            ];
        }

        // Plain search query — no ID to match against.
        $results = $this->searchRaw($input, 1);
        return $results[0] ?? null;
    }

    /**
     * Download audio (or video) for a video ID. Returns local file path or null on failure.
     *
     * @param string $videoId  11-char YouTube video ID
     * @param bool   $isVideo  Download video+audio instead of audio-only
     */
    public function download(string $videoId, bool $isVideo = false): ?string
    {
        $outPath = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $videoId . ($isVideo ? '.mp4' : '.m4a');

        // Already cached
        if (file_exists($outPath) && filesize($outPath) > 0) {
            $this->log->info("[YouTube] Cache hit: $outPath");
            return $outPath;
        }

        // Stage 1: API-1
        if (API_URL && API_KEY) {
            $path = $this->api1Download($videoId, $isVideo, $outPath);
            if ($path) return $path;
        }

        // Stage 2: yt-dlp fallback
        $this->log->info("[YouTube] Falling back to yt-dlp for $videoId");
        return $this->ytdlpDownload($videoId, $isVideo, $outPath);
    }

    // ── API-1: job-based download ─────────────────────────────────────────────

    private function api1Download(string $videoId, bool $isVideo, string $outPath): ?string
    {
        $jobId = $this->api1CreateJob($videoId, $isVideo);
        if (!$jobId) {
            $this->log->warning("[YouTube][API-1] Failed to create job for $videoId");
            return null;
        }

        $url = $this->api1PollJob($jobId);
        if (!$url) {
            return null;
        }

        return $this->downloadFromCdn($url, $outPath);
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
                sleep(1);
            } catch (GuzzleException $e) {
                $this->log->error("[YouTube][API-1] create_job attempt $attempt: " . $e->getMessage());
                sleep(1);
            }
        }
        return null;
    }

    /**
     * Poll /youtube/jobStatus until the job is done.
     *
     * Response shape:
     *   {
     *     "status": "success",
     *     "job": { "status": "done", "result": { "public_url": "/files/<id>.m4a" } }
     *   }
     *
     * public_url may be an absolute URL (CDN) or a path relative to API_URL.
     */
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

                if (($data['status'] ?? '') !== 'success') {
                    $this->log->debug("[YouTube][API-1] Poll attempt $attempt: status={$data['status']}");
                    sleep(self::V2_POLL_SLEEP);
                    continue;
                }

                $job       = $data['job'] ?? [];
                $jobStatus = $job['status'] ?? '';

                if ($jobStatus === 'failed' || $jobStatus === 'error') {
                    $this->log->warning("[YouTube][API-1] Job failed: $jobId");
                    return null;
                }

                if ($jobStatus !== 'done') {
                    $this->log->debug("[YouTube][API-1] Poll attempt $attempt: job_status=$jobStatus");
                    sleep(self::V2_POLL_SLEEP);
                    continue;
                }

                $publicUrl = $job['result']['public_url'] ?? null;
                if (!$publicUrl) {
                    $this->log->warning("[YouTube][API-1] Job done but no public_url: $jobId");
                    return null;
                }

                $fullUrl = str_starts_with($publicUrl, '/')
                    ? rtrim(API_URL, '/') . $publicUrl
                    : $publicUrl;

                $this->log->info("[YouTube][API-1] Job done: $fullUrl");
                return $fullUrl;

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
                $this->http->get($url, [
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
                'yt-dlp -f %s -o %s %s %s 2>&1',
                escapeshellarg('bestaudio[ext=m4a]/bestaudio'),
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

    // ── Metadata / search ─────────────────────────────────────────────────────

    /**
     * Scrape YouTube search results for $query, no API key required.
     * Returns an array of normalized track arrays (see resolveTrack docblock),
     * ordered best-match-first, up to $limit entries.
     */
    private function searchRaw(string $query, int $limit): array
    {
        $url = 'https://www.youtube.com/results?' . http_build_query(['search_query' => $query]);

        try {
            $res = $this->http->get($url, [
                'timeout' => self::SEARCH_TIMEOUT,
                'headers' => [
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]);
            $html = (string) $res->getBody();
        } catch (GuzzleException $e) {
            $this->log->error("[YouTube][Search] HTTP fetch for \"$query\": " . $e->getMessage());
            return [];
        }

        $results = $this->parseSearchJson($html, $limit);
        if (empty($results)) {
            $results = $this->parseSearchRegexFallback($html, $limit);
        }
        return $results;
    }

    /**
     * Parse ytInitialData JSON embedded in the search results page.
     */
    private function parseSearchJson(string $html, int $limit): array
    {
        if (!preg_match('/(?:var ytInitialData|window\["ytInitialData"\])\s*=\s*(\{)/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $start = $m[1][1];
        $json  = $this->extractBalancedJson($html, $start);
        if (!$json) return [];

        $data = json_decode($json, true);
        if (!is_array($data)) return [];

        $contents = $data['contents']['twoColumnSearchResultsRenderer']
            ['primaryContents']['sectionListRenderer']['contents'] ?? [];

        $results = [];
        $seen    = [];

        foreach ($contents as $section) {
            $items = $section['itemSectionRenderer']['contents'] ?? [];
            foreach ($items as $item) {
                if (count($results) >= $limit) break 2;

                $vr = $item['videoRenderer'] ?? null;
                if (!$vr) continue;

                $vidId = $vr['videoId'] ?? null;
                if (!$vidId || isset($seen[$vidId])) continue;
                $seen[$vidId] = true;

                // Skip live streams — no fixed duration / not downloadable the same way
                if ($this->isLiveNow($vr)) continue;

                $title = '';
                foreach ($vr['title']['runs'] ?? [] as $run) {
                    $title .= $run['text'] ?? '';
                }
                $durationText = $vr['lengthText']['simpleText'] ?? '';
                $thumbs       = $vr['thumbnail']['thumbnails'] ?? [];
                $thumb        = !empty($thumbs) ? strtok(end($thumbs)['url'] ?? '', '?') : "https://i.ytimg.com/vi/$vidId/hqdefault.jpg";
                $channel      = '';
                foreach ($vr['ownerText']['runs'] ?? [] as $run) {
                    $channel .= $run['text'] ?? '';
                }
                $views = $vr['viewCountText']['simpleText'] ?? '';

                if (!$title || !$durationText) continue;

                $durationSec = $this->parseDurationText($durationText);
                $results[] = [
                    'title'        => $title,
                    'artist'       => $channel,
                    'duration_min' => $this->secondsToMin($durationSec),
                    'duration_sec' => $durationSec,
                    'thumb'        => $thumb,
                    'vidid'        => $vidId,
                    'link'         => "https://www.youtube.com/watch?v=$vidId",
                    'views'        => $views,
                    'platform'     => 'youtube',
                ];
            }
        }
        return $results;
    }

    /**
     * Regex-only fallback when ytInitialData JSON can't be parsed:
     * just pull video IDs in order of appearance.
     */
    private function parseSearchRegexFallback(string $html, int $limit): array
    {
        preg_match_all('/"videoId":"([a-zA-Z0-9_-]{11})"/', $html, $m);
        $ids = array_values(array_unique($m[1] ?? []));
        $ids = array_slice($ids, 0, $limit);

        return array_map(fn($vidId) => [
            'title'        => 'Unknown Title',
            'artist'       => '',
            'duration_min' => '0:00',
            'duration_sec' => 0,
            'thumb'        => "https://i.ytimg.com/vi/$vidId/hqdefault.jpg",
            'vidid'        => $vidId,
            'link'         => "https://www.youtube.com/watch?v=$vidId",
            'views'        => '',
            'platform'     => 'youtube',
        ], $ids);
    }

    /**
     * Extract a balanced {...} JSON object starting at byte offset $start in $html.
     */
    private function extractBalancedJson(string $html, int $start): ?string
    {
        $depth  = 0;
        $inStr  = false;
        $escape = false;
        $len    = strlen($html);

        for ($i = $start; $i < $len; $i++) {
            $c = $html[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($c === '\\') {
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inStr = !$inStr;
                continue;
            }
            if (!$inStr) {
                if ($c === '{') $depth++;
                elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($html, $start, $i - $start + 1);
                    }
                }
            }
        }
        return null;
    }

    private function isLiveNow(array $videoRenderer): bool
    {
        foreach ($videoRenderer['badges'] ?? [] as $badge) {
            $style = $badge['metadataBadgeRenderer']['style'] ?? '';
            if ($style === 'BADGE_STYLE_TYPE_LIVE_NOW') {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch a video's title via YouTube's public oEmbed endpoint.
     * Used as a second-pass search seed when direct ID matching fails
     * (mirrors TgMusicBot's getYouTubeTitleFromOEmbed).
     */
    private function oEmbedTitle(string $videoId): ?string
    {
        try {
            $res = $this->http->get('https://www.youtube.com/oembed', [
                'query'   => ['url' => "https://www.youtube.com/watch?v=$videoId", 'format' => 'json'],
                'timeout' => 10,
            ]);
            $data = json_decode((string) $res->getBody(), true);
            $title = $data['title'] ?? null;
            return $title ?: null;
        } catch (GuzzleException $e) {
            $this->log->debug("[YouTube][oEmbed] $videoId: " . $e->getMessage());
            return null;
        }
    }

    private function parseDurationText(string $text): int
    {
        $parts = array_map('intval', explode(':', $text));
        $sec = 0;
        foreach ($parts as $p) {
            $sec = $sec * 60 + $p;
        }
        return $sec;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isValidUrl(string $input): bool
    {
        return (bool) preg_match(self::YT_URL_REGEX, $input);
    }

    private function extractVideoId(string $input): ?string
    {
        $input = trim($input);
        // Direct 11-char ID
        if (preg_match(self::YT_ID_REGEX, $input)) {
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
