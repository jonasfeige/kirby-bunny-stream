<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use Kirby\Cms\File;
use Kirby\Panel\Ui\FilePreview;

/**
 * Custom file preview for Bunny Stream videos
 * Shows video player when ready, processing state otherwise
 *
 * @since 5.0.0
 */
class BunnyVideoPreview extends FilePreview
{
    public function __construct(
        public File $file,
        public string $component = 'k-bunny-video-preview'
    ) {
    }

    /**
     * Accept files with bunny-video template
     */
    public static function accepts(File $file): bool
    {
        return $file->template() === 'bunny-video';
    }

    /**
     * Return preview props for the Vue component
     */
    public function props(): array
    {
        $file = $this->file;

        // Get Bunny data from file content (works with any File class)
        $videoId = $file->content()->bunnyvideoid()->value();
        $bunnyDataRaw = $file->content()->bunnydata()->value();
        $bunnyData = $bunnyDataRaw ? json_decode($bunnyDataRaw, true) : [];

        $status = $bunnyData['status'] ?? 0;
        $progress = $bunnyData['encodeProgress'] ?? null;
        $width = $bunnyData['width'] ?? 1920;
        $height = $bunnyData['height'] ?? 1080;

        $availableResolutions = $bunnyData['availableResolutions'] ?? '';

        // Lazy check: if not ready, poll Bunny API for current status
        if ($status !== BunnyStreamState::STATUS_READY && $videoId) {
            try {
                $freshData = BunnyStreamClient::instance()->getVideo($videoId);
                if ($freshData && isset($freshData['status'])) {
                    $status = $freshData['status'];
                    $progress = $freshData['encodeProgress'] ?? $progress;
                    $width = $freshData['width'] ?? $width;
                    $height = $freshData['height'] ?? $height;
                    $availableResolutions = $freshData['availableResolutions'] ?? $availableResolutions;

                    // Update stored metadata if status changed to ready
                    if ($status === BunnyStreamState::STATUS_READY) {
                        $file->update(['bunnydata' => json_encode($freshData)]);
                    }
                }
            } catch (\Exception $e) {
                // Use cached data on API errors
            }
        }

        $isReady = $status === BunnyStreamState::STATUS_READY;

        // Build URLs from Bunny CDN
        $embedUrl = null;
        $thumbnailUrl = null;

        if ($videoId) {
            try {
                $client = BunnyStreamClient::instance();
                $libraryId = $client->getLibraryId();
                $thumbnailUrl = $client->cdnUrl("/{$videoId}/thumbnail.jpg");
                if ($isReady) {
                    $embedUrl = "https://player.mediadelivery.net/embed/{$libraryId}/{$videoId}?autoplay=false&loop=false&muted=false&preload=true&responsive=true";
                }
            } catch (\Exception $e) {
                // Ignore CDN errors
            }
        }

        return [
            ...parent::props(),
            'details' => $this->details(),
            'embedUrl' => $embedUrl,
            'thumbnailUrl' => $thumbnailUrl,
            'status' => $status,
            'progress' => $progress,
            'isReady' => $isReady,
            'videoWidth' => $width,
            'videoHeight' => $height,
        ];
    }

    /**
     * Override details with Bunny video metadata
     */
    public function details(): array
    {
        $bunnyDataRaw = $this->file->content()->bunnydata()->value();
        $bunnyData = $bunnyDataRaw ? json_decode($bunnyDataRaw, true) : [];

        $details = [];

        // Dimensions
        $width = $bunnyData['width'] ?? null;
        $height = $bunnyData['height'] ?? null;
        if ($width && $height) {
            $details[] = [
                'title' => 'Dimensions',
                'text' => "{$width} × {$height}",
            ];
        }

        // Duration
        $length = $bunnyData['length'] ?? null;
        if ($length) {
            $minutes = floor($length / 60);
            $seconds = $length % 60;
            $details[] = [
                'title' => 'Duration',
                'text' => sprintf('%d:%02d', $minutes, $seconds),
            ];
        }

        // File size
        $storageSize = $bunnyData['storageSize'] ?? null;
        if ($storageSize) {
            $details[] = [
                'title' => 'Size',
                'text' => $this->formatBytes($storageSize),
            ];
        }

        // Available resolutions
        $resolutions = $bunnyData['availableResolutions'] ?? null;
        if ($resolutions) {
            $details[] = [
                'title' => 'Resolutions',
                'text' => $resolutions,
            ];
        }

        return $details;
    }

    /**
     * Format bytes to human readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Override image to use Bunny thumbnail
     */
    public function image(): array|null
    {
        $videoId = $this->file->content()->bunnyvideoid()->value();

        if ($videoId) {
            try {
                return [
                    'src' => BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg"),
                    'back' => 'black',
                    'ratio' => '16/9'
                ];
            } catch (\Exception $e) {
                // Fall through to parent
            }
        }

        return parent::image();
    }
}
