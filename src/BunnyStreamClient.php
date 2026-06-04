<?php

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
     * Delete any existing video with the same title in the collection.
     * Prevents duplicates when re-uploading after a failed attempt.
     */
    private function deleteExistingByTitle(string $title, ?string $collectionId): void
    {
        try {
            $params = ['itemsPerPage' => 100, 'search' => $title];
            if ($collectionId) {
                $params['collection'] = $collectionId;
            }

            $response = $this->http->get("library/{$this->libraryId}/videos", [
                'query' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

            foreach ($data['items'] ?? [] as $video) {
                if ($video['title'] === $title) {
                    $this->delete($video['guid']);
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail - duplicate prevention is best-effort
            kirby()->log('bunny-stream')->warning('Failed to check for duplicates: ' . $e->getMessage());
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

    public function delete(string $videoId): bool
    {
        try {
            $this->http->delete("library/{$this->libraryId}/videos/{$videoId}");
            return true;
        } catch (GuzzleException $e) {
            throw new Exception('Failed to delete video from Bunny: ' . $e->getMessage());
        }
    }

    public function downloadThumbnail(string $videoId): ?string
    {
        $cdnHostname = $this->getCdnHostname();
        $thumbnailUrl = "https://{$cdnHostname}/{$videoId}/thumbnail.jpg";

        try {
            $response = (new Client())->get($thumbnailUrl);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            // Thumbnail might not be ready yet
            return null;
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
}
