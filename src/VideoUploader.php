<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use Kirby\Cms\File;
use Kirby\Query\Query;
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
     * Checks blueprint options for custom collection setting first,
     * then falls back to default behavior.
     */
    public static function resolveCollection(File $file): string
    {
        $collectionOption = $file->blueprint()->options()['collection'] ?? null;
        return self::resolveCollectionWithOption($file->parent(), $collectionOption);
    }

    /**
     * Get the collection prefix from config.
     * Supports null (no prefix), string, or Closure.
     *
     * @param \Kirby\Cms\Site|\Kirby\Cms\Page $parent
     */
    private static function getCollectionPrefix($parent): string
    {
        $prefix = kirby()->option('jonasfeige.kirby-bunny-stream.collectionPrefix');

        if ($prefix === null) {
            return '';
        }

        if ($prefix instanceof \Closure) {
            $result = $prefix($parent);
            return $result ? Str::slug((string)$result) . '/' : '';
        }

        return Str::slug((string)$prefix) . '/';
    }

    /**
     * Resolve the Bunny collection ID for a parent (Site or Page).
     * Creates the collection if it doesn't exist.
     *
     * Default collection names:
     * - Site files: "site" (or "{prefix}/site" with collectionPrefix config)
     * - Page files: "{page-id}" (or "{prefix}/{page-id}" with collectionPrefix config)
     *
     * @param \Kirby\Cms\Site|\Kirby\Cms\Page $parent
     */
    public static function resolveCollectionForParent($parent): string
    {
        $client = BunnyStreamClient::instance();
        $prefix = self::getCollectionPrefix($parent);

        if ($parent instanceof \Kirby\Cms\Site) {
            $name = $prefix . 'site';
        } else {
            $name = $prefix . $parent->id();
        }

        return $client->getOrCreateCollection($name);
    }

    /**
     * Resolve the Bunny collection ID with an optional custom collection setting.
     *
     * @param \Kirby\Cms\Site|\Kirby\Cms\Page $parent
     * @param string|null $collectionOption Custom collection path (static string or Kirby query)
     *                                      Examples: "my-collection", "{{ page.parent.id }}"
     */
    public static function resolveCollectionWithOption($parent, ?string $collectionOption): string
    {
        // If no custom option, use default behavior
        if (!$collectionOption) {
            return self::resolveCollectionForParent($parent);
        }

        $client = BunnyStreamClient::instance();
        $prefix = self::getCollectionPrefix($parent);

        // Check if it's a query (contains {{ }})
        if (preg_match('/\{\{\s*(.+?)\s*\}\}/', $collectionOption, $matches)) {
            $query = trim($matches[1]);

            try {
                $resolved = Query::factory($query)->resolve([
                    'page' => $parent instanceof \Kirby\Cms\Page ? $parent : null,
                    'site' => site(),
                    'kirby' => kirby(),
                ]);

                if ($resolved) {
                    $name = $prefix . Str::slug((string)$resolved);
                    return $client->getOrCreateCollection($name);
                }
            } catch (\Exception $e) {
                // Fall back to default on query error
            }
        } else {
            // Static string - use directly
            $name = $prefix . Str::slug($collectionOption);
            return $client->getOrCreateCollection($name);
        }

        // Fallback to default behavior
        return self::resolveCollectionForParent($parent);
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
