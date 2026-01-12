<template>
  <div class="block-progress-bar" :class="alignmentClass">
    <div class="progress-container">
      <div class="progress-bar" :style="progressStyle">
        <span v-if="block.settings.showPercentage" class="progress-text">
          {{ displayProgress }}%
        </span>
      </div>
    </div>
    <p v-if="block.settings.label" class="progress-label">{{ block.settings.label }}</p>
  </div>
</template>

<script>
import { computed } from 'vue';

export default {
  name: 'ProgressBarBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
    globalStyles: { type: Object, default: () => ({}) },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);

    const displayProgress = computed(() => {
      // Use progress if set (for multi-page forms), otherwise use demoProgress
      return props.block.settings.progress ?? props.block.settings.demoProgress ?? 50;
    });

    const progressStyle = computed(() => ({
      width: `${displayProgress.value}%`,
      backgroundColor: props.block.settings.color || props.globalStyles.primaryColor || '#0082c9',
    }));

    return { alignmentClass, displayProgress, progressStyle };
  },
};
</script>

<style scoped lang="scss">
.block-progress-bar {
  padding: 12px 0;

  &.align-left .progress-container { margin-right: auto; }
  &.align-center .progress-container { margin: 0 auto; }
  &.align-right .progress-container { margin-left: auto; }

  .progress-container {
    width: 100%;
    max-width: 400px;
    height: 24px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    overflow: hidden;
  }

  .progress-bar {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: width 0.3s ease;
    border-radius: var(--border-radius-large);
  }

  .progress-text {
    color: white;
    font-size: 12px;
    font-weight: 500;
  }

  .progress-label {
    text-align: center;
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
  }
}
</style>
