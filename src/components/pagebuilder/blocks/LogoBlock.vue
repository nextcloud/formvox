<template>
  <div class="block-logo" :class="alignmentClass">
    <template v-if="editMode">
      <div v-if="block.settings.imageUrl" class="logo-preview">
        <img :src="block.settings.imageUrl" alt="Logo" />
        <NcButton type="error" @click="removeImage">
          {{ t('Remove') }}
        </NcButton>
      </div>
      <div v-else class="logo-upload">
        <input
          ref="fileInput"
          type="file"
          accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp"
          style="display: none"
          @change="onFileSelect"
        >
        <NcButton @click="$refs.fileInput.click()">
          {{ t('Upload logo') }}
        </NcButton>
        <p class="hint">{{ t('PNG, JPEG, SVG, GIF or WebP. Max 2MB.') }}</p>
      </div>
    </template>
    <template v-else>
      <img v-if="block.settings.imageUrl" :src="block.settings.imageUrl" alt="Logo" />
    </template>
  </div>
</template>

<script>
import { computed } from 'vue';
import { NcButton } from '@nextcloud/vue';
import { t } from '@/utils/l10n';

export default {
  name: 'LogoBlock',
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
.block-logo {
  padding: 8px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  img {
    max-width: 200px;
    max-height: 80px;
    object-fit: contain;
  }

  .logo-preview {
    display: inline-flex;
    align-items: center;
    gap: 12px;
  }

  .logo-upload {
    .hint {
      color: var(--color-text-maxcontrast);
      font-size: 12px;
      margin-top: 4px;
    }
  }
}
</style>
