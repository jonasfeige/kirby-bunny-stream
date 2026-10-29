<?php

declare(strict_types=1);

namespace KirbyBunny\Stream\Tests;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Builds a throwaway Kirby site in the system temp dir holding one bunny-video file
 * (`video.mp4`, Bunny id `abc`) on the page `test`, or on the site when $parent is 'site'.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $tmp;
    protected array $lastOptions = [];
    private int $booted = 0;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/bunny-' . uniqid();
    }

    protected function tearDown(): void
    {
        // Every App installs Whoops error/exception handlers; PHPUnit flags tests that leave them behind
        for (; $this->booted > 0; $this->booted--) {
            restore_error_handler();
            restore_exception_handler();
        }
        Dir::remove($this->tmp);
    }

    protected function app(array $options = [], string $videoStatus = '4', string $parent = 'page'): App
    {
        $content = $this->tmp . '/content';
        $folder = $parent === 'site' ? $content : $content . '/test';
        Dir::make($folder);

        if ($parent !== 'site') {
            file_put_contents($folder . '/test.txt', "Title: Test\n");
        }

        file_put_contents($folder . '/video.mp4', 'x');
        file_put_contents($folder . '/video.mp4.txt', implode("\n\n----\n\n", [
            'Template: bunny-video',
            'Bunnyvideoid: abc',
            'Bunnydata: {"status":' . $videoStatus . ',"width":1080,"height":1920,"rotation":0}',
        ]) . "\n");

        return $this->boot($options);
    }

    /**
     * A fresh App over the same roots, nothing rewritten: what the next request sees.
     */
    protected function reboot(): App
    {
        return $this->boot($this->lastOptions);
    }

    private function boot(array $options): App
    {
        $this->lastOptions = $options;
        $this->booted++;
        $content = $this->tmp . '/content';

        return new App([
            'roots' => [
                'index'    => $this->tmp,
                'content'  => $content,
                'site'     => $this->tmp . '/site',
                'media'    => $this->tmp . '/media',
                'cache'    => $this->tmp . '/cache',
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'jonasfeige.kirby-bunny-stream' => $options + [
                    'apiKey'          => 'k',
                    'libraryId'       => '1',
                    'cdnHostname'     => 'cdn.test',
                    'storeThumbnails' => true,
                ],
            ],
        ]);
    }

    /**
     * The bunny-video file of the site built by app(), wherever it lives.
     */
    protected function video(App $app): File
    {
        return $app->file('test/video.mp4') ?? $app->site()->file('video.mp4');
    }
}
