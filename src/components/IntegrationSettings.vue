<template>
  <div class="integration-settings">
    <!-- API Keys Section -->
    <div class="section">
      <h3>
        <ApiIcon :size="18" />
        {{ t('API Access') }}
      </h3>
      <p class="section-description">
        {{ t('Create API keys to integrate this form with external systems like Power Automate, Zapier, or custom applications.') }}
      </p>

      <div v-if="apiKeys.length" class="api-keys-list">
        <div v-for="key in apiKeys" :key="key.id" class="api-key-item">
          <div class="key-info">
            <span class="key-name">{{ key.name }}</span>
            <span class="key-id">{{ key.id }}</span>
            <span class="key-permissions">{{ formatPermissions(key.permissions) }}</span>
          </div>
          <NcButton type="tertiary" @click="deleteApiKey(key.id)">
            <template #icon>
              <DeleteIcon :size="18" />
            </template>
          </NcButton>
        </div>
      </div>

      <div v-if="newApiKey" class="new-key-display">
        <NcNoteCard type="warning">
          <p><strong>{{ t('Save this API key now!') }}</strong></p>
          <p>{{ t('This is the only time you will see the full key. Copy it and store it securely.') }}</p>
          <div class="key-value">
            <code>{{ newApiKey.key }}</code>
            <NcButton type="tertiary" @click="copyApiKey">
              <template #icon>
                <CopyIcon :size="18" />
              </template>
              {{ apiKeyCopied ? t('Copied!') : t('Copy') }}
            </NcButton>
          </div>
        </NcNoteCard>
      </div>

      <div class="create-api-key">
        <NcTextField
          v-model="newKeyName"
          :label="t('Key name')"
          :placeholder="t('e.g., Power Automate')"
        />
        <div class="permissions-select">
          <label class="permissions-label">{{ t('Permissions') }}</label>
          <div class="permission-checkboxes">
            <NcCheckboxRadioSwitch
              :model-value="newKeyPermissions.read_form"
              @update:model-value="newKeyPermissions.read_form = $event"
            >
              {{ t('Read form') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
              :model-value="newKeyPermissions.read_responses"
              @update:model-value="newKeyPermissions.read_responses = $event"
            >
              {{ t('Read responses') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
              :model-value="newKeyPermissions.write_responses"
              @update:model-value="newKeyPermissions.write_responses = $event"
            >
              {{ t('Write responses') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
              :model-value="newKeyPermissions.delete_responses"
              @update:model-value="newKeyPermissions.delete_responses = $event"
            >
              {{ t('Delete responses') }}
            </NcCheckboxRadioSwitch>
          </div>
        </div>
        <NcButton type="primary" @click="createApiKey" :disabled="!newKeyName || creatingKey">
          {{ creatingKey ? t('Creating...') : t('Create API Key') }}
        </NcButton>
      </div>
    </div>

    <!-- Webhooks Section -->
    <div class="section">
      <h3>
        <WebhookIcon :size="18" />
        {{ t('Webhooks') }}
      </h3>
      <p class="section-description">
        {{ t('Get notified when responses are submitted. Webhooks send data to your URL in real-time.') }}
      </p>

      <div v-if="webhooks.length" class="webhooks-list">
        <div v-for="webhook in webhooks" :key="webhook.id" class="webhook-item">
          <div class="webhook-info">
            <span class="webhook-name">{{ webhook.name }}</span>
            <span class="webhook-url">{{ webhook.url }}</span>
            <span class="webhook-events">{{ formatEvents(webhook.events) }}</span>
          </div>
          <div class="webhook-actions">
            <NcCheckboxRadioSwitch
              :checked="webhook.enabled"
              @update:checked="toggleWebhook(webhook.id, $event)"
            />
            <NcButton type="tertiary" @click="deleteWebhook(webhook.id)">
              <template #icon>
                <DeleteIcon :size="18" />
              </template>
            </NcButton>
          </div>
        </div>
      </div>

      <div v-if="newWebhookSecret" class="new-webhook-display">
        <NcNoteCard type="warning">
          <p><strong>{{ t('Save this webhook secret now!') }}</strong></p>
          <p>{{ t('Use this secret to verify webhook signatures. It will not be shown again.') }}</p>
          <div class="key-value">
            <code>{{ newWebhookSecret }}</code>
            <NcButton type="tertiary" @click="copyWebhookSecret">
              <template #icon>
                <CopyIcon :size="18" />
              </template>
              {{ webhookSecretCopied ? t('Copied!') : t('Copy') }}
            </NcButton>
          </div>
        </NcNoteCard>
      </div>

      <div class="create-webhook">
        <NcTextField
          v-model="newWebhookName"
          :label="t('Webhook name')"
          :placeholder="t('e.g., Notify on submission')"
        />
        <NcTextField
          v-model="newWebhookUrl"
          :label="t('Webhook URL')"
          :placeholder="t('https://your-server.com/webhook')"
        />
        <div class="events-select">
          <label class="events-label">{{ t('Events') }}</label>
          <div class="event-checkboxes">
            <NcCheckboxRadioSwitch
              :model-value="newWebhookEvents.created"
              @update:model-value="newWebhookEvents.created = $event"
            >
              {{ t('Response created') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
              :model-value="newWebhookEvents.updated"
              @update:model-value="newWebhookEvents.updated = $event"
            >
              {{ t('Response updated') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
              :model-value="newWebhookEvents.deleted"
              @update:model-value="newWebhookEvents.deleted = $event"
            >
              {{ t('Response deleted') }}
            </NcCheckboxRadioSwitch>
          </div>
        </div>
        <NcButton type="primary" @click="createWebhook" :disabled="!newWebhookUrl || creatingWebhook">
          {{ creatingWebhook ? t('Creating...') : t('Create Webhook') }}
        </NcButton>
      </div>
    </div>

    <!-- API Documentation -->
    <div class="section api-docs">
      <h3>{{ t('API Documentation') }}</h3>
      <p class="section-description">{{ t('Use these endpoints to interact with this form programmatically.') }}</p>

      <div class="endpoint">
        <code class="method get">GET</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}</code>
        <span class="desc">{{ t('Get form definition') }}</span>
      </div>
      <div class="endpoint">
        <code class="method get">GET</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/schema</code>
        <span class="desc">{{ t('Get JSON schema') }}</span>
      </div>
      <div class="endpoint">
        <code class="method get">GET</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/responses</code>
        <span class="desc">{{ t('Get all responses') }}</span>
      </div>
      <div class="endpoint">
        <code class="method get">GET</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/responses/{id}</code>
        <span class="desc">{{ t('Get single response') }}</span>
      </div>
      <div class="endpoint">
        <code class="method post">POST</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/responses</code>
        <span class="desc">{{ t('Create response') }}</span>
      </div>
      <div class="endpoint">
        <code class="method put">PUT</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/responses/{id}</code>
        <span class="desc">{{ t('Update response') }}</span>
      </div>
      <div class="endpoint">
        <code class="method delete">DELETE</code>
        <code class="path">/api/v1/external/forms/{{ fileId }}/responses/{id}</code>
        <span class="desc">{{ t('Delete response') }}</span>
      </div>

      <p class="auth-note">
        {{ t('Include your API key in the X-FormVox-API-Key header.') }}
      </p>
    </div>
  </div>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, reactive, computed, onMounted } from 'vue';
import { NcButton, NcTextField, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import CopyIcon from './icons/CopyIcon.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import ApiIcon from 'vue-material-design-icons/Api.vue';
import WebhookIcon from 'vue-material-design-icons/Webhook.vue';

export default {
  name: 'IntegrationSettings',
  components: {
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    NcNoteCard,
    CopyIcon,
    DeleteIcon,
    ApiIcon,
    WebhookIcon,
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
  setup(props) {
    // API Keys
    const apiKeys = computed(() => {
      const keys = props.form.settings?.api_keys || [];
      // Don't expose hash to frontend
      return keys.map(k => ({
        id: k.id,
        name: k.name,
        permissions: k.permissions,
        created_at: k.created_at,
      }));
    });

    const newKeyName = ref('');
    const newKeyPermissions = reactive({
      read_form: true,
      read_responses: true,
      write_responses: false,
      delete_responses: false,
    });
    const creatingKey = ref(false);
    const newApiKey = ref(null);
    const apiKeyCopied = ref(false);

    // Webhooks
    const webhooks = computed(() => {
      const hooks = props.form.settings?.webhooks || [];
      // Don't expose secret to frontend
      return hooks.map(w => ({
        id: w.id,
        name: w.name,
        url: w.url,
        events: w.events,
        enabled: w.enabled,
      }));
    });

    const newWebhookName = ref('');
    const newWebhookUrl = ref('');
    const newWebhookEvents = reactive({
      created: true,
      updated: false,
      deleted: false,
    });
    const creatingWebhook = ref(false);
    const newWebhookSecret = ref(null);
    const webhookSecretCopied = ref(false);

    // API Key functions
    const createApiKey = async () => {
      if (!newKeyName.value) return;

      creatingKey.value = true;
      try {
        const permissions = [];
        if (newKeyPermissions.read_form) permissions.push('read_form');
        if (newKeyPermissions.read_responses) permissions.push('read_responses');
        if (newKeyPermissions.write_responses) permissions.push('write_responses');
        if (newKeyPermissions.delete_responses) permissions.push('delete_responses');

        const response = await axios.post(
          generateUrl('/apps/formvox/api/form/{fileId}/api-keys', { fileId: props.fileId }),
          {
            name: newKeyName.value,
            permissions,
          }
        );

        // Show the new key (only time it's visible!)
        newApiKey.value = response.data;

        // Add to local form object (without the key, just the config)
        if (!props.form.settings.api_keys) {
          props.form.settings.api_keys = [];
        }
        props.form.settings.api_keys.push({
          id: response.data.id,
          name: response.data.name,
          permissions: response.data.permissions,
          hash: '[hidden]',
        });

        // Reset form
        newKeyName.value = '';
        newKeyPermissions.read_form = true;
        newKeyPermissions.read_responses = true;
        newKeyPermissions.write_responses = false;
        newKeyPermissions.delete_responses = false;

        showSuccess(t('API key created'));
      } catch (error) {
        showError(t('Failed to create API key'));
        console.error(error);
      } finally {
        creatingKey.value = false;
      }
    };

    const deleteApiKey = async (keyId) => {
      if (!confirm(t('Are you sure you want to delete this API key? Any integrations using it will stop working.'))) {
        return;
      }

      try {
        await axios.delete(
          generateUrl('/apps/formvox/api/form/{fileId}/api-keys/{keyId}', { fileId: props.fileId, keyId })
        );

        // Remove from local form object
        props.form.settings.api_keys = props.form.settings.api_keys.filter(k => k.id !== keyId);

        showSuccess(t('API key deleted'));
      } catch (error) {
        showError(t('Failed to delete API key'));
        console.error(error);
      }
    };

    const copyApiKey = async () => {
      if (!newApiKey.value) return;
      try {
        await navigator.clipboard.writeText(newApiKey.value.key);
        apiKeyCopied.value = true;
        setTimeout(() => { apiKeyCopied.value = false; }, 2000);
      } catch (error) {
        showError(t('Failed to copy'));
      }
    };

    // Webhook functions
    const createWebhook = async () => {
      if (!newWebhookUrl.value) return;

      creatingWebhook.value = true;
      try {
        const events = [];
        if (newWebhookEvents.created) events.push('response.created');
        if (newWebhookEvents.updated) events.push('response.updated');
        if (newWebhookEvents.deleted) events.push('response.deleted');

        const response = await axios.post(
          generateUrl('/apps/formvox/api/form/{fileId}/webhooks', { fileId: props.fileId }),
          {
            name: newWebhookName.value || 'Webhook',
            url: newWebhookUrl.value,
            events,
          }
        );

        // Show the secret (only time it's visible!)
        newWebhookSecret.value = response.data.secret;

        // Add to local form object (without the secret)
        if (!props.form.settings.webhooks) {
          props.form.settings.webhooks = [];
        }
        props.form.settings.webhooks.push({
          id: response.data.id,
          name: response.data.name,
          url: response.data.url,
          events: response.data.events,
          enabled: true,
          secret: '[hidden]',
        });

        // Reset form
        newWebhookName.value = '';
        newWebhookUrl.value = '';
        newWebhookEvents.created = true;
        newWebhookEvents.updated = false;
        newWebhookEvents.deleted = false;

        showSuccess(t('Webhook created'));
      } catch (error) {
        showError(t('Failed to create webhook'));
        console.error(error);
      } finally {
        creatingWebhook.value = false;
      }
    };

    const toggleWebhook = async (webhookId, enabled) => {
      try {
        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}/webhooks/{webhookId}', { fileId: props.fileId, webhookId }),
          { enabled }
        );

        // Update local form object
        const webhook = props.form.settings.webhooks.find(w => w.id === webhookId);
        if (webhook) {
          webhook.enabled = enabled;
        }
      } catch (error) {
        showError(t('Failed to update webhook'));
        console.error(error);
      }
    };

    const deleteWebhook = async (webhookId) => {
      if (!confirm(t('Are you sure you want to delete this webhook?'))) {
        return;
      }

      try {
        await axios.delete(
          generateUrl('/apps/formvox/api/form/{fileId}/webhooks/{webhookId}', { fileId: props.fileId, webhookId })
        );

        // Remove from local form object
        props.form.settings.webhooks = props.form.settings.webhooks.filter(w => w.id !== webhookId);

        showSuccess(t('Webhook deleted'));
      } catch (error) {
        showError(t('Failed to delete webhook'));
        console.error(error);
      }
    };

    const copyWebhookSecret = async () => {
      if (!newWebhookSecret.value) return;
      try {
        await navigator.clipboard.writeText(newWebhookSecret.value);
        webhookSecretCopied.value = true;
        setTimeout(() => { webhookSecretCopied.value = false; }, 2000);
      } catch (error) {
        showError(t('Failed to copy'));
      }
    };

    // Formatters
    const formatPermissions = (permissions) => {
      const labels = {
        read_form: t('Read'),
        read_responses: t('Read responses'),
        write_responses: t('Write'),
        delete_responses: t('Delete'),
      };
      return permissions.map(p => labels[p] || p).join(', ');
    };

    const formatEvents = (events) => {
      if (!events || events.length === 0) return t('All events');
      const labels = {
        'response.created': t('Created'),
        'response.updated': t('Updated'),
        'response.deleted': t('Deleted'),
      };
      return events.map(e => labels[e] || e).join(', ');
    };

    return {
      // API Keys
      apiKeys,
      newKeyName,
      newKeyPermissions,
      creatingKey,
      newApiKey,
      apiKeyCopied,
      createApiKey,
      deleteApiKey,
      copyApiKey,

      // Webhooks
      webhooks,
      newWebhookName,
      newWebhookUrl,
      newWebhookEvents,
      creatingWebhook,
      newWebhookSecret,
      webhookSecretCopied,
      createWebhook,
      toggleWebhook,
      deleteWebhook,
      copyWebhookSecret,

      // Formatters
      formatPermissions,
      formatEvents,

      // Props forwarded
      fileId: props.fileId,

      t,
    };
  },
};
</script>

<style scoped lang="scss">
.integration-settings {
  .section {
    margin-bottom: 24px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);

    h3 {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0 0 8px;
      font-size: 14px;
      font-weight: 600;
    }

    .section-description {
      color: var(--color-text-maxcontrast);
      font-size: 13px;
      margin: 0 0 16px;
    }
  }

  .api-keys-list,
  .webhooks-list {
    margin-bottom: 16px;
  }

  .api-key-item,
  .webhook-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    margin-bottom: 8px;

    .key-info,
    .webhook-info {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .key-name,
    .webhook-name {
      font-weight: 600;
    }

    .key-id,
    .webhook-url {
      font-size: 12px;
      color: var(--color-text-maxcontrast);
      font-family: monospace;
    }

    .key-permissions,
    .webhook-events {
      font-size: 12px;
      color: var(--color-text-lighter);
    }

    .webhook-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
  }

  .new-key-display,
  .new-webhook-display {
    margin-bottom: 16px;

    .key-value {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;

      code {
        flex: 1;
        padding: 8px 12px;
        background: var(--color-background-dark);
        border-radius: var(--border-radius);
        font-family: monospace;
        font-size: 12px;
        word-break: break-all;
      }
    }
  }

  .create-api-key,
  .create-webhook {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 12px;
    background: var(--color-main-background);
    border: 1px dashed var(--color-border);
    border-radius: var(--border-radius);

    .permissions-select,
    .events-select {
      .permissions-label,
      .events-label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 8px;
      }

      .permission-checkboxes,
      .event-checkboxes {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
      }
    }
  }

  .api-docs {
    .endpoint {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 0;
      font-size: 13px;

      .method {
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;

        &.get {
          background: #e3f2fd;
          color: #1565c0;
        }

        &.post {
          background: #e8f5e9;
          color: #2e7d32;
        }

        &.put {
          background: #fff3e0;
          color: #ef6c00;
        }

        &.delete {
          background: #ffebee;
          color: #c62828;
        }
      }

      .path {
        font-family: monospace;
        font-size: 12px;
        background: var(--color-background-dark);
        padding: 4px 8px;
        border-radius: 3px;
      }

      .desc {
        color: var(--color-text-maxcontrast);
      }
    }

    .auth-note {
      margin-top: 12px;
      font-size: 13px;
      color: var(--color-text-maxcontrast);
      font-style: italic;
    }
  }
}
</style>
