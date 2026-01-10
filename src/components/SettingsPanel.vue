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
          {{ t('Control who can do what with this form.') }}
        </p>

        <div class="permission-list">
          <div v-for="role in localPermissions.roles" :key="getRoleKey(role)" class="permission-item">
            <div class="role-info">
              <span v-if="role.user" class="role-name">
                <UserIcon :size="16" />
                {{ role.user }}
              </span>
              <span v-else-if="role.group" class="role-name">
                <GroupIcon :size="16" />
                {{ role.group }}
              </span>
              <span v-else-if="role.type === 'public'" class="role-name">
                <GlobeIcon :size="16" />
                {{ t('Public') }}
              </span>
            </div>

            <select
              :value="role.role"
              :disabled="!canEditSettings"
              @change="updateRole(role, $event.target.value)"
            >
              <option value="respondent">{{ t('Respondent') }}</option>
              <option value="viewer">{{ t('Viewer') }}</option>
              <option value="editor">{{ t('Editor') }}</option>
              <option value="admin">{{ t('Admin') }}</option>
            </select>

            <NcButton type="tertiary" :disabled="!canEditSettings" @click="removeRole(role)">
              <template #icon>
                <CloseIcon :size="16" />
              </template>
            </NcButton>
          </div>
        </div>

        <div class="add-permission">
          <NcSelect
            v-model="newRoleUser"
            :placeholder="t('Add user or group...')"
            :options="[]"
            :disabled="!canEditSettings"
          />
          <NcButton :disabled="!canEditSettings" @click="addRole">
            {{ t('Add') }}
          </NcButton>
        </div>
      </div>
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, reactive, computed } from 'vue';
import {
  NcAppSidebar,
  NcAppSidebarTab,
  NcButton,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
  NcSelect,
} from '@nextcloud/vue';
import CogIcon from './icons/CogIcon.vue';
import UsersIcon from './icons/UsersIcon.vue';
import UserIcon from './icons/UserIcon.vue';
import GroupIcon from './icons/GroupIcon.vue';
import GlobeIcon from './icons/GlobeIcon.vue';
import CloseIcon from './icons/CloseIcon.vue';

export default {
  name: 'SettingsPanel',
  components: {
    NcAppSidebar,
    NcAppSidebarTab,
    NcButton,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    NcSelect,
    CogIcon,
    UsersIcon,
    UserIcon,
    GroupIcon,
    GlobeIcon,
    CloseIcon,
  },
  props: {
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
    const localPermissions = reactive({ ...props.permissions });
    const newRoleUser = ref(null);

    const hasExpiration = computed(() => {
      return localSettings.expires_at !== null;
    });

    const updateSetting = (key, value) => {
      localSettings[key] = value;
      emit('update:settings', { ...localSettings });
    };

    const toggleExpiration = (enabled) => {
      if (enabled) {
        // Set default expiration to 30 days from now
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

    const getRoleKey = (role) => {
      return role.user || role.group || role.type || 'unknown';
    };

    const updateRole = (role, newRole) => {
      const index = localPermissions.roles.indexOf(role);
      if (index > -1) {
        localPermissions.roles[index].role = newRole;
        emit('update:permissions', { ...localPermissions });
      }
    };

    const removeRole = (role) => {
      const index = localPermissions.roles.indexOf(role);
      if (index > -1) {
        localPermissions.roles.splice(index, 1);
        emit('update:permissions', { ...localPermissions });
      }
    };

    const addRole = () => {
      if (newRoleUser.value) {
        localPermissions.roles.push({
          user: newRoleUser.value,
          role: 'respondent',
        });
        newRoleUser.value = null;
        emit('update:permissions', { ...localPermissions });
      }
    };

    return {
      localSettings,
      localPermissions,
      newRoleUser,
      hasExpiration,
      updateSetting,
      toggleExpiration,
      updateExpiration,
      getRoleKey,
      updateRole,
      removeRole,
      addRole,
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

.permission-list {
  margin-bottom: 16px;

  .permission-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border-bottom: 1px solid var(--color-border);

    .role-info {
      flex: 1;

      .role-name {
        display: flex;
        align-items: center;
        gap: 6px;
      }
    }

    select {
      padding: 4px 8px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      background: var(--color-main-background);
    }
  }
}

.add-permission {
  display: flex;
  gap: 8px;
}
</style>
