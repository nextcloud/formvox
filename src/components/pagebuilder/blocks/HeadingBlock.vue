<template>
  <div class="block-heading" :class="alignmentClass">
    <template v-if="editMode">
      <component :is="headingTag" class="heading-preview" :style="headingStyle">
        {{ block.settings.text || t('Heading') }}
      </component>
    </template>
    <template v-else>
      <component :is="headingTag" :style="headingStyle">
        {{ block.settings.text }}
      </component>
    </template>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';

export default {
  name: 'HeadingBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
    globalStyles: { type: Object, default: () => ({}) },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);
    const headingTag = computed(() => props.block.settings.level || 'h1');

    const headingStyle = computed(() => {
      const color = props.block.settings.color;
      if (color) {
        return { color };
      }
      return {};
    });

    return { alignmentClass, headingTag, headingStyle, t };
  },
};
</script>

<style scoped lang="scss">
.block-heading {
  padding: 8px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  h1, h2, h3, h4, h5, h6 {
    margin: 0;
  }

  .heading-preview {
    color: var(--color-main-text);
  }
}
</style>
