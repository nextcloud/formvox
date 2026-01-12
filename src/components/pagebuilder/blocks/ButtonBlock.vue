<template>
  <div class="block-button" :class="alignmentClass">
    <a
      :href="buttonUrl"
      class="button-link"
      :class="{ 'edit-mode': editMode }"
      :style="buttonStyle"
      :target="block.settings.newTab ? '_blank' : '_self'"
      rel="noopener noreferrer"
    >
      {{ block.settings.text || t('Button') }}
    </a>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';

// Ensure URL has a protocol
const normalizeUrl = (url) => {
  if (!url) return '#';
  // Already has protocol
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('mailto:') || url.startsWith('tel:')) {
    return url;
  }
  // Add https:// by default
  return `https://${url}`;
};

export default {
  name: 'ButtonBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
    globalStyles: { type: Object, default: () => ({}) },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);

    const buttonUrl = computed(() => {
      if (props.editMode) return undefined;
      return normalizeUrl(props.block.settings.url);
    });

    const buttonStyle = computed(() => {
      const bgColor = props.block.settings.backgroundColor || props.globalStyles.primaryColor || '#0082c9';
      const textColor = props.block.settings.textColor || '#ffffff';
      return {
        backgroundColor: bgColor,
        color: textColor,
        borderColor: bgColor,
      };
    });

    return { alignmentClass, buttonUrl, buttonStyle, t };
  },
};
</script>

<style scoped lang="scss">
.block-button {
  padding: 12px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  .button-link {
    display: inline-block;
    padding: 10px 24px;
    border-radius: var(--border-radius-large);
    font-weight: 500;
    text-decoration: none;
    border: 2px solid;
    transition: opacity 0.2s;

    &:hover {
      opacity: 0.9;
    }

    &.edit-mode {
      cursor: default;
      pointer-events: none;
    }
  }
}
</style>
