<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use Kirby\Cms\App;
use Kirby\Cms\File;
use RuntimeException;

/**
 * Handles video upload to Bunny Stream.
 */
class VideoUploader
{
    /**
     * Resolve the Bunny collection ID for a file.
     * Creates the collection if it doesn't exist.
     */
    public static function resolveCollection(File $file): ?string
    {
        $mode = App::instance()->option('jonasfeige.kirby-bunny-stream.collection', 'site');
        $client = BunnyStreamClient::instance();

        if ($mode === 'page') {
            $name = $file->parent()->slug();
        } else {
            $name = site()->title()->value() ?: 'kirby-site';
        }

        return $client->getOrCreateCollection($name);
    }

    /**
     * Process video upload to Bunny Stream.
     *
     * @param File $file The video file to process
     * @return File The updated file with Bunny metadata
     * @throws RuntimeException If upload fails
     */
    public static function process(File $file): File
    {
        $client = BunnyStreamClient::instance();
        $collectionId = self::resolveCollection($file);

        $result = $client->upload(
            $file->root(),
            $file->filename(),
            $collectionId
        );

        if (!isset($result['guid'])) {
            throw new RuntimeException('Bunny upload failed: missing video guid in response');
        }

        $videoId = $result['guid'];

        // Update file metadata
        $updatedFile = $file->update([
            'bunnyvideoid' => $videoId,
            'bunnycollectionid' => $collectionId,
            'bunnydata' => json_encode($result),
        ]);

        // Replace original video with placeholder (Bunny serves actual video)
        $videoPath = $file->root();
        if (file_exists($videoPath)) {
            file_put_contents($videoPath, 'BUNNY:' . $videoId);
        }

        return $updatedFile;
    }

    /**
     * Clean up files after a failed upload.
     */
    public static function cleanupFailedUpload(File $file): void
    {
        $filePath = $file->root();
        $metaPath = preg_replace('/\.[^.]+$/', '.txt', $filePath);

        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        if (file_exists($metaPath)) {
            @unlink($metaPath);
        }
    }
}
