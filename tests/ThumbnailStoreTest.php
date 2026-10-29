<?php

declare(strict_types=1);

namespace KirbyBunny\Stream\Tests;

use Kirby\Cms\App;
use KirbyBunny\Stream\ThumbnailStore;

final class ThumbnailStoreTest extends TestCase
{
    /** @var string[] */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        ThumbnailStore::flush();
    }

    private function fake(bool $ok = true, ?string $bytes = null): callable
    {
        return function (string $url, string $target) use ($ok, $bytes): bool {
            $this->calls[] = $url;
            file_put_contents($target, $bytes ?? file_get_contents(__DIR__ . '/fixtures/thumbnail.jpg'));
            return $ok;
        };
    }

    private function videoTxt(string $parent = 'page'): string
    {
        return file_get_contents($this->tmp . '/content/' . ($parent === 'site' ? '' : 'test/') . 'video.mp4.txt');
    }

    private function thumbPath(string $parent = 'page'): string
    {
        return $this->tmp . '/content/' . ($parent === 'site' ? '' : 'test/') . 'video-thumbnail.jpg';
    }

    public function testFilename(): void
    {
        $this->assertSame('video-thumbnail.jpg', ThumbnailStore::filename($this->video($this->app())));
    }

    public function testEnsureReturnsNullWhenOptionOff(): void
    {
        $video = $this->video($this->app(['storeThumbnails' => false]));

        $this->assertNull(ThumbnailStore::ensure($video, $this->fake()));
        $this->assertSame([], $this->calls);
    }

    public function testEnsureReturnsNullWhenVideoNotReady(): void
    {
        $video = $this->video($this->app([], '2'));

        $this->assertNull(ThumbnailStore::ensure($video, $this->fake()));
        $this->assertSame([], $this->calls);
        $this->assertFileDoesNotExist($this->thumbPath());
    }

    public function testEnsureCreatesThumbnailFile(): void
    {
        $video = $this->video($this->app());

        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertSame(['https://cdn.test/abc/thumbnail.jpg'], $this->calls);
        $this->assertNotNull($thumb);
        $this->assertSame('video-thumbnail.jpg', $thumb->filename());
        $this->assertFileExists($this->thumbPath());
        $this->assertFileEquals(__DIR__ . '/fixtures/thumbnail.jpg', $this->thumbPath());

        $thumbTxt = file_get_contents($this->thumbPath() . '.txt');
        $this->assertStringContainsString('Template: bunny-thumbnail', $thumbTxt);
        $this->assertStringContainsString('Bunnyvideo: ' . $video->uuid()->toString(), $thumbTxt);

        $videoTxt = $this->videoTxt();
        $this->assertStringContainsString('Bunnythumbnail: ' . $thumb->uuid()->toString(), $videoTxt);
        $this->assertStringContainsString('"status":4', $videoTxt);
    }

    public function testEnsureReturnsExistingWithoutDownloading(): void
    {
        $first = ThumbnailStore::ensure($this->video($this->app()), $this->fake());
        $this->calls = [];
        ThumbnailStore::flush();

        $again = ThumbnailStore::ensure($this->video($this->reboot()), $this->fake());

        $this->assertSame([], $this->calls);
        $this->assertSame($first->uuid()->toString(), $again->uuid()->toString());
    }

    public function testEnsureRefetchesWhenLinkedFileIsMissing(): void
    {
        $firstUuid = ThumbnailStore::ensure($this->video($this->app()), $this->fake())->uuid()->toString(); // read now: content is lazy
        unlink($this->thumbPath());
        unlink($this->thumbPath() . '.txt');
        $this->calls = [];
        ThumbnailStore::flush();

        $again = ThumbnailStore::ensure($this->video($this->reboot()), $this->fake());

        $this->assertCount(1, $this->calls);
        $this->assertFileExists($this->thumbPath());
        $this->assertNotSame($firstUuid, $again->uuid()->toString());
        $this->assertStringContainsString('Bunnythumbnail: ' . $again->uuid()->toString(), $this->videoTxt());
    }

    public function testEnsureReturnsNullWhenDownloadFails(): void
    {
        $video = $this->video($this->app());

        $this->assertNull(ThumbnailStore::ensure($video, $this->fake(false)));
        $this->assertFileDoesNotExist($this->thumbPath());
        $this->assertStringNotContainsString('Bunnythumbnail', $this->videoTxt());
    }

    public function testEnsureRejectsNonImageDownload(): void
    {
        $video = $this->video($this->app());
        $download = fn (string $url, string $target): bool => file_put_contents($target, '<html>404</html>') && ThumbnailStore::isJpeg($target);

        $this->assertNull(ThumbnailStore::ensure($video, $download));
        $this->assertFileDoesNotExist($this->thumbPath());
    }

    public function testIsJpegAcceptsTheFixtureOnly(): void
    {
        $this->assertTrue(ThumbnailStore::isJpeg(__DIR__ . '/fixtures/thumbnail.jpg'));
        $this->assertFalse(ThumbnailStore::isJpeg(__FILE__));
    }

    public function testEnsureLinksExistingFileOnDuplicate(): void
    {
        $app = $this->app();
        file_put_contents($this->thumbPath(), 'written by a parallel request');
        $video = $this->video($app);

        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertNotNull($thumb);
        $this->assertSame('video-thumbnail.jpg', $thumb->filename());
        $this->assertSame('written by a parallel request', file_get_contents($this->thumbPath()));
        $this->assertStringContainsString('Bunnythumbnail: ' . $thumb->uuid()->toString(), $this->videoTxt());
    }

    public function testEnsureWorksForSiteFiles(): void
    {
        $video = $this->video($this->app([], '4', 'site'));

        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertNotNull($thumb);
        $this->assertFileExists($this->thumbPath('site'));
        $this->assertStringContainsString('Bunnythumbnail: ' . $thumb->uuid()->toString(), $this->videoTxt('site'));
    }

    public function testDeleteRemovesLinkedThumbnail(): void
    {
        $video = $this->video($this->app());
        ThumbnailStore::ensure($video, $this->fake());

        ThumbnailStore::delete($video);

        $this->assertFileDoesNotExist($this->thumbPath());
        $this->assertFileDoesNotExist($this->thumbPath() . '.txt');
    }

    public function testDeleteIsANoopWithoutThumbnail(): void
    {
        $video = $this->video($this->app());

        ThumbnailStore::delete($video);

        $this->assertInstanceOf(App::class, $video->kirby());
    }
}
