# kirby-bunny-stream

A Kirby CMS plugin for seamless video hosting via [Bunny Stream](https://bunny.net/stream/).

## Requirements

- Kirby 5.0+
- PHP 8.1+

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

## Bunny Security Settings

The plugin fetches video thumbnails directly from Bunny's CDN. Depending on your Bunny library's security settings, you may need to configure access.

### Recommended Setup

In your Bunny Stream dashboard, go to your library settings and configure:

#### Option 1: Disable direct URL blocking (simplest)

- Set **"Block direct url file access"** to **OFF**

This allows thumbnails to load from any source. Videos are still protected by Bunny's standard security.

#### Option 2: Allow your domains (more restrictive)

- Keep **"Block direct url file access"** **ON**
- Add your domains to **"Allowed domains"**:
  - `yourdomain.com` (production)
  - `*.yourdomain.com` (subdomains)
  - `localhost` (local development)

This restricts thumbnail access to requests originating from your domains.

### Not Supported

The plugin does **not** currently support:
- **CDN token authentication** (signed URLs)
- **Embed view token authentication**

If you enable these features in Bunny, thumbnails will not load in the Panel or on your frontend.

## Usage

### Blueprint Sections

#### Using the preset section

The plugin provides a ready-to-use section that shows only videos that have finished encoding:

```yaml
sections:
  videos:
    extends: sections/bunnyvideos
    label: My Videos
```

#### Using the standard files section

For full control, use a regular files section with the `bunny-video` template:

```yaml
sections:
  videos:
    type: files
    template: bunny-video
    label: Videos
```

### Blueprint Fields

#### Using the preset field

Select only videos that are ready for playback:

```yaml
fields:
  video:
    extends: fields/bunnyvideo
    label: Select Video
```

#### Using the standard files field

For custom filtering or to include processing videos:

```yaml
fields:
  video:
    type: files
    query: page.files.template("bunny-video")
    max: 1
```

### Custom File Blueprints

To add custom fields to Bunny videos, create your own blueprint that extends the base:

```yaml
# site/blueprints/files/bunny-video.yml
extends: files/bunny-video-fields

title: Project Video

image:
  src: "{{ file.bunnyThumbnail }}"
  icon: loader
  cover: true
  back: yellow-500

accept:
  mime:
    - video/*

fields:
  caption:
    type: text
    label: Caption

  credits:
    type: text
    label: Credits
```

The `files/bunny-video-fields` blueprint includes the required hidden fields for Bunny metadata. Your custom blueprint inherits these automatically.

### Filtering Videos

The plugin adds helper methods for filtering videos by encoding status.

#### In blueprints (query language)

Show only ready videos:
```yaml
query: page.files.template("bunny-video").filter(file => file.isBunnyReady)
```

Show only processing videos:
```yaml
query: page.files.template("bunny-video").filter(file => file.isBunnyProcessing)
```

Show all bunny videos:
```yaml
query: page.files.template("bunny-video")
```

#### In templates (PHP)

```php
// Get ready videos from current page
$readyVideos = $page->bunnyVideos();

// Get all videos including processing ones
$allVideos = $page->bunnyVideos(readyOnly: false);

// Manual filtering
$videos = $page->files()->template('bunny-video');
$ready = $videos->filter(fn($f) => $f->isBunnyReady());
$processing = $videos->filter(fn($f) => $f->isBunnyProcessing());
```

### In Templates

```php
<?php if ($video = $page->video()->toFile()): ?>
  <?php if ($video->isBunnyReady()): ?>
    <video
      poster="<?= $video->bunnyThumbnail() ?>"
      src="<?= $video->bunnyHlsUrl() ?>"
    ></video>
  <?php else: ?>
    <p>Video is processing...</p>
  <?php endif ?>
<?php endif ?>
```

### Available File Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `$file->bunnyVideoId()` | `?string` | Bunny video GUID |
| `$file->bunnyHlsUrl()` | `?string` | HLS stream URL |
| `$file->bunnyMp4Url($resolution)` | `?string` | Direct MP4 URL (480, 720, 1080, etc.) |
| `$file->bunnyThumbnail()` | `?string` | Thumbnail URL (only when ready) |
| `$file->bunnyWidth()` | `?int` | Video width |
| `$file->bunnyHeight()` | `?int` | Video height |
| `$file->bunnyAspectRatio()` | `float` | Width / height (defaults to 16/9) |
| `$file->bunnyDuration()` | `?int` | Video length in seconds |
| `$file->bunnyStatus()` | `int` | Bunny status code (0-6) |
| `$file->bunnyData()` | `array` | Raw Bunny metadata |
| `$file->isBunnyReady()` | `bool` | Encoding complete (status 4)? |
| `$file->isBunnyProcessing()` | `bool` | Still encoding (status 0-3)? |

### Available Page Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `$page->bunnyVideos()` | `Files` | Ready videos on this page |
| `$page->bunnyVideos(false)` | `Files` | All videos including processing |

### Bunny Status Codes

| Code | Status |
|------|--------|
| 0 | Queued |
| 1 | Processing |
| 2 | Encoding |
| 3 | Finished |
| 4 | Ready (playable) |
| 5 | Error |
| 6 | Upload failed |

## Panel Preview

The plugin includes a custom Panel file preview that shows:
- **Processing**: Animated loader icon with encoding progress
- **Ready**: Embedded video player with HLS playback

## Webhook Setup (Optional)

For instant metadata updates when encoding completes:

1. In Bunny dashboard, go to Stream > Your Library > Webhooks
2. Add webhook URL: `https://yoursite.com/bunny-stream/webhook`
3. Copy the webhook secret to your config

Without webhooks, metadata updates lazily when the file is accessed.

## License

MIT
