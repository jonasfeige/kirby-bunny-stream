<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kirby\Cms\App;
use Exception;

class BunnyStreamClient
{
    private Client $http;
    private string $apiKey;
    private string $libraryId;
    private ?string $cdnHostname = null;

    private static ?self $instance = null;

    public function __construct(string $apiKey, string $libraryId)
    {
        $this->apiKey = $apiKey;
        $this->libraryId = $libraryId;
        $this->http = new Client([
            'base_uri' => 'https://video.bunnycdn.com/',
            'headers' => [
                'AccessKey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $apiKey = App::instance()->option('jonasfeige.kirby-bunny-stream.apiKey');
            $libraryId = App::instance()->option('jonasfeige.kirby-bunny-stream.libraryId');
            $cdnHostname = App::instance()->option('jonasfeige.kirby-bunny-stream.cdnHostname');

            if (!$apiKey || !$libraryId || !$cdnHostname) {
                throw new Exception('kirby-bunny-stream: apiKey, libraryId, and cdnHostname are required');
            }

            $instance = new self($apiKey, $libraryId);
            $instance->cdnHostname = $cdnHostname;
            self::$instance = $instance;
        }

        return self::$instance;
    }

    /**
     * Build a CDN URL for a video asset
     */
    public function cdnUrl(string $path): string
    {
        return "https://{$this->getCdnHostname()}{$path}";
    }

    public function getLibraryId(): string
    {
        return $this->libraryId;
    }

    public function getCdnHostname(): string
    {
        if ($this->cdnHostname === null) {
            throw new Exception('CDN hostname not configured');
        }
        return $this->cdnHostname;
    }

    public function createVideo(string $title, ?string $collectionId = null): array
    {
        try {
            $payload = ['title' => $title];

            if ($collectionId) {
                $payload['collectionId'] = $collectionId;
            }

            $response = $this->http->post(
                "library/{$this->libraryId}/videos",
                ['json' => $payload]
            );

            return json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new Exception('Failed to create video on Bunny: ' . $e->getMessage());
        }
    }

    public function uploadVideoContent(string $videoId, string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception("Video file not found: {$filePath}");
        }

        $fileHandle = fopen($filePath, 'r');
        if ($fileHandle === false) {
            throw new Exception("Could not open video file: {$filePath}");
        }

        try {
            $this->http->put(
                "library/{$this->libraryId}/videos/{$videoId}",
                [
                    'headers' => [
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $fileHandle,
                ]
            );
        } catch (GuzzleException $e) {
            throw new Exception('Failed to upload video content to Bunny: ' . $e->getMessage());
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }

    public function upload(string $filePath, string $title, ?string $collectionId = null): array
    {
        // Delete any existing video with same title in this collection to prevent duplicates
        $this->deleteExistingByTitle($title, $collectionId);

        $video = $this->createVideo($title, $collectionId);

        if (!isset($video['guid'])) {
            throw new Exception('Invalid video response: missing guid field');
        }

        $videoId = $video['guid'];

        $this->uploadVideoContent($videoId, $filePath);

        return $video;
    }

    /**
     * Check if a video with the given title exists in the collection.
     * Lists all videos in the collection (search index may be delayed for new uploads).
     *
     * @return array|null The existing video data, or null if not found
     */
    public function findVideoByTitle(string $title, ?string $collectionId): ?array
    {
        if (!$collectionId) {
            return null;
        }

        try {
            // List all videos in the collection (don't use search - index may be delayed)
            $response = $this->http->get("library/{$this->libraryId}/videos", [
                'query' => ['itemsPerPage' => 1000, 'collection' => $collectionId],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

            // Normalize the search title (Kirby sanitizes filenames)
            $normalizedTitle = \Kirby\Toolkit\F::safeName($title);

            foreach ($data['items'] ?? [] as $video) {
                // Normalize the Bunny title for comparison
                $normalizedVideoTitle = \Kirby\Toolkit\F::safeName($video['title']);
                if ($normalizedVideoTitle === $normalizedTitle) {
                    return $video;
                }
            }
        } catch (\Exception $e) {
            // If API check fails, allow upload to proceed (fail open)
            // This prevents brief API outages from blocking all uploads
        }

        return null;
    }

    /**
     * Delete any existing video with the same title in the collection.
     * Prevents duplicates when re-uploading after a failed attempt.
     * Used by upload() for server-side uploads only.
     */
    private function deleteExistingByTitle(string $title, ?string $collectionId): void
    {
        $existing = $this->findVideoByTitle($title, $collectionId);
        if ($existing) {
            $this->delete($existing['guid']);
        }
    }

    public function getVideo(string $videoId): array
    {
        try {
            $response = $this->http->get("library/{$this->libraryId}/videos/{$videoId}");
            return json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get video from Bunny: ' . $e->getMessage());
        }
    }

    /**
     * Update video metadata on Bunny.
     */
    public function updateVideo(string $videoId, array $data): array
    {
        try {
            $response = $this->http->post(
                "library/{$this->libraryId}/videos/{$videoId}",
                ['json' => $data]
            );
            return json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new Exception('Failed to update video on Bunny: ' . $e->getMessage());
        }
    }

    public function delete(string $videoId): bool
    {
        try {
            $this->http->delete("library/{$this->libraryId}/videos/{$videoId}");
            return true;
        } catch (GuzzleException $e) {
            throw new Exception('Failed to delete video from Bunny: ' . $e->getMessage());
        }
    }

    public function getOrCreateCollection(string $name): string
    {
        try {
            // List existing collections
            $response = $this->http->get("library/{$this->libraryId}/collections");
            $collections = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

            // Check if collection with this name exists
            foreach ($collections['items'] ?? [] as $collection) {
                if ($collection['name'] === $name) {
                    return $collection['guid'];
                }
            }

            // Create new collection
            $response = $this->http->post(
                "library/{$this->libraryId}/collections",
                ['json' => ['name' => $name]]
            );

            $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
            return $data['guid'];
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get/create collection on Bunny: ' . $e->getMessage());
        }
    }

    /**
     * Get the video count for a collection.
     */
    public function getCollectionVideoCount(string $collectionId): int
    {
        try {
            $response = $this->http->get("library/{$this->libraryId}/collections/{$collectionId}");
            $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
            return $data['videoCount'] ?? 0;
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get collection from Bunny: ' . $e->getMessage());
        }
    }

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $collectionId): bool
    {
        try {
            $this->http->delete("library/{$this->libraryId}/collections/{$collectionId}");
            return true;
        } catch (GuzzleException $e) {
            throw new Exception('Failed to delete collection from Bunny: ' . $e->getMessage());
        }
    }

    /**
     * Generate TUS upload credentials for direct browser-to-Bunny uploads.
     *
     * @param string $videoId The video ID to upload to
     * @param int $expirationSeconds Signature validity duration (default 6 hours)
     * @return array TUS credentials (signature, expiration, libraryId, videoId, endpoint)
     */
    public function generateTusCredentials(string $videoId, int $expirationSeconds = 21600): array
    {
        $expiration = time() + $expirationSeconds;
        $signature = hash('sha256', $this->libraryId . $this->apiKey . $expiration . $videoId);

        return [
            'signature' => $signature,
            'expiration' => $expiration,
            'libraryId' => $this->libraryId,
            'videoId' => $videoId,
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
        ];
    }

    /**
     * Get the API key (needed for TUS authentication header).
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
