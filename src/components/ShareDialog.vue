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

        <NcTextField
          v-if="linkSettings.passwordProtected"
          v-model="linkSettings.password"
          type="password"
          :label="t('Password')"
        />

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

      <div class="share-internal">
        <h3>{{ t('Share with Nextcloud users') }}</h3>
        <p class="share-internal-description">
          {{ t('You can also share via the Files app using standard Nextcloud sharing.') }}
        </p>
        <NcButton @click="openFilesShare">
          {{ t('Open in Files') }}
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
          shareLink.value = `${baseUrl}${generateUrl('/apps/formvox/public/{token}', { token: shareToken.value })}`;
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
        shareLink.value = `${baseUrl}${generateUrl('/apps/formvox/public/{token}', { token })}`;

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
    };

    const openFilesShare = () => {
      // Open the file in the Files app
      window.location.href = generateUrl('/apps/files/?fileid={fileId}', { fileId: props.fileId });
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
      openFilesShare,
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
}

.share-internal {
  margin-bottom: 24px;

  .share-internal-description {
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
    font-size: 14px;
  }
}

.actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}
</style>
