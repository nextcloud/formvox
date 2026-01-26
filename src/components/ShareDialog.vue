<template>
  <NcModal @close="$emit('close')">
    <div class="share-dialog">
      <h2>{{ t('Share form') }}</h2>

      <p class="share-description">
        {{ t('Share this form with others to collect responses.') }}
      </p>

      <div class="share-link-section">
        <h3>{{ t('Response link') }}</h3>

        <div v-if="shareLink" class="share-link-display">
          <input
            ref="linkInput"
            type="text"
            :value="shareLink"
            readonly
            class="link-input"
          >
          <NcButton @click="copyLink">
            <template #icon>
              <CopyIcon :size="20" />
            </template>
            {{ copied ? t('Copied!') : t('Copy') }}
          </NcButton>
        </div>

        <div v-else class="create-link">
          <p v-if="canShare">{{ t('No link yet. Create one to start collecting responses.') }}</p>
          <p v-else>{{ t('You do not have permission to create a response link.') }}</p>
          <NcButton type="primary" :disabled="creatingLink || !canShare" @click="createShareLink">
            {{ creatingLink ? t('Creating...') : t('Create response link') }}
          </NcButton>
        </div>
      </div>

      <div v-if="shareLink" class="response-settings">
        <h3>{{ t('Response settings') }}</h3>

        <NcCheckboxRadioSwitch
          :model-value="responseSettings.allowAnonymous"
          @update:model-value="updateResponseSetting('anonymous', $event)"
        >
          {{ t('Collect anonymously') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :model-value="responseSettings.allowMultiple"
          @update:model-value="updateResponseSetting('allow_multiple', $event)"
        >
          {{ t('Allow multiple submissions') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :model-value="responseSettings.requireLogin"
          @update:model-value="updateResponseSetting('require_login', $event)"
        >
          {{ t('Require login to respond') }}
        </NcCheckboxRadioSwitch>
      </div>

      <div v-if="shareLink" class="link-settings">
        <h3>{{ t('Link settings') }}</h3>

        <NcCheckboxRadioSwitch
          :model-value="linkSettings.passwordProtected"
          @update:model-value="togglePassword"
        >
          {{ t('Password protect') }}
        </NcCheckboxRadioSwitch>

        <div v-if="linkSettings.passwordProtected" class="password-field">
          <NcTextField
            v-model="linkSettings.password"
            type="password"
            :label="t('Password')"
            :placeholder="t('Enter new password')"
          />
          <NcButton type="primary" @click="savePassword">
            {{ t('Save') }}
          </NcButton>
        </div>

        <NcCheckboxRadioSwitch
          :model-value="linkSettings.expires"
          @update:model-value="toggleLinkExpiration"
        >
          {{ t('Set expiration') }}
        </NcCheckboxRadioSwitch>

        <NcDateTimePicker
          v-if="linkSettings.expires"
          :value="linkSettings.expiresAt"
          type="datetime"
          @update:value="linkSettings.expiresAt = $event"
        />

        <NcCheckboxRadioSwitch
          :model-value="accessRestrictions.enabled"
          @update:model-value="toggleAccessRestrictions"
        >
          {{ t('Restrict to specific users/groups') }}
        </NcCheckboxRadioSwitch>

        <div v-if="accessRestrictions.enabled" class="access-restrictions">
          <p class="restriction-note">
            {{ t('Only selected users and group members can access this form. They will need to log in.') }}
          </p>

          <div class="search-field">
            <NcTextField
              v-model="searchTerm"
              :label="t('Search users and groups')"
              :placeholder="t('Type to search...')"
              @input="searchSharees"
            />
          </div>

          <div v-if="searchResults.users.length || searchResults.groups.length" class="search-results">
            <div v-if="searchResults.users.length" class="result-section">
              <span class="section-label">{{ t('Users') }}</span>
              <div
                v-for="user in searchResults.users"
                :key="'user-' + user.id"
                class="result-item"
                @click="addUser(user)"
              >
                <AccountIcon :size="16" />
                <span>{{ user.displayName }}</span>
              </div>
            </div>

            <div v-if="searchResults.groups.length" class="result-section">
              <span class="section-label">{{ t('Groups') }}</span>
              <div
                v-for="group in searchResults.groups"
                :key="'group-' + group.id"
                class="result-item"
                @click="addGroup(group)"
              >
                <AccountGroupIcon :size="16" />
                <span>{{ group.displayName }}</span>
              </div>
            </div>
          </div>

          <div v-if="accessRestrictions.users.length" class="selected-items">
            <span class="section-label">{{ t('Allowed users') }}</span>
            <div class="chips">
              <div
                v-for="user in accessRestrictions.users"
                :key="'selected-user-' + user.id"
                class="chip"
              >
                <AccountIcon :size="14" />
                <span>{{ user.displayName }}</span>
                <button type="button" class="remove-btn" @click="removeUser(user.id)">×</button>
              </div>
            </div>
          </div>

          <div v-if="accessRestrictions.groups.length" class="selected-items">
            <span class="section-label">{{ t('Allowed groups') }}</span>
            <div class="chips">
              <div
                v-for="group in accessRestrictions.groups"
                :key="'selected-group-' + group.id"
                class="chip"
              >
                <AccountGroupIcon :size="14" />
                <span>{{ group.displayName }}</span>
                <button type="button" class="remove-btn" @click="removeGroup(group.id)">×</button>
              </div>
            </div>
          </div>
        </div>

        <div class="delete-link-section">
          <NcButton type="tertiary" @click="deleteShareLink">
            {{ t('Delete response link') }}
          </NcButton>
        </div>
      </div>

      <div v-if="responseCount > 0" class="responses-section">
        <h3>{{ t('Responses') }}</h3>
        <p class="response-count">
          {{ t('{count} responses collected', { count: responseCount }) }}
        </p>
        <NcButton type="error" @click="confirmDeleteResponses">
          {{ t('Delete all responses') }}
        </NcButton>
      </div>

      <div class="actions">
        <NcButton @click="$emit('close')">
          {{ t('Done') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, reactive, onMounted } from 'vue';
import {
  NcModal,
  NcButton,
  NcTextField,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import CopyIcon from './icons/CopyIcon.vue';
import AccountIcon from 'vue-material-design-icons/Account.vue';
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue';

export default {
  name: 'ShareDialog',
  components: {
    NcModal,
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    CopyIcon,
    AccountIcon,
    AccountGroupIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    form: {
      type: Object,
      required: true,
    },
    canShare: {
      type: Boolean,
      default: true,
    },
  },
  emits: ['close', 'responsesDeleted'],
  setup(props, { emit }) {
    const shareLink = ref(null);
    const shareToken = ref(null);
    const copied = ref(false);
    const creatingLink = ref(false);
    const linkInput = ref(null);
    const responseCount = ref(props.form._index?.response_count || 0);

    const linkSettings = reactive({
      passwordProtected: false,
      password: '',
      expires: false,
      expiresAt: null,
    });

    // Response settings state
    const responseSettings = reactive({
      allowAnonymous: props.form.settings?.anonymous ?? true,
      allowMultiple: props.form.settings?.allow_multiple ?? false,
      requireLogin: props.form.settings?.require_login ?? false,
    });

    // Access restrictions state
    const accessRestrictions = reactive({
      enabled: false,
      users: [],
      groups: [],
    });
    const searchTerm = ref('');
    const searchResults = reactive({ users: [], groups: [] });
    let searchTimeout = null;

    const loadExistingShare = async () => {
      try {
        // Check if form already has a public token
        if (props.form.settings?.public_token) {
          shareToken.value = props.form.settings.public_token;
          const baseUrl = window.location.origin;
          shareLink.value = `${baseUrl}${generateUrl('/apps/formvox/public/{fileId}/{token}', { fileId: props.fileId, token: shareToken.value })}`;

          // Load password setting (check hash since plaintext is removed after save)
          if (props.form.settings.share_password_hash) {
            linkSettings.passwordProtected = true;
            linkSettings.password = '********'; // Don't show actual password
          }

          // Load expiration setting
          if (props.form.settings.share_expires_at) {
            linkSettings.expires = true;
            linkSettings.expiresAt = new Date(props.form.settings.share_expires_at);
          }
        }
      } catch (error) {
        console.error('Error loading shares:', error);
      }
    };

    const createShareLink = async () => {
      creatingLink.value = true;
      try {
        const token = generateShareToken();

        // Save token to form settings via API
        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          {
            settings: {
              ...props.form.settings,
              public_token: token,
            },
          }
        );

        shareToken.value = token;
        const baseUrl = window.location.origin;
        shareLink.value = `${baseUrl}${generateUrl('/apps/formvox/public/{fileId}/{token}', { fileId: props.fileId, token })}`;

        // Update local form object
        props.form.settings.public_token = token;

        showSuccess(t('Response link created'));
      } catch (error) {
        showError(t('Failed to create response link'));
        console.error(error);
      } finally {
        creatingLink.value = false;
      }
    };

    const generateShareToken = () => {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
      let token = '';
      for (let i = 0; i < 15; i++) {
        token += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      return token;
    };

    const copyLink = async () => {
      try {
        await navigator.clipboard.writeText(shareLink.value);
        copied.value = true;
        setTimeout(() => {
          copied.value = false;
        }, 2000);
      } catch (error) {
        // Fallback for older browsers
        if (linkInput.value) {
          linkInput.value.select();
          document.execCommand('copy');
          copied.value = true;
          setTimeout(() => {
            copied.value = false;
          }, 2000);
        }
      }
    };

    const togglePassword = (enabled) => {
      linkSettings.passwordProtected = enabled;
      if (!enabled) {
        linkSettings.password = '';
        saveLinkSettings();
      }
    };

    const savePassword = () => {
      if (linkSettings.password) {
        saveLinkSettings();
      }
    };

    const toggleLinkExpiration = (enabled) => {
      linkSettings.expires = enabled;
      if (enabled) {
        const date = new Date();
        date.setDate(date.getDate() + 7);
        linkSettings.expiresAt = date;
      } else {
        linkSettings.expiresAt = null;
      }
      saveLinkSettings();
    };

    const saveLinkSettings = async () => {
      try {
        const settings = {
          ...props.form.settings,
        };

        // Update password
        if (linkSettings.passwordProtected && linkSettings.password) {
          settings.share_password = linkSettings.password;
        } else {
          settings.share_password = null;
        }

        // Update expiration
        if (linkSettings.expires && linkSettings.expiresAt) {
          settings.share_expires_at = linkSettings.expiresAt.toISOString();
        } else {
          settings.share_expires_at = null;
        }

        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          { settings }
        );

        // Update local form object
        props.form.settings.share_password = settings.share_password;
        props.form.settings.share_expires_at = settings.share_expires_at;

        showSuccess(t('Settings saved'));
      } catch (error) {
        showError(t('Failed to save settings'));
        console.error(error);
      }
    };

    const deleteShareLink = async () => {
      if (!confirm(t('Are you sure you want to delete this link? Anyone with this link will no longer be able to submit responses.'))) {
        return;
      }

      try {
        const settings = {
          ...props.form.settings,
          public_token: null,
          share_password: null,
          share_expires_at: null,
        };

        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          { settings }
        );

        // Update local state
        shareLink.value = null;
        shareToken.value = null;
        linkSettings.passwordProtected = false;
        linkSettings.password = '';
        linkSettings.expires = false;
        linkSettings.expiresAt = null;

        // Update local form object
        props.form.settings.public_token = null;
        delete props.form.settings.share_password_hash;
        props.form.settings.share_expires_at = null;

        showSuccess(t('Response link deleted'));
      } catch (error) {
        showError(t('Failed to delete response link'));
        console.error(error);
      }
    };

    const confirmDeleteResponses = async () => {
      if (!confirm(t('Are you sure you want to delete ALL responses? This action cannot be undone.'))) {
        return;
      }

      try {
        await axios.delete(
          generateUrl('/apps/formvox/api/form/{fileId}/responses', { fileId: props.fileId })
        );

        responseCount.value = 0;
        emit('responsesDeleted');

        showSuccess(t('All responses deleted'));
      } catch (error) {
        showError(t('Failed to delete responses'));
        console.error(error);
      }
    };

    // Access restrictions functions
    const toggleAccessRestrictions = (enabled) => {
      accessRestrictions.enabled = enabled;
      if (!enabled) {
        accessRestrictions.users = [];
        accessRestrictions.groups = [];
        saveAccessRestrictions();
      }
    };

    const searchSharees = () => {
      if (searchTimeout) {
        clearTimeout(searchTimeout);
      }

      searchTimeout = setTimeout(async () => {
        const term = searchTerm.value;
        if (!term || term.length < 2) {
          searchResults.users = [];
          searchResults.groups = [];
          return;
        }

        try {
          const response = await axios.get(
            generateUrl('/apps/formvox/api/sharees'),
            { params: { search: term, limit: 10 } }
          );
          searchResults.users = response.data.users || [];
          searchResults.groups = response.data.groups || [];
        } catch (error) {
          console.error('Search failed:', error);
        }
      }, 300);
    };

    const addUser = (user) => {
      if (!accessRestrictions.users.find(u => u.id === user.id)) {
        accessRestrictions.users.push(user);
        saveAccessRestrictions();
      }
      searchTerm.value = '';
      searchResults.users = [];
      searchResults.groups = [];
    };

    const addGroup = (group) => {
      if (!accessRestrictions.groups.find(g => g.id === group.id)) {
        accessRestrictions.groups.push(group);
        saveAccessRestrictions();
      }
      searchTerm.value = '';
      searchResults.users = [];
      searchResults.groups = [];
    };

    const removeUser = (userId) => {
      accessRestrictions.users = accessRestrictions.users.filter(u => u.id !== userId);
      saveAccessRestrictions();
    };

    const removeGroup = (groupId) => {
      accessRestrictions.groups = accessRestrictions.groups.filter(g => g.id !== groupId);
      saveAccessRestrictions();
    };

    const saveAccessRestrictions = async () => {
      try {
        const settings = {
          ...props.form.settings,
          allowed_users: accessRestrictions.users.map(u => u.id),
          allowed_groups: accessRestrictions.groups.map(g => g.id),
        };

        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          { settings }
        );

        props.form.settings.allowed_users = settings.allowed_users;
        props.form.settings.allowed_groups = settings.allowed_groups;

        showSuccess(t('Access restrictions saved'));
      } catch (error) {
        showError(t('Failed to save access restrictions'));
        console.error(error);
      }
    };

    const loadAccessRestrictions = () => {
      const users = props.form.settings?.allowed_users || [];
      const groups = props.form.settings?.allowed_groups || [];

      accessRestrictions.enabled = users.length > 0 || groups.length > 0;
      accessRestrictions.users = users.map(id => ({ id, displayName: id }));
      accessRestrictions.groups = groups.map(id => ({ id, displayName: id }));
    };

    const updateResponseSetting = async (key, value) => {
      responseSettings[key === 'anonymous' ? 'allowAnonymous' : key === 'allow_multiple' ? 'allowMultiple' : 'requireLogin'] = value;

      try {
        const settings = {
          ...props.form.settings,
          [key]: value,
        };

        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          { settings }
        );

        props.form.settings[key] = value;
        showSuccess(t('Settings saved'));
      } catch (error) {
        showError(t('Failed to save settings'));
        console.error(error);
      }
    };

    onMounted(() => {
      loadExistingShare();
      loadAccessRestrictions();
    });

    return {
      shareLink,
      copied,
      creatingLink,
      linkInput,
      linkSettings,
      responseSettings,
      responseCount,
      accessRestrictions,
      searchTerm,
      searchResults,
      createShareLink,
      copyLink,
      togglePassword,
      toggleLinkExpiration,
      savePassword,
      deleteShareLink,
      confirmDeleteResponses,
      toggleAccessRestrictions,
      searchSharees,
      addUser,
      addGroup,
      removeUser,
      removeGroup,
      updateResponseSetting,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.share-dialog {
  padding: 20px;
  min-width: 450px;

  h2 {
    margin: 0 0 8px;
  }

  .share-description {
    color: var(--color-text-maxcontrast);
    margin: 0 0 24px;
  }

  h3 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 600;
  }
}

.share-link-section {
  margin-bottom: 24px;

  .share-link-display {
    display: flex;
    gap: 8px;

    .link-input {
      flex: 1;
      padding: 8px 12px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      background: var(--color-background-hover);
      font-size: 14px;
    }
  }

  .create-link {
    text-align: center;
    padding: 20px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);

    p {
      margin: 0 0 16px;
      color: var(--color-text-maxcontrast);
    }
  }
}

.response-settings {
  margin-bottom: 24px;
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
}

.link-settings {
  margin-bottom: 24px;
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  .password-field {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    margin-top: 8px;
    margin-bottom: 16px;
  }

  .delete-link-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
  }
}

.responses-section {
  margin-bottom: 24px;
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  h3 {
    margin: 0 0 8px;
  }

  .response-count {
    margin: 0 0 12px;
    color: var(--color-text-maxcontrast);
  }
}

.actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.access-restrictions {
  margin-top: 12px;
  padding: 12px;
  background: var(--color-background-dark);
  border-radius: var(--border-radius);

  .restriction-note {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
  }

  .search-field {
    margin-bottom: 12px;
  }

  .search-results {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 12px;
  }

  .result-section {
    .section-label {
      display: block;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 600;
      color: var(--color-text-maxcontrast);
      background: var(--color-background-hover);
    }
  }

  .result-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;

    &:hover {
      background: var(--color-background-hover);
    }
  }

  .selected-items {
    margin-top: 12px;

    .section-label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 8px;
    }
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    background: var(--color-primary-element-light);
    border-radius: var(--border-radius-pill);
    font-size: 13px;

    .remove-btn {
      background: none;
      border: none;
      cursor: pointer;
      font-size: 16px;
      line-height: 1;
      padding: 0;
      margin-left: 2px;
      color: var(--color-text-maxcontrast);

      &:hover {
        color: var(--color-error);
      }
    }
  }
}
</style>
