<?php
declare(strict_types=1);

namespace Ecxod\YouTube;

/**
 * YouTubeChannelInfo
 *
 * Simple wrapper around the YouTube Data API v3.
 * Requires a valid API key with access to the YouTube Data API.
 *
 * Usage:
 *   $yt = new YouTubeChannelInfo('YOUR_API_KEY');
 *   $info = $yt->getChannelInfo('CHANNEL_ID');
 *
 *   // $info contains:
 *   //   - subscribers
 *   //   - creationDate
 *   //   - totalViews
 *   //   - totalUploads
 *   //   - uploads (array of ['videoId','title','publishedAt','viewCount'])
 */
class YouTubeChannelInfo
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get full channel information.
     *
     * @param string $channelId YouTube channel ID (e.g. UC_x5XG1OV2P6uZZ5FSM9Ttw)
     * @return array|null
     */
    public function getChannelInfo(string $channelId): ?array
    {
        // 1️⃣ Get basic channel stats + uploads playlist ID
        $channelUrl = self::API_BASE . '/channels?part=statistics,snippet,contentDetails&id=' .
            urlencode($channelId) . '&key=' . $this->apiKey;

        $channelData = $this->fetchJson($channelUrl);
        if (empty($channelData['items'][0] ?? null)) {
            return null; // invalid channel
        }

        $item = $channelData['items'][0];
        $stats = $item['statistics'];
        $snippet = $item['snippet'];
        $uploadsPlaylistId = $item['contentDetails']['relatedPlaylists']['uploads'];

        $channelInfo = [
            'subscribers'   => (int)($stats['subscriberCount'] ?? 0),
            'creationDate'  => $snippet['publishedAt'] ?? null,
            'totalViews'    => (int)($stats['viewCount'] ?? 0),
            'totalUploads'  => (int)($stats['videoCount'] ?? 0),
            'uploads'       => [], // will be filled below
        ];

        // 2️⃣ Retrieve all videos from the uploads playlist (paged)
        $nextPageToken = '';
        do {
            $playlistUrl = self::API_BASE . '/playlistItems?part=snippet&playlistId=' .
                urlencode($uploadsPlaylistId) .
                '&maxResults=50' .
                ($nextPageToken ? '&pageToken=' . $nextPageToken : '') .
                '&key=' . $this->apiKey;

            $playlistData = $this->fetchJson($playlistUrl);
            foreach ($playlistData['items'] as $video) {
                $videoId = $video['snippet']['resourceId']['videoId'];
                $channelInfo['uploads'][] = [
                    'videoId'     => $videoId,
                    'title'       => $video['snippet']['title'],
                    'publishedAt' => $video['snippet']['publishedAt'],
                    // viewCount will be added later
                ];
            }
            $nextPageToken = $playlistData['nextPageToken'] ?? '';
        } while ($nextPageToken);

        // 3️⃣ Batch‑request video statistics (max 50 IDs per request)
        $uploads = &$channelInfo['uploads'];
        $chunks = array_chunk($uploads, 50);
        foreach ($chunks as $chunk) {
            $ids = array_map(fn($v) => $v['videoId'], $chunk);
            $statsUrl = self::API_BASE . '/videos?part=statistics&id=' .
                implode(',', $ids) .
                '&key=' . $this->apiKey;

            $statsData = $this->fetchJson($statsUrl);
            foreach ($statsData['items'] as $statItem) {
                $vid = $statItem['id'];
                $viewCount = (int)($statItem['statistics']['viewCount'] ?? 0);
                // locate the matching upload entry
                foreach ($chunk as &$u) {
                    if ($u['videoId'] === $vid) {
                        $u['viewCount'] = $viewCount;
                        break;
                    }
                }
                unset($u);
            }
        }

        return $channelInfo;
    }

    /**
     * Helper: fetch JSON from a URL and decode it.
     *
     * @param string $url
     * @return array|bool
     */
    private function fetchJson(string $url): array|bool
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'header'  => "Accept: application/json\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            \Sentry\captureMessage('Failed to fetch data from YouTube API.');
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Sentry\captureMessage('Invalid JSON response from YouTube API.');
            return false;
        }
        return $data;
    }
}
