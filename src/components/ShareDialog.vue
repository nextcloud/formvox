<template>
  <NcModal @close="$emit('close')">
    <div class="share-dialog">
      <h2>{{ t('Share form') }}</h2>

      <p class="share-description">
        {{ t('Share this form with others to collect responses.') }}
      </p>

      <div class="share-link-section">
        <h3>{{ t('Public link') }}</h3>

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
          <p>{{ t('No public link yet. Create one to share your form.') }}</p>
          <NcButton type="primary" :disabled="creatingLink" @click="createShareLink">
            {{ creatingLink ? t('Creating...') : t('Create public link') }}
          </NcButton>
        </div>
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

export default {
  name: 'ShareDialog',
  components: {
    NcModal,
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    CopyIcon,
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
  },
  emits: ['close'],
  setup(props, { emit }) {
    const shareLink = ref(null);
    const shareToken = ref(null);
    const copied = ref(false);
    const creatingLink = ref(false);
    const linkInput = ref(null);

    const linkSettings = reactive({
      passwordProtected: false,
      password: '',
      expires: false,
      expiresAt: null,
    });

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

        showSuccess(t('Public link created'));
      } catch (error) {
        showError(t('Failed to create share link'));
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

    onMounted(() => {
      loadExistingShare();
    });

    return {
      shareLink,
      copied,
      creatingLink,
      linkInput,
      linkSettings,
      createShareLink,
      copyLink,
      togglePassword,
      toggleLinkExpiration,
      savePassword,
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
}

.actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}
</style>
