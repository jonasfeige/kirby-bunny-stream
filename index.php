<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App as Kirby;
use KirbyBunny\Stream\VideoFile;

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
 * Uploads the video, creates thumbnail, replaces original video file with thumbnail.
 *
 * @param \Kirby\Cms\File $file The video file to process
 * @return \Kirby\Cms\File The created thumbnail file with Bunny metadata
 * @throws \RuntimeException If upload fails or response is invalid
 */
function processVideoUpload(\Kirby\Cms\File $file): \Kirby\Cms\File
{
    $client = \KirbyBunny\Stream\BunnyStreamClient::instance();

    // Get or create collection
    $collectionId = resolveBunnyCollection($file);

    // Upload to Bunny - wrap in try-catch to handle failures gracefully
    try {
        $result = $client->upload(
            $file->root(),
            $file->filename(),
            $collectionId
        );
    } catch (\Exception $e) {
        kirby()->log('bunny-stream')->error('Upload failed: ' . $e->getMessage(), [
            'file' => $file->filename(),
        ]);
        throw $e;
    }

    // Validate upload result structure
    if (!isset($result['guid'])) {
        throw new \RuntimeException('Bunny upload failed: missing video guid in response');
    }

    $videoId = $result['guid'];

    // Try to download thumbnail, use placeholder if not ready
    $thumbnailData = $client->downloadThumbnail($videoId);

    $directory = dirname($file->root());
    $baseName = pathinfo($file->filename(), PATHINFO_FILENAME);

    if ($thumbnailData) {
        $thumbnailPath = $directory . '/' . $baseName . '.jpg';
        file_put_contents($thumbnailPath, $thumbnailData);
    } else {
        $thumbnailPath = createPlaceholderThumbnail($directory, $baseName);
    }

    // Store paths for cleanup AFTER successful file creation
    $originalVideoPath = $file->root();
    $originalMetaPath = preg_replace('/\.[^.]+$/', '.txt', $originalVideoPath);

    // Create new thumbnail file with metadata
    $parent = $file->parent();
    $thumbnailFilename = $baseName . '.jpg';

    // Create new file from thumbnail FIRST (before any deletions)
    $newFile = $parent->createFile([
        'source' => $thumbnailPath,
        'filename' => $thumbnailFilename,
        'template' => 'bunny-video',
        'content' => [
            'bunnyvideoid' => $videoId,
            'bunnycollectionid' => $collectionId,
            'bunnydata' => json_encode($result),
        ],
    ]);

    // Only delete original files AFTER successful createFile()
    if (file_exists($originalVideoPath)) {
        unlink($originalVideoPath);
    }
    if (file_exists($originalMetaPath)) {
        unlink($originalMetaPath);
    }

    // Clean up temp thumbnail if it was created separately
    if (file_exists($thumbnailPath) && $thumbnailPath !== $newFile->root()) {
        unlink($thumbnailPath);
    }

    return $newFile;
}

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
    ],
    'fileModels' => [
        'bunny-video' => VideoFile::class,
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
            // Safety check - file might be null in some edge cases
            if (!$file || !method_exists($file, 'template')) {
                return;
            }

            // Only process bunny-video files
            $template = $file->template();
            if ($template !== 'bunny-video') {
                return;
            }

            // Skip if already processed (is an image = thumbnail)
            if ($file->type() === 'image') {
                return;
            }

            try {
                processVideoUpload($file);
            } catch (\Exception $e) {
                // Clean up files directly (not via Kirby) to avoid hook recursion issues
                $filePath = $file->root();
                $metaPath = preg_replace('/\.[^.]+$/', '.txt', $filePath);

                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                if (file_exists($metaPath)) {
                    @unlink($metaPath);
                }

                throw $e;
            }
        },

        'file.delete:before' => function ($file) {
            if (!$file || !method_exists($file, 'template')) {
                return;
            }
            if ($file->template() !== 'bunny-video') {
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
            if (!$newFile || !method_exists($newFile, 'template')) {
                return;
            }
            if ($newFile->template() !== 'bunny-video') {
                return;
            }

            // Only process video uploads (not thumbnail updates)
            if ($newFile->type() !== 'video') {
                return;
            }

            // Get old video ID BEFORE processing new upload
            $oldVideoId = $oldFile->content()->bunnyvideoid()->value();

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

                throw $e;
            }

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
