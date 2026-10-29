# Kirby Bunny Stream

A Kirby CMS plugin for seamless video hosting via [Bunny Stream](https://bunny.net/stream/).

## Features

- **Automatic upload** – Videos are uploaded to Bunny Stream when added to the Panel
- **Direct upload** – Optional browser-to-Bunny uploads via TUS protocol for large files (bypasses PHP limits)
- **Panel preview** – Custom file preview shows embedded player when ready, processing status otherwise
- **Lazy status polling** – Automatically checks encoding status without requiring webhooks
- **HLS streaming** – Serve adaptive bitrate video via Bunny's global CDN
- **Stored thumbnails** – Optionally keep Bunny's thumbnail as a Kirby image file (srcset, lazy loading, no CDN round trip)
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
        'collectionPrefix' => null,              // Optional: see Collections section
        'thumbnailTime' => null,                 // Optional: poster frame in ms (0 = first frame)
        'storeThumbnails' => false,              // Optional: keep the thumbnail as a Kirby file, see Stored thumbnails
    ],
];
```

### Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `apiKey` | string | `null` | Bunny Stream API key (required) |
| `libraryId` | string | `null` | Video library ID (required) |
| `cdnHostname` | string | `null` | CDN hostname from Bunny dashboard (required) |
| `collectionPrefix` | string\|Closure | `null` | Prefix for Bunny collection names (see below) |
| `thumbnailTime` | int | `null` | Video time in milliseconds for the thumbnail/poster frame. `0` uses the first frame, so poster and playback start match. `null` keeps Bunny's default. Applies to new uploads only. |
| `storeThumbnails` | bool | `false` | Download the video's thumbnail into a Kirby file (template `bunny-thumbnail`) next to the video the first time it is requested after encoding finished. See [Stored thumbnails](#stored-thumbnails). |

### Collections

Videos are organized into Bunny collections based on their parent:
- **Site files**: `site`
- **Page files**: `{page-id}` (e.g., `work/project-name`)

#### Custom Collection (per blueprint/section)

Override the default collection using the `collection` option:

```yaml
# In file blueprint (site/blueprints/files/my-video.yml)
extends: files/bunny-video-fields
options:
  collection: "{{ page.parent.id }}"

# In upload section
sections:
  upload:
    type: bunny-video-upload
    collection: "all-videos"
```

Supports static strings or Kirby query syntax (`{{ page.parent.id }}`).

#### Collection Prefix (global)

Add a prefix to all collection names via config:

```php
// Static prefix
'collectionPrefix' => 'my-site',
// Result: my-site/work/project-name

// Dynamic prefix
'collectionPrefix' => function ($parent) {
    return site()->title()->value();
},
```

#### Duplicate Handling

Duplicate filenames within a collection are handled differently:
- **Direct upload (TUS)**: Blocks upload with error message
- **Standard upload**: Replaces existing video (useful for re-uploads after failures)

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
    create: false  # Disable standard upload
```

Both upload methods produce identical results – use whichever suits your needs:

| Method | Best for | Upload path |
|--------|----------|-------------|
| Standard (files section) | Small files, familiar UX | Browser → Server → Bunny |
| Direct (bunny-video-upload) | Large files (100MB+) | Browser → Bunny (TUS) |

### Stored thumbnails

Bunny serves one full-resolution `thumbnail.jpg` per video and cannot resize it. With
`'storeThumbnails' => true` the plugin downloads that image once and keeps it as a regular
Kirby image file, so it gets srcsets, lazy loading and your thumbs pipeline like any other
image:

- The file is created the first time a ready video's thumbnail is requested (Panel preview,
  status polling or a frontend render calling `bunnyThumbnail()` / `bunnyThumbnailFile()`),
  so videos that were already encoded get theirs on their next request. No webhook, no cron.
- It lands next to the video as `<video name>-thumbnail.jpg` with the template
  `bunny-thumbnail`, linked from the video by UUID (hidden field `bunnythumbnail`). Renaming
  either file keeps the pair.
- Deleting the video deletes its thumbnail. Deleting only the thumbnail makes the plugin
  fetch it again on the next request (the way to refresh it after changing the frame on Bunny).
- Editors never have to see these files: a files section or field lists them only when it
  asks for `template: bunny-thumbnail`. Note that a files section **without** a `template`
  filter lists every file of the page, thumbnails included, so filter your sections.

Show them if you want to:

```yaml
sections:
  thumbnails:
    type: files
    template: bunny-thumbnail
    create: false
```

Use them in templates:

```php
<?php if ($still = $video->bunnyThumbnailFile()): ?>
    <img src="<?= $still->url() ?>" srcset="<?= $still->srcset([320, 640, 960]) ?>" alt="">
<?php endif ?>
```

`bunnyThumbnail()` keeps returning a URL string and prefers the stored file, so existing
templates and the Panel preview benefit without changes. The default blueprint ships in
`blueprints/files/bunny-thumbnail.yml`; override it with your own
`site/blueprints/files/bunny-thumbnail.yml`.

#### Replacing the removed `customthumbnail` field (2.0)

Versions before 2.0 shipped a `customthumbnail` files field on `bunny-video`. If you want an
editor-picked override, add a files field to your own blueprint and read it in your template
before falling back to the plugin:

```yaml
# site/blueprints/files/bunny-video.yml
extends: files/bunny-video-fields
title: Video
fields:
  poster:
    type: files
    max: 1
```

```php
$poster = $video->poster()->toFile() ?? $video->bunnyThumbnailFile();
```

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
| `$file->bunnyThumbnailFile()` | `?File` | Stored thumbnail file (option `storeThumbnails`), created on first request once the video is ready |
| `$file->bunnyThumbnail()` | `string\|false` | Thumbnail URL (stored file, else CDN) |
| `$file->bunnyWidth()` | `?int` | Display width (Bunny `rotation` applied) |
| `$file->bunnyHeight()` | `?int` | Display height (Bunny `rotation` applied) |
| `$file->bunnyIsRotated()` | `bool` | Video is stored rotated by ±90° (e.g. portrait phone clips)? |
| `$file->bunnyAspectRatio()` | `?float` | Display width / height, `null` until dimensions are known |
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

## License

MIT
