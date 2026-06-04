# kirby-bunny-stream

A Kirby CMS plugin for seamless video hosting via [Bunny Stream](https://bunny.net/stream/).

## Installation

### Composer (recommended)

```bash
composer require jonasfeige/kirby-bunny-stream
```

### Manual

Download and extract to `site/plugins/kirby-bunny-stream`.

## Configuration

Add to your `site/config/config.php`:

```php
return [
    'jonasfeige.kirby-bunny-stream' => [
        'apiKey' => 'your-bunny-api-key',       // Required
        'libraryId' => 'your-library-id',        // Required
        'cdnHostname' => 'vz-xxx.b-cdn.net',     // Required: from Bunny dashboard
        'webhookSecret' => null,                 // Optional
        'collection' => 'site',                  // 'site' or 'page'
    ],
];
```

### Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `apiKey` | string | `null` | Bunny Stream API key (required) |
| `libraryId` | string | `null` | Video library ID (required) |
| `cdnHostname` | string | `null` | CDN hostname from Bunny dashboard (required) |
| `webhookSecret` | string | `null` | Webhook signature verification secret |
| `collection` | string | `'site'` | Video organization: `'site'` or `'page'` |

## Usage

### In Blueprints

```yaml
sections:
  videos:
    type: files
    template: bunny-video
    label: Videos
```

### In Templates

```php
<?php if ($video = $page->video()->toFile()): ?>
  <?php if ($video->isReady()): ?>
    <video
      poster="<?= $video->thumbnailUrl() ?>"
      data-hls="<?= $video->hlsUrl() ?>"
    ></video>
  <?php else: ?>
    <p>Video is processing (<?= $video->encodingProgress() ?>%)</p>
  <?php endif ?>
<?php endif ?>
```

### Available Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `$file->videoId()` | `?string` | Bunny video GUID |
| `$file->hlsUrl()` | `?string` | HLS stream URL |
| `$file->mp4Url($resolution)` | `?string` | Direct MP4 URL (480, 720, 1080, etc.) |
| `$file->thumbnailUrl()` | `string` | Thumbnail URL (custom or auto) |
| `$file->posterUrl()` | `string` | Alias for thumbnailUrl() |
| `$file->previewUrl()` | `?string` | Animated preview URL |
| `$file->duration()` | `?int` | Video length in seconds |
| `$file->width()` | `?int` | Video width |
| `$file->height()` | `?int` | Video height |
| `$file->aspectRatio()` | `?float` | Width / height |
| `$file->isReady()` | `bool` | Encoding complete? |
| `$file->encodingProgress()` | `?int` | 0-100 during encoding |
| `$file->status()` | `int` | Bunny status code |
| `$file->bunnyData()` | `array` | Raw metadata |

## Webhook Setup (Optional)

For instant thumbnail updates when encoding completes:

1. In Bunny dashboard, go to Stream > Your Library > Webhooks
2. Add webhook URL: `https://yoursite.com/bunny-stream/webhook`
3. Copy the webhook secret to your config

Without webhooks, thumbnails update lazily when the file is accessed.

## License

MIT
