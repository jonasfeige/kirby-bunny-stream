<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App as Kirby;
use KirbyBunny\Stream\BunnyVideoPreview;

function resolveBunnyCollection(\Kirby\Cms\File $file): ?string
{
    $mode = Kirby::instance()->option('jonasfeige.kirby-bunny-stream.collection', 'site');
    $client = \KirbyBunny\Stream\BunnyStreamClient::instance();

    if ($mode === 'page') {
        $name = $file->parent()->slug();
    } else {
        $name = site()->title()->value() ?: 'kirby-site';
    }

    return $client->getOrCreateCollection($name);
}

function createPlaceholderThumbnail(string $directory, string $baseName): string
{
    // Create a simple gray placeholder image
    $image = imagecreatetruecolor(640, 360);
    $gray = imagecolorallocate($image, 128, 128, 128);
    $darkGray = imagecolorallocate($image, 64, 64, 64);

    imagefill($image, 0, 0, $gray);

    // Add "Processing..." text
    $text = 'Processing...';
    $fontSize = 5;
    $textWidth = imagefontwidth($fontSize) * strlen($text);
    $textX = (640 - $textWidth) / 2;
    imagestring($image, $fontSize, (int)$textX, 170, $text, $darkGray);

    $thumbnailPath = $directory . '/' . $baseName . '.jpg';
    imagejpeg($image, $thumbnailPath, 90);
    imagedestroy($image);

    return $thumbnailPath;
}

/**
 * Process video upload to Bunny Stream.
 * Uploads the video and updates the file's metadata with Bunny info.
 * The original video file is kept - thumbnails are served from Bunny CDN.
 *
 * @param \Kirby\Cms\File $file The video file to process
 * @return \Kirby\Cms\File The updated file with Bunny metadata
 * @throws \RuntimeException If upload fails or response is invalid
 */
function processVideoUpload(\Kirby\Cms\File $file): \Kirby\Cms\File
{
    bunny_debug("processVideoUpload START");
    $client = \KirbyBunny\Stream\BunnyStreamClient::instance();

    // Get or create collection
    bunny_debug("Getting collection...");
    $collectionId = resolveBunnyCollection($file);
    bunny_debug("Collection ID: " . ($collectionId ?? 'null'));

    // Upload to Bunny
    bunny_debug("Uploading to Bunny...");
    try {
        $result = $client->upload(
            $file->root(),
            $file->filename(),
            $collectionId
        );
    } catch (\Exception $e) {
        bunny_debug("Upload error: " . $e->getMessage());
        throw $e;
    }

    // Validate upload result structure
    if (!isset($result['guid'])) {
        bunny_debug("No GUID in response");
        throw new \RuntimeException('Bunny upload failed: missing video guid in response');
    }

    $videoId = $result['guid'];
    bunny_debug("Video ID: " . $videoId);

    // Update file metadata with Bunny info
    bunny_debug("Updating file metadata...");
    $updatedFile = $file->update([
        'bunnyvideoid' => $videoId,
        'bunnycollectionid' => $collectionId,
        'bunnydata' => json_encode($result),
    ]);
    bunny_debug("Metadata updated successfully");

    // Replace original video with tiny placeholder (Bunny serves actual video)
    $videoPath = $file->root();
    if (file_exists($videoPath)) {
        bunny_debug("Replacing video with placeholder: " . $videoPath);
        // Create minimal placeholder - just enough for Kirby to recognize the file exists
        file_put_contents($videoPath, 'BUNNY:' . $videoId);
    }

    bunny_debug("processVideoUpload END");
    return $updatedFile;
}

// Debug logging function using Ray (if available)
function bunny_debug($message) {
    if (function_exists('ray')) {
        ray($message)->label('bunny-stream');
    }
}

// Static flag to prevent re-entrant hook calls during processing
$GLOBALS['bunny_stream_processing'] = false;

Kirby::plugin('jonasfeige/kirby-bunny-stream', [
    'options' => [
        'apiKey' => null,
        'libraryId' => null,
        'cdnHostname' => null, // Required: copy from Bunny dashboard
        'webhookSecret' => null,
        'collection' => 'site', // 'site' or 'page'
    ],
    'blueprints' => [
        'files/bunny-video' => __DIR__ . '/blueprints/files/bunny-video.yml',
        'files/bunny-video-fields' => __DIR__ . '/blueprints/files/bunny-video-fields.yml',
        'sections/bunnyvideos' => __DIR__ . '/blueprints/sections/bunnyvideos.yml',
        'fields/bunnyvideo' => __DIR__ . '/blueprints/fields/bunnyvideo.yml',
    ],
    'filePreviews' => [
        BunnyVideoPreview::class,
    ],
    'fileMethods' => [
        'bunnyThumbnail' => function (): ?string {
            // Check for custom thumbnail override first
            $custom = $this->content()->customthumbnail()->toFiles()->first();
            if ($custom) {
                return $custom->url();
            }

            $videoId = $this->content()->bunnyvideoid()->value();
            if (!$videoId) {
                return null;
            }

            // Check stored status
            $bunnyData = $this->content()->bunnydata()->value();
            $data = $bunnyData ? json_decode($bunnyData, true) : [];
            $status = $data['status'] ?? 0;

            // Lazy poll: if not ready, check Bunny API
            if ($status !== 4) {
                try {
                    $freshData = \KirbyBunny\Stream\BunnyStreamClient::instance()->getVideo($videoId);
                    if (($freshData['status'] ?? 0) === 4) {
                        $this->update(['bunnydata' => json_encode($freshData)]);
                        $status = 4;
                    }
                } catch (\Exception $e) {
                    // Ignore API errors
                }
            }

            // Only return thumbnail if ready
            if ($status !== 4) {
                return null;
            }

            try {
                return \KirbyBunny\Stream\BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg");
            } catch (\Exception $e) {
                return null;
            }
        },
        'bunnyStatus' => function (): int {
            $bunnyData = $this->content()->bunnydata()->value();
            $data = $bunnyData ? json_decode($bunnyData, true) : [];
            return $data['status'] ?? 0;
        },
        'isBunnyReady' => function (): bool {
            return $this->bunnyStatus() === 4;
        },
        'isBunnyProcessing' => function (): bool {
            $status = $this->bunnyStatus();
            return $status >= 0 && $status < 4;
        },
        'bunnyVideoId' => function (): ?string {
            return $this->content()->bunnyvideoid()->value() ?: null;
        },
        'bunnyData' => function (): array {
            $data = $this->content()->bunnydata()->value();
            return $data ? json_decode($data, true) : [];
        },
        'bunnyWidth' => function (): ?int {
            return $this->bunnyData()['width'] ?? null;
        },
        'bunnyHeight' => function (): ?int {
            return $this->bunnyData()['height'] ?? null;
        },
        'bunnyAspectRatio' => function (): float {
            $width = $this->bunnyWidth();
            $height = $this->bunnyHeight();
            return ($width && $height) ? $width / $height : 16 / 9;
        },
        'bunnyDuration' => function (): ?int {
            return $this->bunnyData()['length'] ?? null;
        },
        'bunnyHlsUrl' => function (): ?string {
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return null;
            }
            try {
                return \KirbyBunny\Stream\BunnyStreamClient::instance()->cdnUrl("/{$videoId}/playlist.m3u8");
            } catch (\Exception $e) {
                return null;
            }
        },
        'bunnyMp4Url' => function (int $resolution = 720): ?string {
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return null;
            }
            try {
                return \KirbyBunny\Stream\BunnyStreamClient::instance()->cdnUrl("/{$videoId}/play_{$resolution}p.mp4");
            } catch (\Exception $e) {
                return null;
            }
        },
        'bunnyPanelImage' => function (): array {
            // Check for custom thumbnail first
            $custom = $this->content()->customthumbnail()->toFiles()->first();
            if ($custom) {
                return [
                    'src' => $custom->url(),
                    'back' => 'black',
                    'cover' => true,
                ];
            }

            // Show Bunny thumbnail if ready
            if ($this->isBunnyReady()) {
                $thumbnail = $this->bunnyThumbnail();
                if ($thumbnail) {
                    return [
                        'src' => $thumbnail,
                        'back' => 'black',
                        'cover' => true,
                    ];
                }
            }

            // Still processing - show loader icon
            return [
                'icon' => 'loader',
                'back' => 'yellow-500',
                'color' => 'yellow-900',
            ];
        },
    ],
    'pagesMethods' => [
        'bunnyVideos' => function (bool $readyOnly = true) {
            $videos = $this->files()->template('bunny-video');
            if ($readyOnly) {
                return $videos->filter(fn($f) => $f->isBunnyReady());
            }
            return $videos;
        },
    ],
    'pageMethods' => [
        'bunnyVideos' => function (bool $readyOnly = true) {
            $videos = $this->files()->template('bunny-video');
            if ($readyOnly) {
                return $videos->filter(fn($f) => $f->isBunnyReady());
            }
            return $videos;
        },
    ],
    'routes' => [
        [
            'pattern' => 'bunny-stream/webhook',
            'method' => 'POST',
            'action' => function () {
                return \KirbyBunny\Stream\Webhook::handle();
            },
        ],
    ],
    'hooks' => [
        'file.create:after' => function ($file) {
            bunny_debug("=== HOOK START ===");
            bunny_debug("file type: " . gettype($file));
            bunny_debug("file class: " . (is_object($file) ? get_class($file) : 'not object'));
            bunny_debug("processing flag: " . ($GLOBALS['bunny_stream_processing'] ? 'true' : 'false'));

            // Prevent re-entrant calls (when we create the thumbnail file)
            if ($GLOBALS['bunny_stream_processing'] === true) {
                bunny_debug("Skipping - already processing");
                return;
            }

            // Safety check - must be a valid Kirby File object
            if (!$file instanceof \Kirby\Cms\File) {
                bunny_debug("Skipping - not a Kirby File instance");
                return;
            }

            bunny_debug("Checking template...");

            // Only process bunny-video files
            try {
                $template = $file->template();
                bunny_debug("Template: " . ($template ?? 'null'));
            } catch (\Throwable $e) {
                bunny_debug("Template error: " . $e->getMessage());
                return;
            }

            if ($template !== 'bunny-video') {
                bunny_debug("Skipping - not bunny-video template");
                return;
            }

            bunny_debug("Checking file type...");
            $fileType = $file->type();
            bunny_debug("File type: " . ($fileType ?? 'null'));

            // Skip if already processed (is an image = thumbnail)
            if ($fileType === 'image') {
                bunny_debug("Skipping - is image (thumbnail)");
                return;
            }

            bunny_debug("Processing video upload...");
            // Set flag to prevent nested hook calls
            $GLOBALS['bunny_stream_processing'] = true;

            try {
                processVideoUpload($file);
                bunny_debug("Upload complete!");
            } catch (\Exception $e) {
                bunny_debug("Upload error: " . $e->getMessage());
                // Clean up files directly (not via Kirby) to avoid hook recursion issues
                $filePath = $file->root();
                $metaPath = preg_replace('/\.[^.]+$/', '.txt', $filePath);

                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                if (file_exists($metaPath)) {
                    @unlink($metaPath);
                }

                $GLOBALS['bunny_stream_processing'] = false;
                throw $e;
            }

            $GLOBALS['bunny_stream_processing'] = false;
            bunny_debug("=== HOOK END ===");
        },

        'file.delete:before' => function ($file) {
            // Safety check - must be a valid Kirby File object
            if (!$file instanceof \Kirby\Cms\File) {
                return;
            }

            try {
                $template = $file->template();
            } catch (\Throwable $e) {
                return;
            }

            if ($template !== 'bunny-video') {
                return;
            }

            $videoId = $file->content()->bunnyvideoid()->value();
            if (!$videoId) {
                return;
            }

            try {
                \KirbyBunny\Stream\BunnyStreamClient::instance()->delete($videoId);
            } catch (\Exception $e) {
                // Log error but don't prevent deletion
                kirby()->log('bunny-stream')->error('Failed to delete video from Bunny', [
                    'videoId' => $videoId,
                    'error' => $e->getMessage(),
                ]);
            }
        },

        'file.replace:after' => function ($newFile, $oldFile) {
            // Prevent re-entrant calls
            if ($GLOBALS['bunny_stream_processing'] === true) {
                return;
            }

            // Safety check - must be valid Kirby File objects
            if (!$newFile instanceof \Kirby\Cms\File) {
                return;
            }

            try {
                $template = $newFile->template();
            } catch (\Throwable $e) {
                return;
            }

            if ($template !== 'bunny-video') {
                return;
            }

            // Only process video uploads (not thumbnail updates)
            if ($newFile->type() !== 'video') {
                return;
            }

            // Get old video ID BEFORE processing new upload
            $oldVideoId = null;
            if ($oldFile instanceof \Kirby\Cms\File) {
                try {
                    $oldVideoId = $oldFile->content()->bunnyvideoid()->value();
                } catch (\Throwable $e) {
                    // Ignore - old file might not have bunny data
                }
            }

            // Set flag to prevent nested hook calls
            $GLOBALS['bunny_stream_processing'] = true;

            // Upload new video to Bunny
            try {
                processVideoUpload($newFile);
            } catch (\Exception $e) {
                // Clean up files directly (not via Kirby) to avoid hook recursion issues
                $filePath = $newFile->root();
                $metaPath = preg_replace('/\.[^.]+$/', '.txt', $filePath);

                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                if (file_exists($metaPath)) {
                    @unlink($metaPath);
                }

                $GLOBALS['bunny_stream_processing'] = false;
                throw $e;
            }

            $GLOBALS['bunny_stream_processing'] = false;

            // Only delete old video from Bunny AFTER successful upload
            if ($oldVideoId) {
                try {
                    \KirbyBunny\Stream\BunnyStreamClient::instance()->delete($oldVideoId);
                } catch (\Exception $e) {
                    // Log error but don't fail - new video is already uploaded
                    kirby()->log('bunny-stream')->error('Failed to delete old video from Bunny', [
                        'videoId' => $oldVideoId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        },
    ],
]);
