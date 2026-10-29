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
     * The linked thumbnail, if the link resolves to an existing bunny-thumbnail file.
     */
    public static function find(File $video): ?File
    {
        if (array_key_exists($video->id(), self::$cache)) {
            return self::$cache[$video->id()];
        }

        $thumb = $video->content()->bunnythumbnail()->toFile();

        return $thumb?->exists() && $thumb->template() === self::TEMPLATE ? $thumb : null;
    }

    /**
     * The stored thumbnail, downloading and creating it when missing. Null when the option is
     * off, the video is not ready, or the download or the file creation failed (nothing is
     * written then; the next request retries, this one does not).
     *
     * @param callable(string $url, string $target): bool|null $download  defaults to self::download()
     */
    public static function ensure(File $video, ?callable $download = null): ?File
    {
        if (self::enabled() === false) {
            return null;
        }

        if (array_key_exists($video->id(), self::$cache)) {
            return self::$cache[$video->id()];
        }

        if ($found = self::find($video)) {
            return self::$cache[$video->id()] = $found;
        }

        $videoId = $video->bunnyVideoId();

        if (!$videoId || $video->isBunnyReady() === false) {
            return null;
        }

        // Another request may have created it since this one loaded the parent's files
        if ($adopted = self::adopt($video)) {
            return self::link($video, $adopted);
        }

        try {
            $url = BunnyStreamClient::instance()->cdnUrl("/{$videoId}/thumbnail.jpg");
            $tmp = tempnam(sys_get_temp_dir(), 'bunny-thumb-');
        } catch (Throwable) {
            return self::$cache[$video->id()] = null;
        }

        if ($tmp === false || ($download ?? [self::class, 'download'])($url, $tmp) !== true) {
            if ($tmp) {
                @unlink($tmp);
            }
            return self::$cache[$video->id()] = null;
        }

        try {
            $thumb = $video->kirby()->impersonate('kirby', fn (): File => $video->parent()->createFile([
                'source'   => $tmp,
                'filename' => self::filename($video),
                'template' => self::TEMPLATE,
                'content'  => ['bunnyvideo' => $video->uuid()->toString()],
            ], true));
        } catch (Throwable) {
            // DuplicateException or Kirby's same-bytes path (which fails on a stale files
            // collection), a hook, disk or permission error: adopt a parallel request's file,
            // else leave nothing behind
            $thumb = self::adopt($video);
        } finally {
            @unlink($tmp);
        }

        if ($thumb === null) {
            return self::$cache[$video->id()] = null;
        }

        return self::link($video, $thumb);
    }

    /**
     * The thumbnail file another request created for this video: present on disk, template
     * bunny-thumbnail and pointing back at this video. Anything else with that name (an
     * editor's own image) is left alone.
     */
    private static function adopt(File $video): ?File
    {
        $parent = $video->parent();
        $filename = self::filename($video);

        if (is_file($parent->root() . '/' . $filename) === false) {
            return null;
        }

        try {
            // The parent's files collection may predate the file, so build it directly
            $file = $parent->file($filename) ?? new File(['filename' => $filename, 'parent' => $parent]);

            $ours = $file->template() === self::TEMPLATE
                && $file->content()->bunnyvideo()->value() === $video->uuid()->toString();
        } catch (Throwable) {
            return null;
        }

        return $ours ? $file : null;
    }

    private static function link(File $video, File $thumb): File
    {
        $video->bunnyPersistField('Bunnythumbnail', $thumb->uuid()->toString());

        return self::$cache[$video->id()] = $thumb;
    }

    /**
     * Default downloader: writes the CDN response to $target, true only for a JPEG body.
     */
    public static function download(string $url, string $target): bool
    {
        try {
            $response = Remote::get($url, ['timeout' => 5]);
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
     * Deletes the video's stored thumbnail, if any (only a bunny-thumbnail file, see find()).
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
