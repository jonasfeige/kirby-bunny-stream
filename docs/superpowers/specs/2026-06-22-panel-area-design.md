# Bunny Stream Panel Area Design

**Date**: 2026-06-22
**Status**: Draft
**Goal**: Add a Panel area to display video inventory stats and usage information

## Summary

Add a "Bunny Stream" Panel area to the left sidebar that shows:
- Summary cards (total videos, storage, status breakdown)
- Collections breakdown (grouped by page/collection path)
- Video list (sortable table with thumbnails, status, details)

Data source: Kirby file metadata by default, with toggle for full Bunny library view.

## Requirements

1. **Stats scope**: Default to Kirby-managed videos, toggle to show full Bunny library
2. **Dashboard info**: Summary cards + collections tree + video list
3. **Access control**: Configurable via user blueprint permissions
4. **Cache**: Configurable TTL for API data, can be disabled

## Architecture

### Data Flow

```
Kirby View:
  Panel Area → API endpoint → BunnyStats::fromKirby() → site()->index()->files()
                                                       → filter template=bunny-video
                                                       → aggregate bunnydata

Full Library View:
  Panel Area → API endpoint → BunnyStats::fromApi() → Bunny API /library/{id}/videos
                                                    → cache response (configurable TTL)
```

### New Files

| File | Purpose |
|------|---------|
| `src/BunnyStats.php` | Stats aggregation (Kirby + API) |
| `src/BunnyStatsArea.php` | Area registration definition |
| `src/components/BunnyStatsView.vue` | Main dashboard Vue component |

### Modified Files

| File | Changes |
|------|---------|
| `index.php` | Add `areas` registration, add API routes |
| `src/index.js` | Register BunnyStatsView component |
| `src/BunnyStreamClient.php` | Add `listVideos()` method |

## Area Registration

```php
// index.php
'areas' => [
    'bunny-stream' => require __DIR__ . '/src/BunnyStatsArea.php'
]
```

```php
// src/BunnyStatsArea.php
return function ($kirby) {
    return [
        'label' => 'Bunny Stream',
        'icon'  => 'video',
        'menu'  => true,
        'link'  => 'bunny-stream',
        'views' => [
            [
                'pattern' => 'bunny-stream',
                'action'  => function () {
                    return [
                        'component' => 'k-bunny-stats-view',
                        'props'     => []
                    ];
                }
            ]
        ]
    ];
};
```

## API Endpoints

### GET /api/bunny-stream/stats

Returns Kirby-aggregated stats.

**Response**:
```json
{
  "totals": {
    "videos": 42,
    "storage": 5368709120,
    "storageFormatted": "5.0 GB",
    "ready": 38,
    "processing": 3,
    "error": 1
  },
  "collections": [
    {
      "path": "site",
      "label": "Site",
      "videos": 5,
      "storage": 1073741824
    },
    {
      "path": "work/project-a",
      "label": "project-a",
      "videos": 3,
      "storage": 858993459
    }
  ],
  "videos": [
    {
      "id": "abc-123",
      "title": "intro.mp4",
      "status": 4,
      "statusLabel": "Ready",
      "duration": 120,
      "durationFormatted": "2:00",
      "storage": 52428800,
      "storageFormatted": "50 MB",
      "resolutions": ["720p", "1080p"],
      "thumbnail": "https://vz-xxx.b-cdn.net/abc-123/thumbnail.jpg",
      "pageUrl": "/panel/pages/work+project-a",
      "fileUrl": "/panel/pages/work+project-a/files/intro.mp4"
    }
  ]
}
```

### GET /api/bunny-stream/stats/library

Returns full Bunny library stats. Cached per `statsCacheTtl` config.

**Response**: Same structure, but includes:
- `inKirby: boolean` field on each video
- Collections from Bunny (not Kirby page paths)

## Config Options

```php
'jonasfeige.kirby-bunny-stream' => [
    // ... existing options
    'statsCacheTtl' => 300,  // seconds, 0 or false to disable caching
]
```

## BunnyStats Class

```php
class BunnyStats
{
    public static function fromKirby(): array
    {
        $videos = [];
        $totals = ['videos' => 0, 'storage' => 0, 'ready' => 0, 'processing' => 0, 'error' => 0];
        $collections = [];

        foreach (site()->index()->files()->filterBy('template', 'bunny-video') as $file) {
            $data = $file->bunnyData();
            if (!$data) continue;

            $status = $data['status'] ?? 0;
            $totals['videos']++;
            $totals['storage'] += $data['storageSize'] ?? 0;

            if ($status === 4) $totals['ready']++;
            elseif ($status >= 5) $totals['error']++;
            else $totals['processing']++;

            // Group by parent page path (Kirby view)
            // e.g., "site" for site-level files, "work/project-a" for page files
            $pagePath = $file->parent() === site() ? 'site' : $file->parent()->id();
            if (!isset($collections[$pagePath])) {
                $collections[$pagePath] = [
                    'path' => $pagePath,
                    'label' => $file->parent() === site() ? 'Site' : $file->parent()->title()->value(),
                    'videos' => 0,
                    'storage' => 0
                ];
            }
            $collections[$pagePath]['videos']++;
            $collections[$pagePath]['storage'] += $data['storageSize'] ?? 0;

            $videos[] = self::formatVideo($file, $data);
        }

        return [
            'totals' => self::formatTotals($totals),
            'collections' => array_values($collections),
            'videos' => $videos
        ];
    }

    public static function fromApi(): array
    {
        $client = new BunnyStreamClient();
        $videos = $client->listVideos(); // paginated fetch
        // ... transform and return
    }
}
```

## Vue Component

```vue
<!-- src/components/BunnyStatsView.vue -->
<template>
  <k-inside>
    <k-view>
      <k-header>
        Bunny Stream
        <template #buttons>
          <k-button-group>
            <k-button
              :current="!showLibrary"
              @click="showLibrary = false"
            >Kirby Videos</k-button>
            <k-button
              :current="showLibrary"
              @click="showLibrary = true"
            >Full Library</k-button>
          </k-button-group>
        </template>
      </k-header>

      <k-grid v-if="!loading">
        <k-column width="1/4">
          <k-stat :value="stats.totals.videos" label="Videos" />
        </k-column>
        <k-column width="1/4">
          <k-stat :value="stats.totals.storageFormatted" label="Storage" />
        </k-column>
        <k-column width="1/4">
          <k-stat :value="stats.totals.ready" label="Ready" theme="positive" />
        </k-column>
        <k-column width="1/4">
          <k-stat
            :value="stats.totals.processing + stats.totals.error"
            label="Processing/Errors"
            :theme="stats.totals.error > 0 ? 'negative' : 'notice'"
          />
        </k-column>
      </k-grid>

      <k-section label="Collections">
        <k-items :items="collectionItems" @click="filterByCollection" />
      </k-section>

      <k-section label="Videos">
        <k-table
          :columns="columns"
          :rows="filteredVideos"
          :sortable="true"
        />
      </k-section>
    </k-view>
  </k-inside>
</template>

<script>
export default {
  data() {
    return {
      showLibrary: false,
      loading: true,
      stats: null,
      selectedCollection: null
    };
  },
  computed: {
    filteredVideos() {
      if (!this.selectedCollection) return this.stats?.videos || [];
      return this.stats?.videos.filter(v => v.collection === this.selectedCollection);
    }
  },
  watch: {
    showLibrary: 'fetchStats'
  },
  mounted() {
    this.fetchStats();
  },
  methods: {
    async fetchStats() {
      this.loading = true;
      const endpoint = this.showLibrary
        ? '/api/bunny-stream/stats/library'
        : '/api/bunny-stream/stats';
      const response = await this.$api.get(endpoint);
      this.stats = response;
      this.loading = false;
    },
    filterByCollection(collection) {
      this.selectedCollection = collection;
    }
  }
};
</script>
```

## Permissions

Access controlled via user blueprint:

```yaml
# site/blueprints/users/editor.yml
permissions:
  access:
    bunny-stream: false  # Hide area for editors
```

Default: visible to all Panel users.

## Testing

1. **Unit tests**: `BunnyStats::fromKirby()` aggregation logic
2. **Manual testing**:
   - Verify area appears in Panel sidebar
   - Check stats match actual file counts
   - Toggle to Full Library view
   - Filter by collection
   - Sort video table
   - Click video row → opens file view
3. **Permission testing**:
   - Create user with `access.bunny-stream: false`
   - Verify area not visible
4. **Cache testing**:
   - Set `statsCacheTtl: 10`
   - Verify Full Library view caches
   - Set to `0`, verify no caching

## Implementation Order

1. Add `BunnyStats.php` with `fromKirby()` method
2. Add API endpoint `/api/bunny-stream/stats`
3. Add `BunnyStatsArea.php` area definition
4. Register area in `index.php`
5. Create `BunnyStatsView.vue` with summary cards
6. Add collections breakdown
7. Add video table
8. Add `listVideos()` to `BunnyStreamClient.php`
9. Add `fromApi()` to `BunnyStats.php`
10. Add `/api/bunny-stream/stats/library` endpoint
11. Add toggle and Full Library view
12. Add cache config option
13. Test permissions

## Open Questions

None - all clarified during brainstorming.
