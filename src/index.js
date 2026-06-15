import BunnyVideoPreview from "./components/BunnyVideoPreview.vue";
import BunnyVideoUpload from "./components/BunnyVideoUpload.vue";

panel.plugin("jonasfeige/kirby-bunny-stream", {
  components: {
    "k-bunny-video-preview": BunnyVideoPreview
  },
  sections: {
    "bunny-video-upload": BunnyVideoUpload
  }
});
