<?php

declare(strict_types=1);

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use KirbyBunny\Stream\BunnyStreamClient;
use KirbyBunny\Stream\BunnyStreamState;
use KirbyBunny\Stream\BunnyVideoPreview;
use KirbyBunny\Stream\VideoUploader;

Kirby::plugin('jonasfeige/kirby-bunny-stream', [
    'options' => [
        'apiKey' => null,
        'libraryId' => null,
        'cdnHostname' => null,
        'webhookSecret' => null,
        'collection' => 'site', // 'site' or 'page'
    ],

    'blueprints' => [
        'files/bunny-video' => __DIR__ . '/blueprints/files/bunny-video.yml',
        'files/bunny-video-fields' => __DIR__ . '/blueprints/files/bunny-video-fields.yml',
        'fields/bunnyvideo' => __DIR__ . '/blueprints/fields/bunnyvideo.yml',
    ],

    'sections' => [
        'bunny-video-upload' => require __DIR__ . '/sections/bunny-video-upload.php',
    ],

    'filePreviews' => [
        BunnyVideoPreview::class,
    ],

    'fileMethods' => [
        'bunnyThumbnail' => function (): string|false {
            /** @var File $this */
            $custom = $this->content()->customthumbnail()->toFiles()->first();
            if ($custom) {
                return $custom->url();
            }

            $videoId = $this->content()->bunnyvideoid()->value();
            if (!$videoId) {
                return false;
            }

            $bunnyData = $this->content()->bunnydata()->value();
            $data = $bunnyData ? json_decode($bunnyData, true) : [];
            $status = $data['status'] ?? 0;

            // Lazy poll: if not ready, check Bunny API
            if ($status !== BunnyStreamState::STATUS_READY) {
                try {
                    $freshData = BunnyStreamClient::instance()->getVideo($videoId);
                    if (($freshData['status'] ?? 0) === BunnyStreamState::STATUS_READY) {
                        $this->update(['bunnydata' => json_encode($freshData)]);
                        $status = BunnyStreamState::STATUS_READY;
                    }
                } catch (\Exception $e) {
                    // Use cached data on error
                }
            }

            if ($status !== BunnyStreamState::STATUS_READY) {
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
            $bunnyData = $this->content()->bunnydata()->value();
            $data = $bunnyData ? json_decode($bunnyData, true) : [];
            return $data['status'] ?? 0;
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
            return $data ? json_decode($data, true) : [];
        },

        'bunnyWidth' => function (): ?int {
            /** @var File $this */
            return $this->bunnyData()['width'] ?? null;
        },

        'bunnyHeight' => function (): ?int {
            /** @var File $this */
            return $this->bunnyData()['height'] ?? null;
        },

        'bunnyAspectRatio' => function (): float {
            /** @var File $this */
            $width = $this->bunnyWidth();
            $height = $this->bunnyHeight();
            return ($width && $height) ? $width / $height : 16 / 9;
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
            $custom = $this->content()->customthumbnail()->toFiles()->first();
            if ($custom) {
                return [
                    'src' => $custom->url(),
                    'back' => 'black',
                    'cover' => true,
                ];
            }

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
            $videoId = $this->bunnyVideoId();
            if (!$videoId) {
                return null;
            }

            $data = $this->bunnyData();
            $status = $data['status'] ?? null;

            // Fetch fresh data from API if not ready (read-only, no save)
            if ($status === null || $status !== BunnyStreamState::STATUS_READY) {
                try {
                    $freshData = BunnyStreamClient::instance()->getVideo($videoId);
                    if ($freshData) {
                        $data = $freshData;
                        $status = $freshData['status'] ?? 0;
                    }
                } catch (\Exception $e) {
                    // Use cached data on error
                }
            }

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

    'routes' => [
        [
            'pattern' => 'bunny-stream/webhook',
            'method' => 'POST',
            'action' => function () {
                return \KirbyBunny\Stream\Webhook::handle();
            },
        ],
    ],

    'api' => [
        'routes' => [
                        [
                'pattern' => 'bunny-stream/init-upload',
                'method' => 'POST',
                'action' => function () {
                    $kirby = kirby();
                    $request = $kirby->request();
                    $filename = $request->body()->get('filename');
                    $parentType = $request->body()->get('parentType');
                    $parentId = $request->body()->get('parentId');

                    if (!$filename) {
                        throw new \Exception('Filename is required');
                    }

                    // Resolve parent
                    if ($parentType === 'site') {
                        $parent = site();
                    } else {
                        $parent = page($parentId);
                        if (!$parent) {
                            throw new \Exception('Parent page not found');
                        }
                    }

                    $client = BunnyStreamClient::instance();

                    // Resolve collection
                    $collectionId = VideoUploader::resolveCollectionForParent($parent);

                    // Create video on Bunny
                    $video = $client->createVideo($filename, $collectionId);

                    if (!isset($video['guid'])) {
                        throw new \Exception('Failed to create video on Bunny');
                    }

                    $videoId = $video['guid'];

                    // Generate TUS credentials
                    $tusCredentials = $client->generateTusCredentials($videoId);

                    return [
                        'videoId' => $videoId,
                        'collectionId' => $collectionId,
                        'tusCredentials' => $tusCredentials,
                    ];
                },
            ],
            [
                'pattern' => 'bunny-stream/finalize-upload',
                'method' => 'POST',
                'action' => function () {
                    $kirby = kirby();
                    $request = $kirby->request();
                    $videoId = $request->body()->get('videoId');
                    $collectionId = $request->body()->get('collectionId');
                    $filename = $request->body()->get('filename');
                    $parentType = $request->body()->get('parentType');
                    $parentId = $request->body()->get('parentId');

                    if (!$videoId || !$filename) {
                        throw new \Exception('videoId and filename are required');
                    }

                    // Resolve parent
                    if ($parentType === 'site') {
                        $parent = site();
                    } else {
                        $parent = page($parentId);
                        if (!$parent) {
                            throw new \Exception('Parent page not found');
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
                        throw new \Exception('Failed to create file');
                    }

                    return [
                        'success' => true,
                        'file' => [
                            'filename' => $file->filename(),
                            'id' => $file->id(),
                        ],
                    ];
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
