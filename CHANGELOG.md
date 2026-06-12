# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-06-12

### Fixed
- Enable file options and improve preview styling

## [1.0.1] - 2026-06-11

### Added
- Collection lifecycle management (auto-cleanup of empty collections)

### Changed
- Use site-prefixed page paths for Bunny collections

## [1.0.0] - 2026-06-10

### Added
- Automatic video upload to Bunny Stream on file creation
- Custom Panel file preview with embedded player
- Lazy status polling for encoding progress
- HLS streaming URL generation
- MP4 direct URL generation with resolution parameter
- Custom thumbnail support
- File methods: `bunnyVideoId()`, `bunnyHlsUrl()`, `bunnyMp4Url()`, `bunnyThumbnail()`, `bunnyWidth()`, `bunnyHeight()`, `bunnyAspectRatio()`, `bunnyDuration()`, `bunnyStatus()`, `bunnyData()`, `isBunnyReady()`, `isBunnyProcessing()`
- Page methods: `bunnyVideos()`
- Extensible blueprints via `files/bunny-video-fields`
- Optional webhook support for instant status updates
- Automatic video deletion from Bunny when file is deleted
- Video title sync on file rename
- Video replacement support
