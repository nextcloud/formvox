<template>
  <div class="block-html" :class="alignmentClass">
    <div v-if="block.settings.content" v-html="sanitizedHtml"></div>
    <p v-else-if="editMode" class="placeholder">{{ t('Enter HTML in settings') }}</p>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';
import DOMPurify from 'dompurify';

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
      return DOMPurify.sanitize(props.block.settings.content, {
        ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre', 'span', 'div'],
        ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'style'],
      });
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
