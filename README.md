# Kirby Bunny Stream

A Kirby CMS plugin for seamless video hosting via [Bunny Stream](https://bunny.net/stream/).

## Features

- **Automatic upload** – Videos are uploaded to Bunny Stream when added to the Panel
- **Direct upload** – Optional browser-to-Bunny uploads via TUS protocol for large files (bypasses PHP limits)
- **Panel preview** – Custom file preview shows embedded player when ready, processing status otherwise
- **Lazy status polling** – Automatically checks encoding status without requiring webhooks
- **HLS streaming** – Serve adaptive bitrate video via Bunny's global CDN
- **Custom thumbnails** – Override auto-generated thumbnails with your own images
- **Extensible blueprints** – Add custom fields while keeping core functionality

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

### Adding Videos

Create a files section in your page blueprint:

```yaml
sections:
  videos:
    type: files
    template: bunny-video
    label: Videos
```

Upload a video and it will automatically be sent to Bunny Stream. The original file is replaced with a small placeholder while Bunny handles storage and delivery.

### Direct Upload (Large Files)

For large files that exceed PHP upload limits, use the direct upload section. This uploads directly from the browser to Bunny via the TUS protocol, bypassing your server entirely.

Add to your page blueprint:

```yaml
sections:
  video-upload:
    type: bunny-video-upload
    label: Upload Video
    help: Supports files up to 10GB

  videos:
    type: files
    template: bunny-video
    upload: false  # Disable standard upload
```

Both upload methods produce identical results – use whichever suits your needs:

| Method | Best for | Upload path |
|--------|----------|-------------|
| Standard (files section) | Small files, familiar UX | Browser → Server → Bunny |
| Direct (bunny-video-upload) | Large files (100MB+) | Browser → Bunny (TUS) |

### Custom Thumbnails

The default blueprint includes a `customthumbnail` field. Upload an image to override Bunny's auto-generated thumbnail. The `bunnyThumbnail()` method automatically returns the custom thumbnail if set.

### Selecting Videos

Use the preset field to select only videos that have finished encoding:

```yaml
fields:
  video:
    extends: fields/bunnyvideo
    label: Select Video
```

Or use a standard files field:

```yaml
fields:
  video:
    type: files
    query: page.files.template("bunny-video")
    max: 1
```

### Filtering by Status

Filter videos by encoding status in blueprints:

```yaml
# Only ready videos
query: page.files.template("bunny-video").filter(file => file.isBunnyReady)

# Only processing videos
query: page.files.template("bunny-video").filter(file => file.isBunnyProcessing)
```

Or in PHP:

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

### Custom Blueprints

To add custom fields, create your own blueprint that extends the base:

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
  credits:
    type: text
```

The `files/bunny-video-fields` blueprint includes the required hidden fields for Bunny metadata.

## File Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `$file->bunnyVideoId()` | `?string` | Bunny video GUID |
| `$file->bunnyHlsUrl()` | `?string` | HLS stream URL |
| `$file->bunnyMp4Url($resolution)` | `?string` | Direct MP4 URL (480, 720, 1080, etc.) |
| `$file->bunnyThumbnail()` | `?string` | Thumbnail URL (custom or auto) |
| `$file->bunnyWidth()` | `?int` | Video width |
| `$file->bunnyHeight()` | `?int` | Video height |
| `$file->bunnyAspectRatio()` | `float` | Width / height (defaults to 16/9) |
| `$file->bunnyDuration()` | `?int` | Video length in seconds |
| `$file->bunnyStatus()` | `int` | Bunny status code (0-6) |
| `$file->bunnyData()` | `array` | Raw Bunny metadata |
| `$file->isBunnyReady()` | `bool` | Encoding complete (status 4)? |
| `$file->isBunnyProcessing()` | `bool` | Still encoding (status 0-3)? |

## Page Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `$page->bunnyVideos()` | `Files` | Ready videos on this page |
| `$page->bunnyVideos(false)` | `Files` | All videos including processing |

## Status Codes

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
- **Processing**: Spinner with current status
- **Ready**: Embedded video player

## Webhook Setup (Optional)

For instant metadata updates when encoding completes:

1. In Bunny dashboard, go to Stream > Your Library > Webhooks
2. Add webhook URL: `https://yoursite.com/bunny-stream/webhook`
3. Copy the webhook secret to your config

Without webhooks, metadata updates lazily when the file is accessed in the Panel.

## License

MIT
