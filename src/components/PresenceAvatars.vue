<template>
  <div v-if="editors.length > 0" class="presence-avatars">
    <div
      v-for="editor in editors"
      :key="editor.userId"
      class="presence-avatar"
      :title="editor.displayName"
    >
      <NcAvatar :user="editor.userId" :display-name="editor.displayName" :size="28" />
    </div>
    <span class="presence-label">{{ t('{count} editing', { count: editors.length }) }}</span>
  </div>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue';
import { t } from '@/utils/l10n';

export default {
  name: 'PresenceAvatars',
  components: {
    NcAvatar,
  },
  props: {
    editors: {
      type: Array,
      default: () => [],
    },
  },
  setup() {
    return { t };
  },
};
</script>

<style scoped lang="scss">
.presence-avatars {
  display: flex;
  align-items: center;
  gap: 4px;

  .presence-avatar {
    position: relative;

    &::after {
      content: '';
      position: absolute;
      bottom: 0;
      right: 0;
      width: 8px;
      height: 8px;
      background: #2ecc71;
      border-radius: 50%;
      border: 2px solid var(--color-main-background);
    }
  }

  .presence-label {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-left: 4px;
  }
}
</style>
