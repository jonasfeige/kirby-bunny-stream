<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use Kirby\Cms\File;
use Kirby\Toolkit\Str;
use RuntimeException;

/**
 * Handles video upload to Bunny Stream.
 */
class VideoUploader
{
    /**
     * Resolve the Bunny collection ID for a file.
     * Creates the collection if it doesn't exist.
     *
     * Collections are prefixed with the site slug:
     * - Site files: "{site-slug}/site"
     * - Page files: "{site-slug}/{page-path}" (e.g., "bastianthiery/work/some-project")
     */
    public static function resolveCollection(File $file): string
    {
        $parent = $file->parent();
        return self::resolveCollectionForParent($parent);
    }

    /**
     * Resolve the Bunny collection ID for a parent (Site or Page).
     * Creates the collection if it doesn't exist.
     *
     * @param \Kirby\Cms\Site|\Kirby\Cms\Page $parent
     */
    public static function resolveCollectionForParent($parent): string
    {
        $client = BunnyStreamClient::instance();
        $siteSlug = Str::slug(site()->title()->value() ?: 'kirby');

        if ($parent instanceof \Kirby\Cms\Site) {
            $name = $siteSlug . '/site';
        } else {
            $name = $siteSlug . '/' . $parent->id();
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
