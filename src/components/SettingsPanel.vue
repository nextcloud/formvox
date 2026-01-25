<template>
  <NcAppSidebar
    :name="t('Settings')"
    @close="$emit('close')"
  >
    <NcAppSidebarTab id="settings" :name="t('Settings')" :order="1">
      <template #icon>
        <CogIcon :size="20" />
      </template>

      <div class="settings-section">
        <h3>{{ t('Responses') }}</h3>

        <NcCheckboxRadioSwitch
          :model-value="localSettings.anonymous"
          :disabled="!canEditSettings"
          @update:model-value="updateSetting('anonymous', $event)"
        >
          {{ t('Allow anonymous responses') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :model-value="localSettings.allow_multiple"
          :disabled="!canEditSettings"
          @update:model-value="updateSetting('allow_multiple', $event)"
        >
          {{ t('Allow multiple submissions') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :model-value="localSettings.require_login"
          :disabled="!canEditSettings"
          @update:model-value="updateSetting('require_login', $event)"
        >
          {{ t('Require login to respond') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="settings-section">
        <h3>{{ t('Results visibility') }}</h3>

        <div class="radio-group">
          <NcCheckboxRadioSwitch
            :model-value="localSettings.show_results === 'never'"
            type="radio"
            name="show_results"
            :disabled="!canEditSettings"
            @update:model-value="updateSetting('show_results', 'never')"
          >
            {{ t('Never show results') }}
          </NcCheckboxRadioSwitch>

          <NcCheckboxRadioSwitch
            :model-value="localSettings.show_results === 'after_submit'"
            type="radio"
            name="show_results"
            :disabled="!canEditSettings"
            @update:model-value="updateSetting('show_results', 'after_submit')"
          >
            {{ t('Show after submission') }}
          </NcCheckboxRadioSwitch>

          <NcCheckboxRadioSwitch
            :model-value="localSettings.show_results === 'always'"
            type="radio"
            name="show_results"
            :disabled="!canEditSettings"
            @update:model-value="updateSetting('show_results', 'always')"
          >
            {{ t('Always show results') }}
          </NcCheckboxRadioSwitch>
        </div>
      </div>

      <div class="settings-section">
        <h3>{{ t('Expiration') }}</h3>

        <NcCheckboxRadioSwitch
          :model-value="hasExpiration"
          :disabled="!canEditSettings"
          @update:model-value="toggleExpiration"
        >
          {{ t('Set expiration date') }}
        </NcCheckboxRadioSwitch>

        <NcDateTimePicker
          v-if="hasExpiration"
          :value="localSettings.expires_at ? new Date(localSettings.expires_at) : null"
          type="datetime"
          :disabled="!canEditSettings"
          @update:value="updateExpiration"
        />
      </div>
    </NcAppSidebarTab>

    <NcAppSidebarTab id="permissions" :name="t('Permissions')" :order="2">
      <template #icon>
        <UsersIcon :size="20" />
      </template>

      <div class="permissions-section">
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
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script>
import { t } from '@/utils/l10n';
import { reactive, computed } from 'vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import {
  NcAppSidebar,
  NcAppSidebarTab,
  NcButton,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
} from '@nextcloud/vue';
import CogIcon from './icons/CogIcon.vue';
import UsersIcon from './icons/UsersIcon.vue';
import ShareIcon from './icons/ShareIcon.vue';

export default {
  name: 'SettingsPanel',
  components: {
    NcAppSidebar,
    NcAppSidebarTab,
    NcButton,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    CogIcon,
    UsersIcon,
    ShareIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    settings: {
      type: Object,
      required: true,
    },
    permissions: {
      type: Object,
      required: true,
    },
    canEditSettings: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['update:settings', 'update:permissions', 'close'],
  setup(props, { emit }) {
    const localSettings = reactive({ ...props.settings });

    const hasExpiration = computed(() => {
      return localSettings.expires_at !== null;
    });

    const updateSetting = (key, value) => {
      localSettings[key] = value;
      emit('update:settings', { ...localSettings });
    };

    const toggleExpiration = (enabled) => {
      if (enabled) {
        const date = new Date();
        date.setDate(date.getDate() + 30);
        localSettings.expires_at = date.toISOString();
      } else {
        localSettings.expires_at = null;
      }
      emit('update:settings', { ...localSettings });
    };

    const updateExpiration = (date) => {
      localSettings.expires_at = date ? date.toISOString() : null;
      emit('update:settings', { ...localSettings });
    };

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
      localSettings,
      hasExpiration,
      updateSetting,
      toggleExpiration,
      updateExpiration,
      openNativeShareDialog,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.settings-section,
.permissions-section {
  margin-bottom: 24px;

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

.radio-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
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
