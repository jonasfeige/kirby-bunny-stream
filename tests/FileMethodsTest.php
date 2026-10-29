<?php

declare(strict_types=1);

namespace KirbyBunny\Stream\Tests;

use KirbyBunny\Stream\ThumbnailStore;

final class FileMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThumbnailStore::flush();
    }

    private function fake(): callable
    {
        return function (string $url, string $target): bool {
            file_put_contents($target, file_get_contents(__DIR__ . '/fixtures/thumbnail.jpg'));
            return true;
        };
    }

    public function testBunnyThumbnailFileIsNullWhenOptionOff(): void
    {
        $this->assertNull($this->video($this->app(['storeThumbnails' => false]))->bunnyThumbnailFile());
    }

    public function testBunnyThumbnailReturnsCdnUrlWithoutStoredFile(): void
    {
        $video = $this->video($this->app(['storeThumbnails' => false]));

        $this->assertSame('https://cdn.test/abc/thumbnail.jpg', $video->bunnyThumbnail());
    }

    public function testBunnyThumbnailFileReturnsTheStoredFile(): void
    {
        $video = $this->video($this->app());
        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertSame($thumb->uuid()->toString(), $video->bunnyThumbnailFile()->uuid()->toString());
    }

    public function testBunnyThumbnailPrefersStoredFile(): void
    {
        $video = $this->video($this->app());
        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertSame($thumb->url(), $video->bunnyThumbnail());
        $this->assertStringNotContainsString('cdn.test', $video->bunnyThumbnail());
    }

    public function testBunnyPanelImageUsesStoredFile(): void
    {
        $video = $this->video($this->app());
        $thumb = ThumbnailStore::ensure($video, $this->fake());

        $this->assertSame(['src' => $thumb->url(), 'back' => 'black', 'cover' => true], $video->bunnyPanelImage());
    }

    public function testThumbnailBlueprintIsRegistered(): void
    {
        $thumb = ThumbnailStore::ensure($this->video($this->app()), $this->fake());

        $this->assertSame('bunny-thumbnail', $thumb->template());
        $this->assertSame('Bunny Thumbnail', $thumb->blueprint()->title());
        $this->assertArrayHasKey('bunnyvideo', $thumb->blueprint()->fields());
    }

    public function testVideoBlueprintHasNoCustomThumbnailField(): void
    {
        $fields = $this->video($this->app())->blueprint()->fields();

        $this->assertArrayNotHasKey('customthumbnail', $fields);
        $this->assertArrayHasKey('bunnythumbnail', $fields);
    }
}
