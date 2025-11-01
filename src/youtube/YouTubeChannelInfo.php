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
        // -------------------------------------------------
        // 1️⃣ Channel meta + uploads‑playlist ID
        // -------------------------------------------------
        $url = self::API_BASE . '/channels?part=statistics,snippet,contentDetails&id=' .
            urlencode($channelId) . '&key=' . $this->apiKey;

        $data = $this->fetchJson($url);
        if(empty($data['items'][0]))
        {
            return null;
        }

        $item       = $data['items'][0];
        $stats      = $item['statistics'];
        $snippet    = $item['snippet'];
        $playlistId = $item['contentDetails']['relatedPlaylists']['uploads'];

        $result = [ 
            'subscribers'  => (int) ($stats['subscriberCount'] ?? 0),
            'creationDate' => $snippet['publishedAt'] ?? null,
            'totalViews'   => (int) ($stats['viewCount'] ?? 0),
            'totalUploads' => (int) ($stats['videoCount'] ?? 0),
            'uploads'      => [],   // filled later
        ];

        // -------------------------------------------------
        // 2️⃣ Pull every video from the uploads playlist
        // -------------------------------------------------
        $nextPageToken = '';
        $uploadsById   = [];

        do
        {
            $plUrl = self::API_BASE . '/playlistItems?part=snippet&playlistId=' .
                urlencode($playlistId) .
                '&maxResults=50' .
                ($nextPageToken ? '&pageToken=' . $nextPageToken : '') .
                '&key=' . $this->apiKey;

            $plData = $this->fetchJson($plUrl);
            foreach($plData['items'] as $v)
            {
                $vid               = $v['snippet']['resourceId']['videoId'];
                $uploadsById[ $vid ] = [ 
                    'videoId'      => $vid,
                    'title'        => $v['snippet']['title'],
                    'publishedAt'  => $v['snippet']['publishedAt'],
                    // placeholders for stats
                    'viewCount'    => 0,
                    'likeCount'    => 0,
                    'commentCount' => 0,
                ];
            }
            $nextPageToken = $plData['nextPageToken'] ?? '';
        } while($nextPageToken);

        // -------------------------------------------------
        // 3️⃣ Batch‑request statistics (max 50 IDs per call)
        // -------------------------------------------------
        $chunks = array_chunk(array_keys($uploadsById), 50);
        foreach($chunks as $chunk)
        {
            $ids = implode(',', $chunk);
            // request the statistics we need
            $statsUrl = self::API_BASE . '/videos?part=statistics&id=' .
                $ids . '&key=' . $this->apiKey;

            $statsData = $this->fetchJson($statsUrl);
            foreach($statsData['items'] as $item)
            {
                $vid = $item['id'];
                if(!isset($uploadsById[ $vid ]))
                {
                    continue; // safety check
                }
                $uploadsById[ $vid ]['viewCount']    = (int) ($item['statistics']['viewCount'] ?? 0);
                $uploadsById[ $vid ]['likeCount']    = (int) ($item['statistics']['likeCount'] ?? 0);
                $uploadsById[ $vid ]['commentCount'] = (int) ($item['statistics']['commentCount'] ?? 0);
                // dislikeCount is not returned by the API
            }
        }

        // -------------------------------------------------
        // 4️⃣ Return ordered list
        // -------------------------------------------------
        $result['uploads'] = array_values($uploadsById);
        return $result;
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
        if($response === false)
        {
            \Sentry\captureMessage('Failed to fetch data from YouTube API.');
            return false;
        }

        $data = json_decode($response, true);
        if(json_last_error() !== JSON_ERROR_NONE)
        {
            \Sentry\captureMessage('Invalid JSON response from YouTube API.');
            return false;
        }
        return $data;
    }
}
