<?php

declare(strict_types=1);

namespace KirbyBunny\Stream\Tests;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Filesystem\Dir;
use KirbyBunny\Stream\BunnyStreamClient;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Builds a throwaway Kirby site in the system temp dir holding one bunny-video file
 * (`video.mp4`, Bunny id `abc`) on the page `test`, or on the site when $parent is 'site'.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $tmp;
    protected array $lastOptions = [];
    protected array $lastExtensions = [];
    private int $booted = 0;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/bunny-' . uniqid();

        // The client is a process-wide singleton built from the first App's options; every test
        // starts without one, so its own options apply
        $instance = new \ReflectionProperty(BunnyStreamClient::class, 'instance');
        $instance->setValue(null, null);
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

    /**
     * @param array $extensions  extra App props, e.g. ['hooks' => [...]]
     * @param bool  $multilang   two languages (en default, de); content files are then video.mp4.en.txt
     */
    protected function app(array $options = [], string $videoStatus = '4', string $parent = 'page', array $extensions = [], bool $multilang = false): App
    {
        $content = $this->tmp . '/content';
        $folder = $parent === 'site' ? $content : $content . '/test';
        Dir::make($folder);
        $suffix = $multilang ? '.en.txt' : '.txt';

        if ($multilang) {
            Dir::make($this->tmp . '/site/languages');
            file_put_contents($this->tmp . '/site/languages/en.php', "<?php return ['code' => 'en', 'default' => true, 'name' => 'English'];");
            file_put_contents($this->tmp . '/site/languages/de.php', "<?php return ['code' => 'de', 'name' => 'Deutsch'];");
        }

        if ($parent !== 'site') {
            file_put_contents($folder . '/test' . $suffix, "Title: Test\n");
        }

        file_put_contents($folder . '/video.mp4', 'x');
        file_put_contents($folder . '/video.mp4' . $suffix, implode("\n\n----\n\n", [
            'Template: bunny-video',
            'Bunnyvideoid: abc',
            'Bunnydata: {"status":' . $videoStatus . ',"width":1080,"height":1920,"rotation":0}',
        ]) . "\n");

        return $this->boot($options, $extensions);
    }

    /**
     * A fresh App over the same roots, nothing rewritten: what the next request sees.
     */
    protected function reboot(): App
    {
        return $this->boot($this->lastOptions, $this->lastExtensions);
    }

    private function boot(array $options, array $extensions = []): App
    {
        $this->lastOptions = $options;
        $this->lastExtensions = $extensions;
        $this->booted++;
        $content = $this->tmp . '/content';

        return new App($extensions + [
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
