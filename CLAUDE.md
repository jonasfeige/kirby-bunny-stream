# CLAUDE.md - kirby-bunny-stream

A Kirby 5 plugin for Bunny Stream video hosting. Videos are uploaded to Bunny on file creation, replaced with tiny placeholders locally, and served via Bunny's CDN.

## Quick Reference

```bash
# Build Vue component for Panel
npm run dev    # Watch mode
npm run build  # Production build

# Debug with Ray
ray($message)->label('bunny-stream');
```

## Architecture Decisions

### Why fileMethods, not fileModels

**Kirby does NOT support `fileModels` as a plugin extension.** Only `pageModels`, `blockModels`, and `userModels` exist. We tried registering `fileModels` but Kirby silently ignores it.

The solution: use `fileMethods` with explicit `bunny` prefixes:
- `bunnyVideoId()`, `bunnyHlsUrl()`, `bunnyThumbnail()`, etc.
- Cannot override core methods like `width()` via fileMethods
- Explicit naming avoids conflicts and is clearer for users

### Why fileMethods over custom File subclass

Custom File subclasses ARE possible (extend `Kirby\Cms\File`), but require:
- Overriding `files()` method in every page model that uses them
- Manual wiring per page type

For an open-source plugin that should work on any page type without configuration, `fileMethods` is the right choice.

### Blueprint Extensibility

Users may want to add custom fields. We provide:
- `files/bunny-video-fields` - base blueprint with required hidden fields
- `files/bunny-video` - default blueprint that extends it

Users create their own `site/blueprints/files/bunny-video.yml` extending `files/bunny-video-fields` to add custom fields while keeping required metadata.

## Key Files

```
index.php                     # Plugin registration, hooks, fileMethods
src/
  BunnyStreamClient.php       # API client (upload, delete, getVideo, cdnUrl)
  BunnyVideoPreview.php       # Panel file preview (accepts, props, details)
  Webhook.php                 # Optional webhook handler for instant updates
  components/
    BunnyVideoPreview.vue     # Vue component for Panel preview
  index.js                    # Vue component registration
blueprints/
  files/
    bunny-video.yml           # Default file blueprint
    bunny-video-fields.yml    # Base blueprint for extending
  fields/
    bunnyvideo.yml            # Preset field (selects only ready videos)
```

## How It Works

### Upload Flow

1. User uploads video in Panel → `file.create:after` hook fires
2. Hook checks: is template `bunny-video`? is type `video`?
3. `processVideoUpload()` calls `BunnyStreamClient::upload()`
4. On success: update file metadata with `bunnyvideoid`, `bunnydata`
5. Replace original video file with tiny placeholder (`BUNNY:{videoId}`)
6. Bunny handles storage and CDN delivery

### Status Polling (Lazy)

Without webhooks, status updates happen lazily:
- `bunnyThumbnail()` checks stored status
- If not ready (status !== 4), polls Bunny API
- Updates `bunnydata` if status changed to ready
- Panel preview does the same in `BunnyVideoPreview::props()`

### Panel Preview

`BunnyVideoPreview` extends `Kirby\Panel\Ui\FilePreview`:
- `accepts()` - returns true for `bunny-video` template
- `props()` - returns data for Vue component (embedUrl, status, dimensions)
- Vue component shows spinner when processing, embedded player when ready

## File Metadata

Stored in file's content (txt file):
```yaml
Bunnyvideoid: abc-123-def
Bunnycollectionid: xyz-789
Bunnydata: {"status":4,"width":1920,"height":1080,...}
Customthumbnail: - my-thumb.jpg
```

## Bunny Status Codes

| Code | Meaning |
|------|---------|
| 0 | Queued |
| 1 | Processing |
| 2 | Encoding |
| 3 | Finished |
| 4 | Ready ✓ |
| 5 | Error |
| 6 | Upload failed |

## Debugging

Always use Ray for debugging:
```php
ray($variable)->label('bunny-stream');
bunny_debug("message");  // Helper function in index.php
```

Use Kirby MCP tools for documentation questions:
- `kirby_online` - search official docs
- `kirby_search` - search local knowledge base

## Common Issues

### Thumbnails return 403 Forbidden

Bunny's "Block direct url file access" is enabled. Either:
1. Disable it in Bunny dashboard, OR
2. Add your domains to "Allowed domains"

Token authentication is NOT supported.

### Panel preview stays on spinner

Status not updating. Check:
1. Is video actually finished in Bunny dashboard?
2. Is `bunnydata` being updated? (lazy poll should handle this)
3. Check Ray logs for API errors

### Hook runs multiple times

The `$GLOBALS['bunny_stream_processing']` flag prevents re-entrant calls. If you see duplicate processing, check that flag is being set/unset correctly.

## Config Options

```php
'jonasfeige.kirby-bunny-stream' => [
    'apiKey' => '...',           // Required: Bunny API key
    'libraryId' => '...',        // Required: Video library ID
    'cdnHostname' => '...',      // Required: e.g., vz-xxx.b-cdn.net
    'webhookSecret' => null,     // Optional: for webhook verification
]
```

### Collections

Videos are automatically organized into Bunny collections, prefixed with the site slug:
- **Site files**: `{site-slug}/site` (e.g., `bastianthiery/site`)
- **Page files**: `{site-slug}/{page-path}` (e.g., `bastianthiery/work/some-project`)

Duplicate detection (same filename) operates within each collection.

## TODO / Future Improvements

- [ ] Support token authentication for private videos
- [ ] Add encoding progress indicator (requires webhooks or polling)
- [ ] Consider adding `file.update:after` hook for re-uploading replaced videos
- [ ] Add CLI command for bulk migration of existing videos
