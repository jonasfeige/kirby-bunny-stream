<?php

namespace KirbyBunny\Stream;

use Kirby\Cms\File;
use KirbyBunny\Stream\BunnyStreamClient;

class VideoFile extends File
{
    public function videoId(): ?string
    {
        return $this->content()->bunnyvideoid()->value() ?: null;
    }

    public function collectionId(): ?string
    {
        return $this->content()->bunnycollectionid()->value() ?: null;
    }

    public function bunnyData(): array
    {
        $data = $this->content()->bunnydata()->value();
        return $data ? json_decode($data, true) : [];
    }

    public function status(): int
    {
        return $this->bunnyData()['status'] ?? 0;
    }

    public function isReady(): bool
    {
        // Status 4 = Finished encoding
        if ($this->status() === 4) {
            return true;
        }

        // Lazy check: if not ready, ping Bunny API once
        if ($this->videoId()) {
            try {
                $video = BunnyStreamClient::instance()->getVideo($this->videoId());
                if (($video['status'] ?? 0) === 4) {
                    // Update local metadata
                    $this->update(['bunnydata' => json_encode($video)]);
                    return true;
                }
            } catch (\Exception $e) {
                // Ignore - return current status
            }
        }

        return false;
    }

    public function encodingProgress(): ?int
    {
        return $this->bunnyData()['encodeProgress'] ?? null;
    }

    public function hlsUrl(): ?string
    {
        if (!$this->videoId()) {
            return null;
        }

        try {
            $cdnHostname = BunnyStreamClient::instance()->getCdnHostname();
            return "https://{$cdnHostname}/{$this->videoId()}/playlist.m3u8";
        } catch (\Exception $e) {
            return null;
        }
    }

    public function mp4Url(int $resolution = 720): ?string
    {
        if (!$this->videoId()) {
            return null;
        }

        try {
            $cdnHostname = BunnyStreamClient::instance()->getCdnHostname();
            return "https://{$cdnHostname}/{$this->videoId()}/play_{$resolution}p.mp4";
        } catch (\Exception $e) {
            return null;
        }
    }

    public function thumbnailUrl(): string
    {
        // Check for custom thumbnail override first
        $custom = $this->content()->customthumbnail()->toFile();
        if ($custom) {
            return $custom->url();
        }

        // Otherwise return the file's own URL (the stored thumbnail)
        return $this->url();
    }

    public function posterUrl(): string
    {
        return $this->thumbnailUrl();
    }

    public function previewUrl(): ?string
    {
        if (!$this->videoId()) {
            return null;
        }

        try {
            $cdnHostname = BunnyStreamClient::instance()->getCdnHostname();
            return "https://{$cdnHostname}/{$this->videoId()}/preview.webp";
        } catch (\Exception $e) {
            return null;
        }
    }

    public function duration(): ?int
    {
        return $this->bunnyData()['length'] ?? null;
    }

    public function width(): ?int
    {
        return $this->bunnyData()['width'] ?? null;
    }

    public function height(): ?int
    {
        return $this->bunnyData()['height'] ?? null;
    }

    public function aspectRatio(): ?float
    {
        $width = $this->width();
        $height = $this->height();

        if ($width && $height) {
            return $width / $height;
        }

        return null;
    }
}
