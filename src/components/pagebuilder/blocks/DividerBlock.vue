<template>
  <div class="block-divider" :class="alignmentClass">
    <hr :style="dividerStyle" />
  </div>
</template>

<script>
import { computed } from 'vue';

export default {
  name: 'DividerBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);

    const dividerStyle = computed(() => ({
      borderColor: props.block.settings.color || 'var(--color-border)',
      borderStyle: props.block.settings.style || 'solid',
      borderWidth: `${props.block.settings.thickness || 1}px 0 0 0`,
    }));

    return { alignmentClass, dividerStyle };
  },
};
</script>

<style scoped lang="scss">
.block-divider {
  padding: 16px 0;

  hr {
    margin: 0;
    border: none;
    width: 100%;
  }

  &.align-left hr { width: 50%; margin-right: auto; }
  &.align-center hr { width: 80%; margin: 0 auto; }
  &.align-right hr { width: 50%; margin-left: auto; }
}
</style>
