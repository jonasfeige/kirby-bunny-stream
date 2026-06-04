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

    'filePreviews' => [
        BunnyVideoPreview::class,
    ],

    'fileMethods' => [
        'bunnyThumbnail' => function (): ?string {
            /** @var File $this */
            $custom = $this->content()->customthumbnail()->toFiles()->first();
            if ($custom) {
                return $custom->url();
            }

            $videoId = $this->content()->bunnyvideoid()->value();
            if (!$videoId) {
                return null;
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
                return null;
            }

            try {
                return BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg");
            } catch (\Exception $e) {
                return null;
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
        'file.create:after' => function (File $file) {
            if (BunnyStreamState::$processing) {
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
            if (!$videoId) {
                return;
            }

            // Attempt to delete from Bunny, ignore failures
            try {
                BunnyStreamClient::instance()->delete($videoId);
            } catch (\Exception $e) {
                // Don't block local deletion if Bunny delete fails
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
