<template>
  <k-section :label="label" class="k-bunny-video-upload-section">
    <!-- Upload zone -->
    <div
      class="k-bunny-upload-zone"
      :class="{ 'is-dragging': isDragging, 'is-uploading': isUploading }"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
      @click="openFilePicker"
    >
      <input
        ref="fileInput"
        type="file"
        :accept="accept"
        multiple
        class="k-bunny-file-input"
        @change="onFileSelect"
      >

      <!-- Idle state -->
      <div v-if="!isUploading && uploads.length === 0" class="k-bunny-upload-idle">
        <div class="k-bunny-upload-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <polyline points="17 8 12 3 7 8" />
            <line x1="12" y1="3" x2="12" y2="15" />
          </svg>
        </div>
        <div class="k-bunny-upload-text">
          <strong>Drop videos here</strong> <span>or click to browse</span>
        </div>
        <div v-if="help" class="k-bunny-upload-help">{{ help }}</div>
      </div>

      <!-- Upload queue -->
      <div v-if="uploads.length > 0" class="k-bunny-upload-queue">
        <div
          v-for="upload in uploads"
          :key="upload.id"
          class="k-bunny-upload-item"
          :class="'is-' + upload.status"
        >
          <div class="k-bunny-upload-item-info">
            <span class="k-bunny-upload-item-name">{{ upload.filename }}</span>
            <span class="k-bunny-upload-item-size">{{ formatSize(upload.size) }}</span>
          </div>

          <!-- Progress bar -->
          <template v-if="upload.status === 'uploading'">
            <div class="k-bunny-upload-progress-bar">
              <div
                class="k-bunny-upload-progress-fill"
                :style="{ width: upload.progress + '%' }"
              />
            </div>
            <div class="k-bunny-upload-progress-text">
              {{ upload.progress.toFixed(0) }}% · {{ formatSize(upload.uploaded) }} / {{ formatSize(upload.size) }}
            </div>
          </template>

          <!-- Status indicators -->
          <div v-else-if="upload.status === 'pending'" class="k-bunny-upload-status">
            Waiting...
          </div>
          <div v-else-if="upload.status === 'initializing'" class="k-bunny-upload-status">
            Initializing...
          </div>
          <div v-else-if="upload.status === 'finalizing'" class="k-bunny-upload-status">
            Finalizing...
          </div>
          <div v-else-if="upload.status === 'complete'" class="k-bunny-upload-status is-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="20 6 9 17 4 12" />
            </svg>
            Complete
          </div>
          <div v-else-if="upload.status === 'error'" class="k-bunny-upload-status is-error">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="15" y1="9" x2="9" y2="15" />
              <line x1="9" y1="9" x2="15" y2="15" />
            </svg>
            {{ upload.error || 'Upload failed' }}
          </div>

          <!-- Cancel button -->
          <button
            v-if="upload.status === 'uploading' || upload.status === 'pending'"
            class="k-bunny-upload-cancel"
            @click.stop="cancelUpload(upload)"
            title="Cancel upload"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </k-section>
</template>

<script>
import * as tus from 'tus-js-client';

export default {
  props: {
    // Props from blueprint
    label: {
      type: String,
      default: 'Upload Video'
    },
    help: {
      type: String,
      default: null
    },
    accept: {
      type: String,
      default: 'video/*'
    },
    max: {
      type: Number,
      default: null
    },
    // Section name for API calls
    name: {
      type: String,
      required: true
    },
    // Parent info passed by Panel
    parent: {
      type: String,
      default: null
    }
  },

  data() {
    return {
      isDragging: false,
      uploads: [],
      uploadIdCounter: 0,
      // Data loaded from section API
      parentType: 'page',
      parentId: null,
      apiEndpoint: 'bunny-stream'
    };
  },

  computed: {
    isUploading() {
      return this.uploads.some(u =>
        u.status === 'uploading' ||
        u.status === 'initializing' ||
        u.status === 'finalizing'
      );
    }
  },

  created() {
    this.load();
  },

  methods: {
    async load() {
      try {
        const response = await this.$api.get(this.parent + '/sections/' + this.name);
        this.parentType = response.parentType ?? 'page';
        this.parentId = response.parentId ?? null;
        this.apiEndpoint = response.apiEndpoint ?? 'bunny-stream';
      } catch (error) {
        console.error('Failed to load section data:', error);
      }
    },

    openFilePicker() {
      if (!this.isUploading) {
        this.$refs.fileInput.click();
      }
    },

    onDragOver() {
      this.isDragging = true;
    },

    onDragLeave() {
      this.isDragging = false;
    },

    onDrop(event) {
      this.isDragging = false;
      const files = Array.from(event.dataTransfer.files);
      this.queueFiles(files);
    },

    onFileSelect(event) {
      const files = Array.from(event.target.files);
      this.queueFiles(files);
      // Reset input so same file can be selected again
      event.target.value = '';
    },

    queueFiles(files) {
      const videoFiles = files.filter(file => file.type.startsWith('video/'));

      if (videoFiles.length === 0) {
        this.$panel.notification.error('Please select video files only');
        return;
      }

      for (const file of videoFiles) {
        const upload = {
          id: ++this.uploadIdCounter,
          file: file,
          filename: file.name,
          size: file.size,
          uploaded: 0,
          progress: 0,
          status: 'pending',
          error: null,
          tusUpload: null,
          videoId: null,
          collectionId: null
        };
        this.uploads.push(upload);
      }

      this.processQueue();
    },

    async processQueue() {
      const pending = this.uploads.find(u => u.status === 'pending');
      if (!pending) return;

      // Check if already uploading (process one at a time)
      if (this.uploads.some(u => u.status === 'uploading' || u.status === 'initializing')) {
        return;
      }

      await this.startUpload(pending);
    },

    async startUpload(upload) {
      upload.status = 'initializing';

      try {
        // Step 1: Initialize upload on server - creates video on Bunny
        const initResponse = await this.$api.post(this.apiEndpoint + '/init-upload', {
          filename: upload.filename,
          parentType: this.parentType,
          parentId: this.parentId
        });

        if (!initResponse.videoId || !initResponse.tusCredentials) {
          throw new Error('Invalid init response');
        }

        upload.videoId = initResponse.videoId;
        upload.collectionId = initResponse.collectionId;

        const { signature, expiration, libraryId, videoId, endpoint } = initResponse.tusCredentials;

        // Step 2: Start TUS upload directly to Bunny
        upload.status = 'uploading';

        const tusUpload = new tus.Upload(upload.file, {
          endpoint: endpoint,
          retryDelays: [0, 3000, 5000, 10000, 20000],
          chunkSize: 5 * 1024 * 1024, // 5MB chunks
          metadata: {
            filetype: upload.file.type,
            title: upload.filename
          },
          headers: {
            AuthorizationSignature: signature,
            AuthorizationExpire: String(expiration),
            VideoId: videoId,
            LibraryId: String(libraryId)
          },
          onError: (error) => {
            console.error('TUS upload error:', error);
            upload.status = 'error';
            upload.error = error.message || 'Upload failed';
            this.processQueue();
          },
          onProgress: (bytesUploaded, bytesTotal) => {
            upload.uploaded = bytesUploaded;
            upload.progress = (bytesUploaded / bytesTotal) * 100;
          },
          onSuccess: async () => {
            await this.finalizeUpload(upload);
          }
        });

        upload.tusUpload = tusUpload;

        // Start upload directly (no resume for now to simplify debugging)
        tusUpload.start();

      } catch (error) {
        console.error('Upload init error:', error);
        upload.status = 'error';
        upload.error = error.message || 'Failed to initialize upload';
        this.processQueue();
      }
    },

    async finalizeUpload(upload) {
      upload.status = 'finalizing';

      try {
        // Step 3: Create Kirby file record
        await this.$api.post(this.apiEndpoint + '/finalize-upload', {
          videoId: upload.videoId,
          collectionId: upload.collectionId,
          filename: upload.filename,
          parentType: this.parentType,
          parentId: this.parentId
        });

        upload.status = 'complete';

        // Refresh the parent view to show new file
        this.$panel.notification.success(`${upload.filename} uploaded successfully. Video is now processing on Bunny.`);

        // Remove completed upload after a delay, then reload
        setTimeout(() => {
          this.uploads = this.uploads.filter(u => u.id !== upload.id);
          this.$reload();
        }, 2000);

      } catch (error) {
        console.error('Finalize error:', error);
        upload.status = 'error';
        upload.error = error.message || 'Failed to create file record';
      }

      this.processQueue();
    },

    cancelUpload(upload) {
      if (upload.tusUpload) {
        upload.tusUpload.abort();
      }
      upload.status = 'error';
      upload.error = 'Cancelled';
      this.processQueue();
    },

    formatSize(bytes) {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    clearCompleted() {
      this.uploads = this.uploads.filter(u =>
        u.status !== 'complete' && u.status !== 'error'
      );
    }
  }
};
</script>

<style>
.k-bunny-upload-zone {
  border: 1px dashed var(--color-gray-400);
  border-radius: var(--rounded);
  padding: var(--spacing-6);
  background: var(--color-background);
  cursor: pointer;
}

.k-bunny-upload-zone:hover:not(.is-uploading) {
  background: var(--color-gray-100);
}

.k-bunny-upload-zone.is-dragging {
  background: var(--color-gray-200);
  border-color: var(--color-focus);
}

.k-bunny-upload-zone.is-uploading {
  cursor: default;
}

.k-bunny-file-input {
  display: none;
}

.k-bunny-upload-idle {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--spacing-2);
  text-align: center;
}

.k-bunny-upload-icon {
  width: 2rem;
  height: 2rem;
  color: var(--color-gray-500);
}

.k-bunny-upload-icon svg {
  width: 100%;
  height: 100%;
}

.k-bunny-upload-text {
  font-size: var(--text-sm);
  color: var(--color-gray-700);
}

.k-bunny-upload-text span {
  color: var(--color-gray-500);
}

.k-bunny-upload-help {
  font-size: var(--text-xs);
  color: var(--color-gray-500);
}

/* Upload queue */
.k-bunny-upload-queue {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2);
}

.k-bunny-upload-item {
  background: var(--color-gray-100);
  border-radius: var(--rounded-sm);
  padding: var(--spacing-3);
  position: relative;
}

.k-bunny-upload-item.is-complete {
  background: var(--color-positive-light);
}

.k-bunny-upload-item.is-error {
  background: var(--color-negative-light);
}

.k-bunny-upload-item-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--spacing-2);
  padding-right: var(--spacing-6);
}

.k-bunny-upload-item-name {
  font-size: var(--text-sm);
  color: var(--color-gray-900);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.k-bunny-upload-item-size {
  font-size: var(--text-xs);
  color: var(--color-gray-600);
  flex-shrink: 0;
  margin-left: var(--spacing-2);
}

/* Progress bar */
.k-bunny-upload-progress-bar {
  height: 4px;
  background: var(--color-gray-300);
  border-radius: 2px;
  overflow: hidden;
  margin-bottom: var(--spacing-1);
}

.k-bunny-upload-progress-fill {
  height: 100%;
  background: var(--color-focus);
  border-radius: 2px;
}

.k-bunny-upload-progress-text {
  font-size: var(--text-xs);
  color: var(--color-gray-600);
}

/* Status indicators */
.k-bunny-upload-status {
  display: flex;
  align-items: center;
  gap: var(--spacing-1);
  font-size: var(--text-xs);
  color: var(--color-gray-600);
}

.k-bunny-upload-status svg {
  width: 1rem;
  height: 1rem;
}

.k-bunny-upload-status.is-success {
  color: var(--color-positive);
}

.k-bunny-upload-status.is-error {
  color: var(--color-negative);
}

/* Cancel button */
.k-bunny-upload-cancel {
  position: absolute;
  top: var(--spacing-3);
  right: var(--spacing-3);
  width: 1.25rem;
  height: 1.25rem;
  padding: 0;
  border: none;
  background: transparent;
  border-radius: var(--rounded-sm);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-gray-500);
}

.k-bunny-upload-cancel:hover {
  color: var(--color-negative);
}

.k-bunny-upload-cancel svg {
  width: 1rem;
  height: 1rem;
}
</style>
