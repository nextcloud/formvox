<template>
  <div class="block-image" :class="alignmentClass">
    <template v-if="editMode">
      <div v-if="block.settings.imageUrl" class="image-preview">
        <img :src="block.settings.imageUrl" :alt="block.settings.alt || ''" />
        <NcButton type="error" @click="removeImage">
          {{ t('Remove') }}
        </NcButton>
      </div>
      <div v-else class="image-upload">
        <input
          ref="fileInput"
          type="file"
          accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp"
          style="display: none"
          @change="onFileSelect"
        >
        <NcButton @click="$refs.fileInput.click()">
          {{ t('Upload image') }}
        </NcButton>
      </div>
    </template>
    <template v-else>
      <img v-if="block.settings.imageUrl" :src="block.settings.imageUrl" :alt="block.settings.alt || ''" />
    </template>
  </div>
</template>

<script>
import { computed } from 'vue';
import { NcButton } from '@nextcloud/vue';
import { t } from '@/utils/l10n';

export default {
  name: 'ImageBlock',
  components: { NcButton },
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
  },
  emits: ['update', 'upload-image'],
  setup(props, { emit }) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);

    const onFileSelect = (event) => {
      const file = event.target.files[0];
      if (file) {
        emit('upload-image', { blockId: props.block.id, file });
      }
      event.target.value = '';
    };

    const removeImage = () => {
      emit('update', {
        ...props.block,
        settings: { ...props.block.settings, imageUrl: null, imageId: null },
      });
    };

    return { alignmentClass, onFileSelect, removeImage, t };
  },
};
</script>

<style scoped lang="scss">
.block-image {
  padding: 8px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  img {
    max-width: 100%;
    height: auto;
    border-radius: var(--border-radius);
  }

  .image-preview {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;

    img {
      max-width: 300px;
    }
  }
}
</style>
