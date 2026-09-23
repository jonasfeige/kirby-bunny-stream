# Bunny Stream Panel Area Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Panel area to display video inventory stats with summary cards, collections breakdown, and video list.

**Architecture:** Kirby-first data aggregation from file metadata. New `BunnyStats` class scans all bunny-video files and aggregates data. Vue component fetches via API endpoints and renders using Kirby's Panel component kit. Toggle to Full Library view fetches from Bunny API with configurable caching.

**Tech Stack:** PHP 8.1+, Kirby 5, Vue 3 (Kirby Panel), Guzzle HTTP

## Global Constraints

- Follow existing plugin namespace: `KirbyBunny\Stream`
- Use `BunnyStreamClient::instance()` for API access
- Status constants from `BunnyStreamState::STATUS_*`
- Cache TTL default: 300 seconds (5 minutes), configurable, can be disabled with 0/false
- No external dependencies beyond existing Guzzle

---

## File Structure

| File | Responsibility |
|------|----------------|
| `src/BunnyStats.php` | Stats aggregation: `fromKirby()` and `fromApi()` methods |
| `src/BunnyStatsArea.php` | Panel area definition (views, routes) |
| `src/components/BunnyStatsView.vue` | Dashboard Vue component |
| `index.php` | Register area + API routes + config option |
| `src/index.js` | Register Vue component |
| `src/BunnyStreamClient.php` | Add `listVideos()` method |

---

### Task 1: BunnyStats Class with fromKirby()

**Files:**
- Create: `src/BunnyStats.php`

**Interfaces:**
- Consumes: `site()->index()->files()`, `BunnyStreamState::STATUS_*`
- Produces: `BunnyStats::fromKirby(): array` returning `{totals, collections, videos}`

- [ ] **Step 1: Create BunnyStats.php with class skeleton**

```php
<?php

declare(strict_types=1);

namespace KirbyBunny\Stream;

class BunnyStats
{
    public static function fromKirby(): array
    {
        $videos = [];
        $totals = [
            'videos' => 0,
            'storage' => 0,
            'ready' => 0,
            'processing' => 0,
            'error' => 0
        ];
        $collections = [];

        foreach (site()->index()->files()->filterBy('template', 'bunny-video') as $file) {
            $data = $file->bunnyData();
            $videoId = $file->bunnyVideoId();

            if (!$videoId) {
                continue;
            }

            $status = $data['status'] ?? 0;
            $storageSize = $data['storageSize'] ?? 0;

            $totals['videos']++;
            $totals['storage'] += $storageSize;

            if ($status === BunnyStreamState::STATUS_READY) {
                $totals['ready']++;
            } elseif ($status >= BunnyStreamState::STATUS_ERROR) {
                $totals['error']++;
            } else {
                $totals['processing']++;
            }

            // Group by parent page path
            $parent = $file->parent();
            $pagePath = $parent === site() ? 'site' : $parent->id();
            $pageLabel = $parent === site() ? 'Site' : $parent->title()->value();

            if (!isset($collections[$pagePath])) {
                $collections[$pagePath] = [
                    'path' => $pagePath,
                    'label' => $pageLabel,
                    'videos' => 0,
                    'storage' => 0,
                    'storageFormatted' => ''
                ];
            }
            $collections[$pagePath]['videos']++;
            $collections[$pagePath]['storage'] += $storageSize;

            $videos[] = self::formatVideo($file, $data);
        }

        // Format storage for collections
        foreach ($collections as &$col) {
            $col['storageFormatted'] = self::formatBytes($col['storage']);
        }

        return [
            'totals' => self::formatTotals($totals),
            'collections' => array_values($collections),
            'videos' => $videos
        ];
    }

    private static function formatTotals(array $totals): array
    {
        return [
            'videos' => $totals['videos'],
            'storage' => $totals['storage'],
            'storageFormatted' => self::formatBytes($totals['storage']),
            'ready' => $totals['ready'],
            'processing' => $totals['processing'],
            'error' => $totals['error']
        ];
    }

    private static function formatVideo($file, array $data): array
    {
        $duration = $data['length'] ?? 0;
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        $parent = $file->parent();
        $pagePath = $parent === site() ? 'site' : $parent->id();
        $pageId = $parent === site() ? '' : $parent->id();

        return [
            'id' => $file->bunnyVideoId(),
            'filename' => $file->filename(),
            'status' => $data['status'] ?? 0,
            'statusLabel' => self::statusLabel($data['status'] ?? 0),
            'duration' => $duration,
            'durationFormatted' => sprintf('%d:%02d', $minutes, $seconds),
            'storage' => $data['storageSize'] ?? 0,
            'storageFormatted' => self::formatBytes($data['storageSize'] ?? 0),
            'resolutions' => isset($data['availableResolutions'])
                ? explode(',', $data['availableResolutions'])
                : [],
            'thumbnail' => $file->bunnyThumbnail() ?: null,
            'collection' => $pagePath,
            'pageId' => $pageId,
            'fileId' => $file->id()
        ];
    }

    private static function statusLabel(int $status): string
    {
        return match ($status) {
            BunnyStreamState::STATUS_QUEUED => 'Queued',
            BunnyStreamState::STATUS_PROCESSING => 'Processing',
            BunnyStreamState::STATUS_ENCODING => 'Encoding',
            BunnyStreamState::STATUS_FINISHED => 'Finishing',
            BunnyStreamState::STATUS_READY => 'Ready',
            BunnyStreamState::STATUS_ERROR => 'Error',
            BunnyStreamState::STATUS_UPLOAD_FAILED => 'Upload Failed',
            default => 'Unknown'
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
}
```

- [ ] **Step 2: Verify file loads without syntax errors**

Run: `php -l src/BunnyStats.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/BunnyStats.php
git commit -m "feat: add BunnyStats class with fromKirby() aggregation"
```

---

### Task 2: Stats API Endpoint

**Files:**
- Modify: `index.php` (add API route)

**Interfaces:**
- Consumes: `BunnyStats::fromKirby()`
- Produces: `GET /api/bunny-stream/stats` endpoint

- [ ] **Step 1: Add use statement for BunnyStats in index.php**

After line 12 (`use KirbyBunny\Stream\VideoUploader;`), add:

```php
use KirbyBunny\Stream\BunnyStats;
```

- [ ] **Step 2: Add stats API route**

In the `'api' => ['routes' => [...]]` array (after the `finalize-upload` route around line 378), add:

```php
[
    'pattern' => 'bunny-stream/stats',
    'method' => 'GET',
    'action' => function () {
        return BunnyStats::fromKirby();
    }
],
```

- [ ] **Step 3: Verify syntax**

Run: `php -l index.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat: add /api/bunny-stream/stats endpoint"
```

---

### Task 3: Panel Area Registration

**Files:**
- Create: `src/BunnyStatsArea.php`
- Modify: `index.php` (register area)

**Interfaces:**
- Consumes: Kirby area extension API
- Produces: Panel area at `/panel/bunny-stream`

- [ ] **Step 1: Create BunnyStatsArea.php**

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 2: Register area in index.php**

After the `'filePreviews'` array (around line 35), add:

```php
'areas' => [
    'bunny-stream' => require __DIR__ . '/src/BunnyStatsArea.php'
],
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/BunnyStatsArea.php && php -l index.php`
Expected: `No syntax errors detected` (twice)

- [ ] **Step 4: Commit**

```bash
git add src/BunnyStatsArea.php index.php
git commit -m "feat: register Bunny Stream panel area"
```

---

### Task 4: Vue Dashboard Component (Summary Cards)

**Files:**
- Create: `src/components/BunnyStatsView.vue`
- Modify: `src/index.js` (register component)

**Interfaces:**
- Consumes: `/api/bunny-stream/stats` endpoint
- Produces: Vue component `k-bunny-stats-view`

- [ ] **Step 1: Create BunnyStatsView.vue with summary cards**

```vue
<template>
  <k-inside>
    <k-view>
      <k-header>
        Bunny Stream
        <template #buttons>
          <k-button-group>
            <k-button
              :current="!showLibrary"
              size="sm"
              @click="showLibrary = false"
            >
              Kirby Videos
            </k-button>
            <k-button
              :current="showLibrary"
              size="sm"
              @click="showLibrary = true"
            >
              Full Library
            </k-button>
          </k-button-group>
        </template>
      </k-header>

      <k-grid v-if="!loading && stats" gutter="medium">
        <k-column width="1/4">
          <k-box theme="none">
            <k-text size="large" theme="help">
              <strong>{{ stats.totals.videos }}</strong>
            </k-text>
            <k-text size="small" theme="help">Videos</k-text>
          </k-box>
        </k-column>
        <k-column width="1/4">
          <k-box theme="none">
            <k-text size="large" theme="help">
              <strong>{{ stats.totals.storageFormatted }}</strong>
            </k-text>
            <k-text size="small" theme="help">Storage</k-text>
          </k-box>
        </k-column>
        <k-column width="1/4">
          <k-box theme="none">
            <k-text size="large" theme="positive">
              <strong>{{ stats.totals.ready }}</strong>
            </k-text>
            <k-text size="small" theme="help">Ready</k-text>
          </k-box>
        </k-column>
        <k-column width="1/4">
          <k-box theme="none">
            <k-text size="large" :theme="stats.totals.error > 0 ? 'negative' : 'notice'">
              <strong>{{ stats.totals.processing + stats.totals.error }}</strong>
            </k-text>
            <k-text size="small" theme="help">Processing / Errors</k-text>
          </k-box>
        </k-column>
      </k-grid>

      <k-box v-if="loading" theme="none" style="padding: 2rem; text-align: center;">
        <k-loader />
      </k-box>

      <k-box v-if="error" theme="negative">
        {{ error }}
      </k-box>
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
      error: null
    };
  },
  watch: {
    showLibrary() {
      this.fetchStats();
    }
  },
  mounted() {
    this.fetchStats();
  },
  methods: {
    async fetchStats() {
      this.loading = true;
      this.error = null;

      try {
        const endpoint = this.showLibrary
          ? 'bunny-stream/stats/library'
          : 'bunny-stream/stats';
        this.stats = await this.$api.get(endpoint);
      } catch (err) {
        this.error = err.message || 'Failed to load stats';
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>
```

- [ ] **Step 2: Register component in src/index.js**

Replace entire file content:

```javascript
import BunnyVideoPreview from "./components/BunnyVideoPreview.vue";
import BunnyVideoUpload from "./components/BunnyVideoUpload.vue";
import BunnyStatsView from "./components/BunnyStatsView.vue";

panel.plugin("jonasfeige/kirby-bunny-stream", {
  components: {
    "k-bunny-video-preview": BunnyVideoPreview,
    "k-bunny-stats-view": BunnyStatsView
  },
  sections: {
    "bunny-video-upload": BunnyVideoUpload
  }
});
```

- [ ] **Step 3: Build the Vue component**

Run: `npm run build`
Expected: Build completes without errors

- [ ] **Step 4: Commit**

```bash
git add src/components/BunnyStatsView.vue src/index.js
git commit -m "feat: add BunnyStatsView component with summary cards"
```

---

### Task 5: Collections Breakdown Section

**Files:**
- Modify: `src/components/BunnyStatsView.vue`

**Interfaces:**
- Consumes: `stats.collections` array
- Produces: Clickable collections list that filters videos

- [ ] **Step 1: Add collections section after stats grid**

In `BunnyStatsView.vue`, after the `</k-grid>` (around line 51), add:

```vue
      <k-section v-if="!loading && stats" label="Collections" class="k-bunny-collections">
        <k-items
          :items="collectionItems"
          :layout="'list'"
          @item="filterByCollection"
        />
      </k-section>
```

- [ ] **Step 2: Add selectedCollection to data**

In the `data()` return object, add:

```javascript
selectedCollection: null
```

- [ ] **Step 3: Add collectionItems computed property**

After the `data()` method, add:

```javascript
computed: {
  collectionItems() {
    if (!this.stats?.collections) return [];

    return this.stats.collections.map(col => ({
      id: col.path,
      text: col.label,
      info: `${col.videos} videos · ${col.storageFormatted}`,
      icon: col.path === 'site' ? 'home' : 'page',
      link: false
    }));
  }
},
```

- [ ] **Step 4: Add filterByCollection method**

In the `methods` object, add:

```javascript
filterByCollection(item) {
  this.selectedCollection = this.selectedCollection === item ? null : item;
}
```

- [ ] **Step 5: Build and verify**

Run: `npm run build`
Expected: Build completes without errors

- [ ] **Step 6: Commit**

```bash
git add src/components/BunnyStatsView.vue
git commit -m "feat: add collections breakdown section"
```

---

### Task 6: Video List Table

**Files:**
- Modify: `src/components/BunnyStatsView.vue`

**Interfaces:**
- Consumes: `stats.videos` array, `selectedCollection`
- Produces: Sortable video table with links to files

- [ ] **Step 1: Add videos section after collections**

After the collections `</k-section>`, add:

```vue
      <k-section v-if="!loading && stats" label="Videos" class="k-bunny-videos">
        <k-table
          v-if="filteredVideos.length > 0"
          :columns="{
            thumbnail: { label: '', width: '60px', type: 'image' },
            filename: { label: 'Name', width: '1fr' },
            statusLabel: { label: 'Status', width: '100px' },
            durationFormatted: { label: 'Duration', width: '80px' },
            storageFormatted: { label: 'Size', width: '80px' },
            collection: { label: 'Location', width: '150px' }
          }"
          :rows="filteredVideos"
          :index="false"
          @cell="onVideoClick"
        />
        <k-empty v-else icon="video">
          No videos found
        </k-empty>
      </k-section>
```

- [ ] **Step 2: Add filteredVideos computed property**

In the `computed` object, add:

```javascript
filteredVideos() {
  if (!this.stats?.videos) return [];

  let videos = this.stats.videos;

  if (this.selectedCollection) {
    videos = videos.filter(v => v.collection === this.selectedCollection);
  }

  return videos.map(v => ({
    ...v,
    thumbnail: v.thumbnail ? { src: v.thumbnail, cover: true } : null
  }));
}
```

- [ ] **Step 3: Add onVideoClick method**

In the `methods` object, add:

```javascript
onVideoClick(row, column, columnIndex) {
  if (row.fileId) {
    const parts = row.fileId.split('/');
    const filename = parts.pop();
    const pageId = parts.join('/');

    if (pageId) {
      this.$panel.open(`pages/${pageId.replace(/\//g, '+')}/files/${filename}`);
    } else {
      this.$panel.open(`site/files/${filename}`);
    }
  }
}
```

- [ ] **Step 4: Build and verify**

Run: `npm run build`
Expected: Build completes without errors

- [ ] **Step 5: Commit**

```bash
git add src/components/BunnyStatsView.vue
git commit -m "feat: add video list table with filtering"
```

---

### Task 7: listVideos() Method in BunnyStreamClient

**Files:**
- Modify: `src/BunnyStreamClient.php`

**Interfaces:**
- Consumes: Bunny API `/library/{id}/videos`
- Produces: `listVideos(int $page = 1, int $perPage = 100): array`

- [ ] **Step 1: Add listVideos method**

After the `getVideo()` method (around line 179), add:

```php
/**
 * List all videos in the library with pagination.
 *
 * @param int $page Page number (1-indexed)
 * @param int $perPage Items per page (max 100)
 * @return array {items: array, totalItems: int, currentPage: int, itemsPerPage: int}
 */
public function listVideos(int $page = 1, int $perPage = 100): array
{
    try {
        $response = $this->http->get("library/{$this->libraryId}/videos", [
            'query' => [
                'page' => $page,
                'itemsPerPage' => min($perPage, 100)
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
    } catch (GuzzleException $e) {
        throw new Exception('Failed to list videos from Bunny: ' . $e->getMessage());
    }
}

/**
 * List all videos in the library (fetches all pages).
 *
 * @return array All video items
 */
public function listAllVideos(): array
{
    $allVideos = [];
    $page = 1;
    $perPage = 100;

    do {
        $response = $this->listVideos($page, $perPage);
        $items = $response['items'] ?? [];
        $allVideos = array_merge($allVideos, $items);
        $totalItems = $response['totalItems'] ?? 0;
        $page++;
    } while (count($allVideos) < $totalItems);

    return $allVideos;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l src/BunnyStreamClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/BunnyStreamClient.php
git commit -m "feat: add listVideos() and listAllVideos() to BunnyStreamClient"
```

---

### Task 8: fromApi() Method and Cache Config

**Files:**
- Modify: `src/BunnyStats.php`
- Modify: `index.php` (add config option)

**Interfaces:**
- Consumes: `BunnyStreamClient::listAllVideos()`, Kirby cache API
- Produces: `BunnyStats::fromApi(): array`

- [ ] **Step 1: Add statsCacheTtl config option in index.php**

In the `'options'` array (around line 15), add after `'collection' => 'site'`:

```php
'statsCacheTtl' => 300, // seconds, 0 or false to disable
```

- [ ] **Step 2: Add fromApi method to BunnyStats.php**

After the `fromKirby()` method, add:

```php
public static function fromApi(): array
{
    $kirby = \Kirby\Cms\App::instance();
    $ttl = $kirby->option('jonasfeige.kirby-bunny-stream.statsCacheTtl', 300);
    $cacheKey = 'bunny-stream-library-stats';

    // Check cache if TTL is enabled
    if ($ttl) {
        $cached = $kirby->cache('jonasfeige.kirby-bunny-stream')->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    $client = BunnyStreamClient::instance();
    $apiVideos = $client->listAllVideos();

    // Get list of Kirby video IDs for "inKirby" flag
    $kirbyVideoIds = [];
    foreach (site()->index()->files()->filterBy('template', 'bunny-video') as $file) {
        $videoId = $file->bunnyVideoId();
        if ($videoId) {
            $kirbyVideoIds[$videoId] = $file->id();
        }
    }

    $videos = [];
    $totals = [
        'videos' => 0,
        'storage' => 0,
        'ready' => 0,
        'processing' => 0,
        'error' => 0
    ];
    $collections = [];

    foreach ($apiVideos as $video) {
        $videoId = $video['guid'] ?? '';
        $status = $video['status'] ?? 0;
        $storageSize = $video['storageSize'] ?? 0;
        $collectionId = $video['collectionId'] ?? '';

        $totals['videos']++;
        $totals['storage'] += $storageSize;

        if ($status === BunnyStreamState::STATUS_READY) {
            $totals['ready']++;
        } elseif ($status >= BunnyStreamState::STATUS_ERROR) {
            $totals['error']++;
        } else {
            $totals['processing']++;
        }

        // Group by Bunny collection
        if ($collectionId) {
            if (!isset($collections[$collectionId])) {
                $collections[$collectionId] = [
                    'path' => $collectionId,
                    'label' => $collectionId, // Could fetch collection name if needed
                    'videos' => 0,
                    'storage' => 0,
                    'storageFormatted' => ''
                ];
            }
            $collections[$collectionId]['videos']++;
            $collections[$collectionId]['storage'] += $storageSize;
        }

        $duration = $video['length'] ?? 0;
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        $videos[] = [
            'id' => $videoId,
            'filename' => $video['title'] ?? 'Untitled',
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'duration' => $duration,
            'durationFormatted' => sprintf('%d:%02d', $minutes, $seconds),
            'storage' => $storageSize,
            'storageFormatted' => self::formatBytes($storageSize),
            'resolutions' => isset($video['availableResolutions'])
                ? explode(',', $video['availableResolutions'])
                : [],
            'thumbnail' => null, // Would need CDN hostname to build URL
            'collection' => $collectionId,
            'inKirby' => isset($kirbyVideoIds[$videoId]),
            'fileId' => $kirbyVideoIds[$videoId] ?? null
        ];
    }

    // Format storage for collections
    foreach ($collections as &$col) {
        $col['storageFormatted'] = self::formatBytes($col['storage']);
    }

    $result = [
        'totals' => self::formatTotals($totals),
        'collections' => array_values($collections),
        'videos' => $videos
    ];

    // Store in cache if TTL is enabled
    if ($ttl) {
        $kirby->cache('jonasfeige.kirby-bunny-stream')->set($cacheKey, $result, $ttl);
    }

    return $result;
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/BunnyStats.php && php -l index.php`
Expected: `No syntax errors detected` (twice)

- [ ] **Step 4: Commit**

```bash
git add src/BunnyStats.php index.php
git commit -m "feat: add fromApi() with caching and statsCacheTtl config"
```

---

### Task 9: Library Stats API Endpoint

**Files:**
- Modify: `index.php` (add API route)

**Interfaces:**
- Consumes: `BunnyStats::fromApi()`
- Produces: `GET /api/bunny-stream/stats/library` endpoint

- [ ] **Step 1: Add library stats API route**

After the stats route added in Task 2, add:

```php
[
    'pattern' => 'bunny-stream/stats/library',
    'method' => 'GET',
    'action' => function () {
        return BunnyStats::fromApi();
    }
],
```

- [ ] **Step 2: Verify syntax**

Run: `php -l index.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add index.php
git commit -m "feat: add /api/bunny-stream/stats/library endpoint"
```

---

### Task 10: Full Library View Enhancements

**Files:**
- Modify: `src/components/BunnyStatsView.vue`

**Interfaces:**
- Consumes: `stats.videos[].inKirby` field
- Produces: Visual indicator for videos not in Kirby

- [ ] **Step 1: Add inKirby column to table when showLibrary is true**

Replace the `<k-table>` columns prop with a computed property. In the `computed` object, add:

```javascript
tableColumns() {
  const cols = {
    thumbnail: { label: '', width: '60px', type: 'image' },
    filename: { label: 'Name', width: '1fr' },
    statusLabel: { label: 'Status', width: '100px' },
    durationFormatted: { label: 'Duration', width: '80px' },
    storageFormatted: { label: 'Size', width: '80px' }
  };

  if (this.showLibrary) {
    cols.inKirbyLabel = { label: 'In Kirby', width: '80px' };
  } else {
    cols.collection = { label: 'Location', width: '150px' };
  }

  return cols;
}
```

- [ ] **Step 2: Update filteredVideos to add inKirbyLabel**

In the `filteredVideos` computed property, update the map:

```javascript
filteredVideos() {
  if (!this.stats?.videos) return [];

  let videos = this.stats.videos;

  if (this.selectedCollection) {
    videos = videos.filter(v => v.collection === this.selectedCollection);
  }

  return videos.map(v => ({
    ...v,
    thumbnail: v.thumbnail ? { src: v.thumbnail, cover: true } : null,
    inKirbyLabel: v.inKirby === true ? 'Yes' : (v.inKirby === false ? 'No' : '—')
  }));
}
```

- [ ] **Step 3: Update k-table to use computed columns**

Replace `:columns="{...}"` with `:columns="tableColumns"` in the template.

- [ ] **Step 4: Build and verify**

Run: `npm run build`
Expected: Build completes without errors

- [ ] **Step 5: Commit**

```bash
git add src/components/BunnyStatsView.vue
git commit -m "feat: add 'In Kirby' indicator for full library view"
```

---

### Task 11: Manual Testing

**Files:** None (testing only)

- [ ] **Step 1: Verify area appears in Panel**

1. Open Kirby Panel
2. Look for "Bunny Stream" in the left sidebar with video icon
3. Click it to open the area

Expected: Area loads, shows summary cards (may show 0s if no videos)

- [ ] **Step 2: Verify Kirby stats display**

1. Ensure some bunny-video files exist
2. Refresh the Bunny Stream area
3. Check summary cards show correct counts
4. Check collections list groups by page
5. Check video table shows all videos

Expected: Stats match actual file counts

- [ ] **Step 3: Verify Full Library toggle**

1. Click "Full Library" button
2. Wait for data to load

Expected: Shows all videos from Bunny API, with "In Kirby" column

- [ ] **Step 4: Verify collection filtering**

1. Click a collection in the list
2. Video table should filter to that collection
3. Click again to clear filter

Expected: Filtering works both ways

- [ ] **Step 5: Verify video click navigation**

1. Click a video row in the table
2. Should navigate to that file's Panel view

Expected: Opens file view in Panel

- [ ] **Step 6: Test cache behavior**

1. Set `statsCacheTtl: 10` in config
2. Load Full Library view
3. Reload quickly (within 10 seconds)
4. Should return cached data (fast)
5. Wait 10+ seconds, reload
6. Should fetch fresh data

Expected: Caching works as configured

- [ ] **Step 7: Test cache disabled**

1. Set `statsCacheTtl: 0` in config
2. Load Full Library view multiple times
3. Each request should hit the API

Expected: No caching when disabled

---

### Task 12: Permission Testing

**Files:** None (testing only)

- [ ] **Step 1: Test default visibility**

1. Log in as any Panel user
2. Verify Bunny Stream area is visible in sidebar

Expected: Visible to all users by default

- [ ] **Step 2: Test restricted access**

1. Create user blueprint `site/blueprints/users/editor.yml`:

```yaml
title: Editor
permissions:
  access:
    bunny-stream: false
```

2. Create/update a user with this role
3. Log in as that user
4. Check sidebar

Expected: Bunny Stream area not visible for editors

- [ ] **Step 3: Document permission in README**

Note: This is documentation only, no code change needed. Update README or CLAUDE.md to mention permission configuration.

---

## Verification

After all tasks complete:

1. **Syntax check all PHP files:**
   ```bash
   php -l index.php src/BunnyStats.php src/BunnyStatsArea.php src/BunnyStreamClient.php
   ```

2. **Build Vue components:**
   ```bash
   npm run build
   ```

3. **Manual verification in Panel:**
   - Area visible in sidebar
   - Summary cards show correct stats
   - Collections grouped correctly
   - Video table sortable/filterable
   - Full Library toggle works
   - Video clicks navigate to files
   - Permissions work as documented

4. **Final commit:**
   ```bash
   git add -A
   git commit -m "feat: complete Bunny Stream panel area implementation"
   ```
