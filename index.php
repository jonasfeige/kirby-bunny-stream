<?php

declare(strict_types=1);

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use Kirby\Cms\Language;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\VersionId;
use Kirby\Exception\DuplicateException;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\LogicException;
use Kirby\Exception\NotFoundException;
use KirbyBunny\Stream\BunnyStreamClient;
use KirbyBunny\Stream\BunnyStreamState;
use KirbyBunny\Stream\BunnyVideoPreview;
use KirbyBunny\Stream\ThumbnailStore;
use KirbyBunny\Stream\VideoUploader;

/**
 * Wrap an API route action so that any non-Kirby exception is rethrown as a
 * Kirby exception. Kirby's API only exposes messages of Kirby\Exception\Exception
 * subclasses when debug mode is off; everything else becomes a generic
 * "unexpected error". Our routes return intentional, user-facing messages.
 */
function bunny_api_route(callable $action): callable
{
    return function () use ($action) {
        try {
            return $action();
        } catch (KirbyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new KirbyException(message: $e->getMessage(), previous: $e);
        }
    };
}

Kirby::plugin('jonasfeige/kirby-bunny-stream', [
    'options' => [
        'apiKey' => null,
        'libraryId' => null,
        'cdnHostname' => null,
        // Optional prefix for collection names. Can be:
        // - null: no prefix (default)
        // - string: static prefix (e.g., 'my-site')
        // - Closure: dynamic prefix, receives $parent (Page|Site) as argument
        //   Example: function ($parent) { return site()->title()->value(); }
        'collectionPrefix' => null,
        // Keep Bunny's thumbnail as a Kirby image file next to the video (template
        // `bunny-thumbnail`, `<video name>-thumbnail.jpg`), downloaded once the video is ready
        // and first requested. See src/ThumbnailStore.php.
        'storeThumbnails' => false,
    ],

    'blueprints' => [
        'files/bunny-video' => __DIR__ . '/blueprints/files/bunny-video.yml',
        'files/bunny-video-fields' => __DIR__ . '/blueprints/files/bunny-video-fields.yml',
        'files/bunny-thumbnail' => __DIR__ . '/blueprints/files/bunny-thumbnail.yml',
        'fields/bunnyvideo' => __DIR__ . '/blueprints/fields/bunnyvideo.yml',
    ],

    'sections' => [
        'bunny-video-upload' => require __DIR__ . '/sections/bunny-video-upload.php',
    ],

    'filePreviews' => [
        BunnyVideoPreview::class,
    ],

    'fileMethods' => [
        /**
         * The stored thumbnail file (option `storeThumbnails`), created on first request once
         * the video is ready; null when the option is off, the video is not ready or the
         * download failed.
         */
        'bunnyThumbnailFile' => function (): ?File {
            /** @var File $this */
            return ThumbnailStore::ensure($this);
        },

        /**
         * Thumbnail URL: the stored file when there is one, else Bunny's CDN thumbnail.
         */
        'bunnyThumbnail' => function (): string|false {
            /** @var File $this */
            if ($stored = $this->bunnyThumbnailFile()) {
                return $stored->url();
            }

            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return false;
            }

            // bunnyData() triggers refresh if not ready
            if (!$this->isBunnyReady()) {
                return false;
            }

            try {
                return BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg");
            } catch (\Exception $e) {
                return false;
            }
        },

        'bunnyStatus' => function (): int {
            /** @var File $this */
            // bunnyData() triggers refresh if not ready
            return $this->bunnyData()['status'] ?? 0;
        },

        'isBunnyReady' => function (): bool {
            /** @var File $this */
            return $this->bunnyStatus() === BunnyStreamState::STATUS_READY;
        },

        'isBunnyProcessing' => function (): bool {
            /** @var File $this */
            $status = $this->bunnyStatus();
            return $status >= BunnyStreamState::STATUS_QUEUED && $status < BunnyStreamState::STATUS_READY;
        },

        'bunnyVideoId' => function (): ?string {
            /** @var File $this */
            return $this->content()->bunnyvideoid()->value() ?: null;
        },

        'bunnyData' => function (): array {
            /** @var File $this */
            $data = $this->content()->bunnydata()->value();
            $decoded = $data ? json_decode($data, true) : [];
            $status = $decoded['status'] ?? 0;

            // Already ready or error - return cached
            if ($status === BunnyStreamState::STATUS_READY || $status >= BunnyStreamState::STATUS_ERROR) {
                return $decoded;
            }

            // Not ready - fetch from Bunny API
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return $decoded;
            }

            try {
                $freshData = BunnyStreamClient::instance()->getVideo($videoId);
                if ($freshData && isset($freshData['status']) && $freshData['status'] !== $status) {
                    // Persist by writing directly to filesystem (bypasses immutable check)
                    $this->bunnyPersistData($freshData);
                    return $freshData;
                }
            } catch (\Exception $e) {}

            return $decoded;
        },

        'bunnyPersistData' => function (array $data): void {
            /** @var File $this */
            $this->bunnyPersistField('Bunnydata', json_encode($data));
        },

        /**
         * Writes one field straight into the content file (e.g. video.mp4.txt), replacing the
         * existing line or appending a new field. Bypasses File::update(), so it works from
         * requests without a user and never writes a stale in-memory content back.
         */
        'bunnyPersistField' => function (string $field, string $value): void {
            /** @var File $this */
            try {
                // The default language's content file (video.mp4.txt, or video.mp4.en.txt on a
                // multi-language site); plugin-managed fields are not translated
                $storage = $this->storage();
                $contentFile = $storage instanceof PlainTextStorage
                    ? $storage->contentFile(VersionId::latest(), Language::ensure('default'))
                    : $this->parent()->root() . '/' . $this->filename() . '.txt';

                if (!file_exists($contentFile)) {
                    return;
                }

                $content = file_get_contents($contentFile);
                $line = "{$field}: {$value}";

                if (preg_match('/^' . preg_quote($field, '/') . ':.*$/m', $content)) {
                    $content = preg_replace('/^' . preg_quote($field, '/') . ':.*$/m', $line, $content);
                } else {
                    $content .= "\n\n----\n\n{$line}";
                }

                file_put_contents($contentFile, $content);
            } catch (\Exception $e) {
                // Silently fail - will retry next request
            }
        },

        'bunnyWidth' => function (): ?int {
            /** @var File $this */
            return BunnyStreamClient::displayDimensions($this->bunnyData())[0];
        },

        'bunnyHeight' => function (): ?int {
            /** @var File $this */
            return BunnyStreamClient::displayDimensions($this->bunnyData())[1];
        },

        'bunnyIsRotated' => function (): bool {
            /** @var File $this */
            $rotation = abs((int) ($this->bunnyData()['rotation'] ?? 0)) % 180;
            return $rotation === 90;
        },

        'bunnyAspectRatio' => function (): ?float {
            /** @var File $this */
            $width = $this->bunnyWidth();
            $height = $this->bunnyHeight();
            return ($width && $height) ? $width / $height : null;
        },

        'bunnyDuration' => function (): ?int {
            /** @var File $this */
            return $this->bunnyData()['length'] ?? null;
        },

        'bunnyHlsUrl' => function (): ?string {
            /** @var File $this */
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return null;
            }
            try {
                return BunnyStreamClient::instance()->cdnUrl("/{$videoId}/playlist.m3u8");
            } catch (\Exception $e) {
                return null;
            }
        },

        'bunnyMp4Url' => function (int $resolution = 720): ?string {
            /** @var File $this */
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return null;
            }
            try {
                return BunnyStreamClient::instance()->cdnUrl("/{$videoId}/play_{$resolution}p.mp4");
            } catch (\Exception $e) {
                return null;
            }
        },

        'bunnyPanelImage' => function (): array {
            /** @var File $this */
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

            return [
                'icon' => 'loader',
                'back' => 'yellow-500',
                'color' => 'black',
            ];
        },

        'bunnyStatusInfo' => function (): ?string {
            /** @var File $this */
            if (!$this->bunnyVideoId()) {
                return null;
            }

            // bunnyData() triggers refresh if not ready
            $data = $this->bunnyData();
            $status = $data['status'] ?? 0;

            // Ready - no info needed
            if ($status === BunnyStreamState::STATUS_READY) {
                return null;
            }

            // Show progress if encoding
            $progress = $data['encodeProgress'] ?? null;
            if ($progress !== null && $status === BunnyStreamState::STATUS_ENCODING) {
                return "Encoding {$progress}%";
            }

            // Map other statuses
            $statusMap = [
                BunnyStreamState::STATUS_QUEUED => 'Queued',
                BunnyStreamState::STATUS_PROCESSING => 'Processing',
                BunnyStreamState::STATUS_ENCODING => 'Encoding',
                BunnyStreamState::STATUS_FINISHED => 'Finishing',
                BunnyStreamState::STATUS_ERROR => 'Error',
                BunnyStreamState::STATUS_UPLOAD_FAILED => 'Upload failed',
            ];

            return $statusMap[$status] ?? null;
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

    'api' => [
        'routes' => [
                        [
                'pattern' => 'bunny-stream/init-upload',
                'method' => 'POST',
                'action' => bunny_api_route(function () {
                    $kirby = kirby();
                    $request = $kirby->request();
                    $filename = $request->body()->get('filename');
                    $parentType = $request->body()->get('parentType');
                    $parentId = $request->body()->get('parentId');
                    $collectionOption = $request->body()->get('collection');

                    if (!$filename) {
                        throw new InvalidArgumentException('Filename is required');
                    }

                    // Resolve parent
                    if ($parentType === 'site') {
                        $parent = site();
                    } else {
                        // Use kirby()->page() which finds both published and draft pages
                        $parent = kirby()->page($parentId);
                        if (!$parent) {
                            throw new NotFoundException('Parent page not found');
                        }
                    }

                    // Sanitize filename first (same as finalize-upload)
                    $extension = pathinfo($filename, PATHINFO_EXTENSION);
                    if (!$extension) {
                        $filename .= '.mp4';
                    }
                    $filename = \Kirby\Toolkit\F::safeName($filename);

                    $client = BunnyStreamClient::instance();

                    // Resolve collection (with optional custom setting)
                    $collectionId = VideoUploader::resolveCollectionWithOption($parent, $collectionOption);

                    // Check if a video with this title already exists in the Bunny collection
                    $existingVideo = $client->findVideoByTitle($filename, $collectionId);
                    if ($existingVideo) {
                        throw new DuplicateException("A video named '{$filename}' already exists in this collection. Please use a different filename.");
                    }

                    // Check if a Kirby file with this name already exists
                    $existingFile = $parent->file($filename);
                    if ($existingFile) {
                        throw new DuplicateException("A file named '{$filename}' already exists on this page. Please use a different filename or delete the existing file first.");
                    }

                    // Create video on Bunny
                    $video = $client->createVideo($filename, $collectionId);

                    if (!isset($video['guid'])) {
                        throw new LogicException('Failed to create video on Bunny');
                    }

                    $videoId = $video['guid'];

                    // Generate TUS credentials
                    $tusCredentials = $client->generateTusCredentials($videoId);

                    return [
                        'videoId' => $videoId,
                        'collectionId' => $collectionId,
                        'tusCredentials' => $tusCredentials,
                    ];
                }),
            ],
            [
                'pattern' => 'bunny-stream/finalize-upload',
                'method' => 'POST',
                'action' => bunny_api_route(function () {
                    $kirby = kirby();
                    $request = $kirby->request();
                    $videoId = $request->body()->get('videoId');
                    $collectionId = $request->body()->get('collectionId');
                    $filename = $request->body()->get('filename');
                    $parentType = $request->body()->get('parentType');
                    $parentId = $request->body()->get('parentId');

                    if (!$videoId || !$filename) {
                        throw new InvalidArgumentException('videoId and filename are required');
                    }

                    // Resolve parent
                    if ($parentType === 'site') {
                        $parent = site();
                    } else {
                        // Use kirby()->page() which finds both published and draft pages
                        $parent = kirby()->page($parentId);
                        if (!$parent) {
                            throw new NotFoundException('Parent page not found');
                        }
                    }

                    // Ensure filename has video extension (use .mp4 as default)
                    $extension = pathinfo($filename, PATHINFO_EXTENSION);
                    if (!$extension) {
                        $filename .= '.mp4';
                    }

                    // Sanitize filename for Kirby
                    $filename = \Kirby\Toolkit\F::safeName($filename);

                    // Create files directly in filesystem (bypasses mime validation)
                    $contentDir = $parent->root();
                    $filePath = $contentDir . '/' . $filename;
                    $metaPath = $contentDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.' . pathinfo($filename, PATHINFO_EXTENSION) . '.txt';

                    // Write placeholder video file
                    $placeholderContent = 'BUNNY:' . $videoId;
                    file_put_contents($filePath, $placeholderContent);

                    // Write metadata file (same format as Kirby content files)
                    // Note: bunnydata is left empty - will be populated lazily when video is ready
                    $metaContent = "Bunnyvideoid: {$videoId}\n\n----\n\nBunnycollectionid: {$collectionId}\n\n----\n\nBunnydata: \n\n----\n\nTemplate: bunny-video";
                    file_put_contents($metaPath, $metaContent);

                    // Clear Kirby's cache so it picks up the new file
                    $kirby->impersonate('kirby');

                    // Get the file through Kirby to confirm it exists
                    $file = $parent->file($filename);

                    if (!$file) {
                        throw new LogicException('Failed to create file');
                    }

                    return [
                        'success' => true,
                        'file' => [
                            'filename' => $file->filename(),
                            'id' => $file->id(),
                        ],
                    ];
                }),
            ],
            [
                'pattern' => 'bunny-stream/cancel-upload',
                'method' => 'POST',
                'action' => function () {
                    $data = kirby()->request()->body()->toArray();
                    $videoId = $data['videoId'] ?? null;
                    $collectionId = $data['collectionId'] ?? null;

                    if (!$videoId) {
                        return ['success' => false, 'error' => 'Missing videoId'];
                    }

                    try {
                        $client = BunnyStreamClient::instance();

                        // Delete video from Bunny
                        $client->delete($videoId);

                        // Clean up empty collection (same logic as file.delete:before)
                        if ($collectionId) {
                            try {
                                $videoCount = $client->getCollectionVideoCount($collectionId);
                                if ($videoCount === 0) {
                                    $client->deleteCollection($collectionId);
                                }
                            } catch (\Exception $e) {
                                // Don't fail if collection cleanup fails
                            }
                        }

                        return ['success' => true];
                    } catch (\Exception $e) {
                        return ['success' => false, 'error' => $e->getMessage()];
                    }
                },
            ],
        ],
    ],

    'hooks' => [
        'file.create:after' => function (File $file) {
            // Skip if already processing or if this is a direct upload
            if (BunnyStreamState::$processing || BunnyStreamState::$directUploadInProgress) {
                return;
            }

            try {
                if ($file->template() !== 'bunny-video') {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            if ($file->type() === 'image') {
                return;
            }

            BunnyStreamState::$processing = true;

            try {
                VideoUploader::process($file);
            } catch (\Exception $e) {
                VideoUploader::cleanupFailedUpload($file);
                BunnyStreamState::$processing = false;
                throw $e;
            }

            BunnyStreamState::$processing = false;
        },

        'file.delete:before' => function (File $file) {
            try {
                if ($file->template() !== 'bunny-video') {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            // The stored thumbnail goes with its video (before the Bunny call, which may return early)
            ThumbnailStore::delete($file);

            $videoId = $file->content()->bunnyvideoid()->value();
            $collectionId = $file->content()->bunnycollectionid()->value();

            if (!$videoId) {
                return;
            }

            $client = BunnyStreamClient::instance();

            // Attempt to delete video from Bunny
            try {
                $client->delete($videoId);
            } catch (\Exception $e) {
                // Don't block local deletion if Bunny delete fails
                return;
            }

            // Clean up empty collection
            if ($collectionId) {
                try {
                    $videoCount = $client->getCollectionVideoCount($collectionId);
                    if ($videoCount === 0) {
                        $client->deleteCollection($collectionId);
                    }
                } catch (\Exception $e) {
                    // Don't block deletion if collection cleanup fails
                }
            }
        },

        'file.changeName:after' => function (File $newFile, File $oldFile) {
            try {
                if ($newFile->template() !== 'bunny-video') {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            $videoId = $newFile->content()->bunnyvideoid()->value();
            if (!$videoId) {
                return;
            }

            // Update video title on Bunny to match new filename
            try {
                BunnyStreamClient::instance()->updateVideo($videoId, [
                    'title' => $newFile->filename(),
                ]);
            } catch (\Exception $e) {
                // Don't fail if Bunny update fails
            }
        },

        'file.replace:after' => function (File $newFile, File $oldFile) {
            if (BunnyStreamState::$processing) {
                return;
            }

            try {
                if ($newFile->template() !== 'bunny-video') {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            if ($newFile->type() !== 'video') {
                return;
            }

            $oldVideoId = null;
            try {
                $oldVideoId = $oldFile->content()->bunnyvideoid()->value();
            } catch (\Throwable $e) {
                // Old file might not have bunny data
            }

            // The old video's stored thumbnail must not become the new video's: delete it,
            // the dangling link is refetched once the new video is ready
            ThumbnailStore::delete($newFile);

            BunnyStreamState::$processing = true;

            try {
                VideoUploader::process($newFile);
            } catch (\Exception $e) {
                VideoUploader::cleanupFailedUpload($newFile);
                BunnyStreamState::$processing = false;
                throw $e;
            }

            BunnyStreamState::$processing = false;

            // Delete old video from Bunny
            if ($oldVideoId) {
                try {
                    BunnyStreamClient::instance()->delete($oldVideoId);
                } catch (\Exception $e) {
                    // Don't fail if old video deletion fails
                }
            }
        },
    ],
]);
