<template>
  <div class="block-html" :class="alignmentClass">
    <div v-if="block.settings.content" v-html="sanitizedHtml"></div>
    <p v-else-if="editMode" class="placeholder">{{ t('formvox', 'Enter HTML in settings') }}</p>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';
import { sanitizeBlockHtml } from '@/utils/blockHtml';

export default {
  name: 'HtmlBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'left'}`);

    const sanitizedHtml = computed(() => {
      if (!props.block.settings.content) return '';
      return sanitizeBlockHtml(props.block.settings.content);
    });

    return { alignmentClass, sanitizedHtml, t };
  },
};
</script>

<style scoped lang="scss">
.block-html {
  padding: 8px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  .placeholder {
    color: var(--color-text-maxcontrast);
    font-style: italic;
    margin: 0;
  }
}
</style>
