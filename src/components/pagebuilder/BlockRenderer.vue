<template>
  <component
    :is="blockComponent"
    :block="block"
    :edit-mode="editMode"
    :global-styles="globalStyles"
    @update="$emit('update', $event)"
    @upload-image="$emit('upload-image', $event)"
  />
</template>

<script>
import { computed } from 'vue';
import LogoBlock from './blocks/LogoBlock.vue';
import HeadingBlock from './blocks/HeadingBlock.vue';
import TextBlock from './blocks/TextBlock.vue';
import ImageBlock from './blocks/ImageBlock.vue';
import SpacerBlock from './blocks/SpacerBlock.vue';
import DividerBlock from './blocks/DividerBlock.vue';
import SocialLinksBlock from './blocks/SocialLinksBlock.vue';
import ButtonBlock from './blocks/ButtonBlock.vue';
import HtmlBlock from './blocks/HtmlBlock.vue';
import ProgressBarBlock from './blocks/ProgressBarBlock.vue';

const BLOCK_COMPONENTS = {
  logo: LogoBlock,
  heading: HeadingBlock,
  text: TextBlock,
  image: ImageBlock,
  spacer: SpacerBlock,
  divider: DividerBlock,
  socialLinks: SocialLinksBlock,
  button: ButtonBlock,
  html: HtmlBlock,
  progressBar: ProgressBarBlock,
};

export default {
  name: 'BlockRenderer',
  props: {
    block: {
      type: Object,
      required: true,
    },
    editMode: {
      type: Boolean,
      default: false,
    },
    globalStyles: {
      type: Object,
      default: () => ({}),
    },
  },
  emits: ['update', 'upload-image'],
  setup(props) {
    const blockComponent = computed(() => {
      return BLOCK_COMPONENTS[props.block.type] || null;
    });

    return {
      blockComponent,
    };
  },
};
</script>
