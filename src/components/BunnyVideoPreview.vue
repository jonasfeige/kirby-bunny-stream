<template>
  <figure class="k-default-file-preview k-bunny-video-preview">
    <k-file-preview-frame>
      <!-- Ready: Show video player -->
      <div
        v-if="isReady && embedUrl"
        class="k-bunny-video-wrapper"
        :style="{ aspectRatio: videoWidth + '/' + videoHeight }"
      >
        <iframe
          :src="embedUrl"
          class="k-bunny-video-player"
          allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen
        />
      </div>

      <!-- Error: Show error state -->
      <div v-else-if="isError" class="k-bunny-video-error">
        <div class="k-bunny-error-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
        </div>
        <span class="k-bunny-error-text">
          {{ statusText }}
        </span>
      </div>

      <!-- Processing: Show spinner/progress -->
      <div v-else class="k-bunny-video-processing">
        <div class="k-bunny-spinner-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" stroke-dasharray="60" stroke-dashoffset="20" />
          </svg>
        </div>
        <span class="k-bunny-progress">
          {{ statusText }}
        </span>
      </div>
    </k-file-preview-frame>

    <k-file-preview-details :details="details" />
  </figure>
</template>

<script>
export default {
  props: {
    details: Array,
    url: String,
    image: Object,
    embedUrl: String,
    thumbnailUrl: String,
    status: Number,
    progress: Number,
    isReady: Boolean,
    videoWidth: Number,
    videoHeight: Number
  },
  computed: {
    isError() {
      return this.status === 5 || this.status === 6;
    },
    statusText() {
      if (this.progress !== null && this.progress !== undefined) {
        return `Encoding... ${this.progress}%`;
      }

      const statusMap = {
        0: 'Queued',
        1: 'Processing',
        2: 'Encoding',
        3: 'Finished',
        4: 'Ready',
        5: 'Error',
        6: 'Upload failed'
      };

      return statusMap[this.status] || 'Processing...';
    }
  }
};
</script>

<style>
.k-bunny-video-preview .k-file-preview-frame {
  padding: 0;
}

.k-bunny-video-wrapper {
  width: 100%;
  background: #000;
}

.k-bunny-video-player {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

.k-bunny-video-processing {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 2rem;
  color: var(--color-gray-600);
  background: var(--color-gray-200);
  min-height: 200px;
}

.k-bunny-spinner-icon {
  width: 2rem;
  height: 2rem;
  animation: k-bunny-spin 1s linear infinite;
  margin-bottom: 1rem;
  color: var(--color-gray-500);
}

.k-bunny-spinner-icon svg {
  width: 100%;
  height: 100%;
}

.k-bunny-progress {
  font-size: var(--text-sm);
  color: var(--color-gray-600);
}

@keyframes k-bunny-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.k-bunny-video-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 2rem;
  color: var(--color-red-700);
  background: var(--color-red-100);
  min-height: 200px;
}

.k-bunny-error-icon {
  width: 2rem;
  height: 2rem;
  margin-bottom: 1rem;
  color: var(--color-red-600);
}

.k-bunny-error-icon svg {
  width: 100%;
  height: 100%;
}

.k-bunny-error-text {
  font-size: var(--text-sm);
  font-weight: 500;
  color: var(--color-red-700);
}
</style>
