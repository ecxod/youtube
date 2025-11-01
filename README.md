**Explanation of the workflow**

1. **Channel request** – Retrieves statistics, the channel’s creation date, and the ID of the “uploads” playlist that contains every video the channel has posted.  
2. **Playlist pagination** – The uploads playlist is read in pages of up to 50 items (the API limit). Each video’s ID, title, and publish date are stored.  
3. **Batch statistics** – Video view counts are fetched in batches of 50 IDs (the maximum allowed per `videos.list` call) and merged back into the upload array.

The returned array looks like:

```php
[
    'subscribers'  => 123456,
    'creationDate' => '2007-04-23T07:00:00Z',
    'totalViews'   => 987654321,
    'totalUploads' => 342,
    'uploads' => [
        [
            'videoId'     => 'a1b2c3d4e5',
            'title'       => 'First video',
            'publishedAt' => '2007-05-01T12:34:56Z',
            'viewCount'   => 12345,
        ],
        // …more videos
    ],
];
```
