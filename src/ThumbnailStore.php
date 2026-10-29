<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\DuplicateException;
use Kirby\Http\Remote;
use Throwable;

/**
 * Keeps Bunny's thumbnail as a Kirby image file next to the video (option `storeThumbnails`).
 *
 * The file is created once, the first time a ready video's thumbnail is asked for, from
 * whichever request gets there first (Panel preview, upload polling, frontend render). It is
 * linked from the video's content (`bunnythumbnail`, the thumbnail's UUID), so renaming
 * either file keeps the pair; a deleted thumbnail is simply fetched again.
 *
 * The link is written straight into the content file (like `bunnyPersistData`) rather than
 * through File::update(), so a stale in-memory `bunnydata` is never written back. Within one
 * request the result is cached per video, because the video object's content is stale after
 * that direct write.
 */
final class ThumbnailStore
{
    public const TEMPLATE = 'bunny-thumbnail';

    /** @var array<string, File|null> video id → thumbnail (null after a failed download this request) */
    private static array $cache = [];

    public static function enabled(): bool
    {
        return App::instance()->option('jonasfeige.kirby-bunny-stream.storeThumbnails', false) === true;
    }

    public static function filename(File $video): string
    {
        return $video->name() . '-thumbnail.jpg';
    }

    /**
     * The linked thumbnail, if the link resolves to an existing file.
     */
    public static function find(File $video): ?File
    {
        if (array_key_exists($video->id(), self::$cache)) {
            return self::$cache[$video->id()];
        }

        $thumb = $video->content()->bunnythumbnail()->toFile();

        return $thumb?->exists() ? $thumb : null;
    }

    /**
     * The stored thumbnail, downloading and creating it when missing. Null when the option is
     * off, the video is not ready, or the download failed (nothing is written then; the next
     * call retries).
     *
     * @param callable(string $url, string $target): bool|null $download  defaults to self::download()
     */
    public static function ensure(File $video, ?callable $download = null): ?File
    {
        if (self::enabled() === false) {
            return null;
        }

        if ($found = self::find($video)) {
            return self::$cache[$video->id()] = $found;
        }

        $videoId = $video->bunnyVideoId();

        if (!$videoId || $video->isBunnyReady() === false) {
            return null;
        }

        $url = BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg");
        $tmp = tempnam(sys_get_temp_dir(), 'bunny-thumb-');

        if (($download ?? [self::class, 'download'])($url, $tmp) !== true) {
            @unlink($tmp);
            return self::$cache[$video->id()] = null;
        }

        $thumb = $video->kirby()->impersonate('kirby', function () use ($video, $tmp): ?File {
            $parent = $video->parent();
            $filename = self::filename($video);

            try {
                return $parent->createFile([
                    'source'   => $tmp,
                    'filename' => $filename,
                    'template' => self::TEMPLATE,
                    'content'  => ['bunnyvideo' => $video->uuid()->toString()],
                ], true);
            } catch (DuplicateException) {
                // A parallel request created it between our lookup and the create
                return $parent->file($filename) ?? new File(['filename' => $filename, 'parent' => $parent]);
            }
        });

        @unlink($tmp);

        if ($thumb === null) {
            return self::$cache[$video->id()] = null;
        }

        $video->bunnyPersistField('Bunnythumbnail', $thumb->uuid()->toString());

        return self::$cache[$video->id()] = $thumb;
    }

    /**
     * Default downloader: writes the CDN response to $target, true only for a JPEG body.
     */
    public static function download(string $url, string $target): bool
    {
        try {
            $response = Remote::get($url, ['timeout' => 10]);
        } catch (Throwable) {
            return false;
        }

        if ($response->code() !== 200 || !($body = $response->content())) {
            return false;
        }

        return file_put_contents($target, $body) !== false && self::isJpeg($target);
    }

    public static function isJpeg(string $path): bool
    {
        $info = @getimagesize($path);

        return is_array($info) && ($info['mime'] ?? null) === 'image/jpeg';
    }

    /**
     * Deletes the video's stored thumbnail, if any.
     */
    public static function delete(File $video): void
    {
        if ($thumb = self::find($video)) {
            $video->kirby()->impersonate('kirby', fn () => $thumb->delete());
        }

        unset(self::$cache[$video->id()]);
    }

    /**
     * Clears the per-request cache (tests, long-running CLI runs).
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
