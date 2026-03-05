<template>
  <div v-if="editors.length > 0" class="presence-indicator">
    <div class="presence-avatars-stack">
      <div
        v-for="(editor, index) in editors.slice(0, 3)"
        :key="editor.userId"
        class="presence-avatar-wrapper"
        :style="{ zIndex: editors.length - index }"
        :title="editor.displayName"
      >
        <NcAvatar :user="editor.userId" :display-name="editor.displayName" :size="28" />
      </div>
    </div>
    <span class="presence-text">
      {{ editors.length === 1
        ? editors[0].displayName
        : t('{count} others editing', { count: editors.length })
      }}
    </span>
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
.presence-indicator {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px 4px 4px;
  background: var(--color-background-hover);
  border-radius: 20px;
  border: 1px solid var(--color-border);
}

.presence-avatars-stack {
  display: flex;
  flex-direction: row;

  .presence-avatar-wrapper {
    margin-left: -8px;
    position: relative;

    &:first-child {
      margin-left: 0;
    }

    :deep(.avatardiv) {
      border: 2px solid var(--color-main-background);
      border-radius: 50%;
    }
  }
}

.presence-text {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
}
</style>
