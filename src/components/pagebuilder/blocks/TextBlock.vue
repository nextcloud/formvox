<template>
  <div class="block-text" :class="alignmentClass">
    <p v-if="block.settings.content" :style="textStyle">{{ block.settings.content }}</p>
    <p v-else-if="editMode" class="placeholder">{{ t('Enter text...') }}</p>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';

export default {
  name: 'TextBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
    globalStyles: { type: Object, default: () => ({}) },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'left'}`);

    const textStyle = computed(() => {
      const color = props.block.settings.color;
      if (color) {
        return { color };
      }
      return {};
    });

    return { alignmentClass, textStyle, t };
  },
};
</script>

<style scoped lang="scss">
.block-text {
  padding: 8px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  p {
    margin: 0;
    line-height: 1.6;
  }

  .placeholder {
    color: var(--color-text-maxcontrast);
    font-style: italic;
  }
}
</style>
