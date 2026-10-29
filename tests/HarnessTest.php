<?php

declare(strict_types=1);

namespace KirbyBunny\Stream\Tests;

final class HarnessTest extends TestCase
{
    public function testVideoFileIsReady(): void
    {
        $this->assertTrue($this->video($this->app())->isBunnyReady());
    }

    public function testVideoFileOnSiteIsReady(): void
    {
        $this->assertTrue($this->video($this->app([], '4', 'site'))->isBunnyReady());
    }

    public function testPluginOptionIsRead(): void
    {
        $this->assertTrue($this->app()->option('jonasfeige.kirby-bunny-stream.storeThumbnails'));
    }
}
