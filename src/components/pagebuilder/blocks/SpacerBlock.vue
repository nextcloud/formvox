<template>
  <div class="block-spacer" :style="spacerStyle">
    <template v-if="editMode">
      <div class="spacer-indicator">
        {{ height }}px
      </div>
    </template>
  </div>
</template>

<script>
import { computed } from 'vue';

export default {
  name: 'SpacerBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
  },
  setup(props) {
    const height = computed(() => {
      const size = props.block.settings.size || 'medium';
      const sizes = { small: 16, medium: 32, large: 64 };
      return props.block.settings.customHeight || sizes[size] || 32;
    });

    const spacerStyle = computed(() => ({
      height: `${height.value}px`,
    }));

    return { height, spacerStyle };
  },
};
</script>

<style scoped lang="scss">
.block-spacer {
  position: relative;
  width: 100%;

  .spacer-indicator {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    padding: 4px 8px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    font-size: 11px;
    color: var(--color-text-maxcontrast);
  }
}
</style>
