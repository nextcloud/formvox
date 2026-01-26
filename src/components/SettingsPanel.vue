<template>
  <NcAppSidebar
    :name="t('Collaboration')"
    @close="$emit('close')"
  >
    <div class="collaboration-content">
      <div class="collaboration-section">
        <h3>{{ t('Access control') }}</h3>
        <p class="section-description">
          {{ t('Control who can edit this form using Nextcloud\'s native sharing.') }}
        </p>

        <div class="native-share-info">
          <p>{{ t('Use Nextcloud sharing to control access:') }}</p>
          <ul>
            <li><strong>{{ t('Can view') }}</strong> - {{ t('Can fill in and view the form') }}</li>
            <li><strong>{{ t('Can edit') }}</strong> - {{ t('Can modify questions and settings') }}</li>
            <li><strong>{{ t('Can delete') }}</strong> - {{ t('Full admin access') }}</li>
          </ul>
        </div>

        <NcButton type="primary" @click="openNativeShareDialog">
          <template #icon>
            <ShareIcon :size="20" />
          </template>
          {{ t('Manage sharing') }}
        </NcButton>
      </div>
    </div>
  </NcAppSidebar>
</template>

<script>
import { t } from '@/utils/l10n';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import {
  NcAppSidebar,
  NcButton,
} from '@nextcloud/vue';
import ShareIcon from './icons/ShareIcon.vue';

export default {
  name: 'SettingsPanel',
  components: {
    NcAppSidebar,
    NcButton,
    ShareIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
  },
  emits: ['close'],
  setup(props) {
    const openNativeShareDialog = async () => {
      // Try legacy API (NC < 33) - works when Files app is loaded
      if (window.OCA?.Files?.Sidebar) {
        try {
          const response = await axios.get(
            generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId })
          );
          const filePath = response.data.path;

          window.OCA.Files.Sidebar.open(filePath);
          window.OCA.Files.Sidebar.setActiveTab('sharing');
          return;
        } catch (e) {
          console.error('Failed to open sidebar:', e);
        }
      }

      // Fallback: redirect to Files app with the file selected and sharing tab open
      window.location.href = generateUrl('/apps/files/?fileid={fileId}&openfile=true', {
        fileId: props.fileId
      });
    };

    return {
      openNativeShareDialog,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.collaboration-content {
  padding: 16px;
}

.collaboration-section {
  h3 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 600;
  }

  .section-description {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 16px;
  }
}

.native-share-info {
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  padding: 16px;
  margin-bottom: 16px;

  p {
    margin: 0 0 8px;
    font-weight: 500;
  }

  ul {
    margin: 0;
    padding-left: 20px;

    li {
      margin-bottom: 4px;
      font-size: 13px;
    }
  }
}
</style>
