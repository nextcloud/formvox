<template>
  <div class="ai-settings">
    <div class="settings-section">
      <h2>{{ t('formvox', 'AI Form Generation') }}</h2>
      <p class="settings-section-desc">
        {{ t('formvox', 'Let FormVox use the built-in Nextcloud AI (TaskProcessing) to generate forms from a description or uploaded document.') }}
      </p>

      <div v-if="loading" class="ai-loading">
        <NcLoadingIcon :size="32" />
      </div>

      <template v-else>
        <div class="provider-status" :class="{ ok: settings.providerAvailable, warning: !settings.providerAvailable }">
          <div class="provider-status-icon">
            <CheckIcon v-if="settings.providerAvailable" :size="20" />
            <AlertIcon v-else :size="20" />
          </div>
          <div class="provider-status-text">
            <strong v-if="settings.providerAvailable">
              {{ t('formvox', 'AI provider available') }}
            </strong>
            <strong v-else>
              {{ t('formvox', 'No AI provider configured') }}
            </strong>
            <span v-if="settings.providerAvailable">
              {{ t('formvox', 'Task type:') }} <code>{{ settings.providerTaskType }}</code>
            </span>
            <span v-else>
              {{ t('formvox', 'Install an AI provider app (e.g. Integration OpenAI) to enable form generation.') }}
            </span>
          </div>
        </div>

        <div class="setting-row">
          <NcCheckboxRadioSwitch
            type="switch"
            :model-value="settings.enabled"
            :disabled="!settings.providerAvailable || saving"
            @update:model-value="onToggleEnabled"
          >
            {{ t('formvox', 'Enable AI form generation for all users') }}
          </NcCheckboxRadioSwitch>
        </div>

        <fieldset :disabled="!settings.enabled || saving" class="ai-options">
          <div class="setting-row">
            <label class="setting-label">
              <span>{{ t('formvox', 'Maximum questions per generated form') }}</span>
              <span class="setting-hint">{{ settings.maxQuestions }}</span>
            </label>
            <input
              :value="settings.maxQuestions"
              type="range"
              min="3"
              max="20"
              step="1"
              class="nc-range"
              @input="settings.maxQuestions = Number($event.target.value)"
              @change="save"
            />
          </div>

          <div class="setting-row">
            <label class="setting-label">
              <span>{{ t('formvox', 'Maximum source document size') }}</span>
              <span class="setting-hint">{{ settings.maxDocSizeMb }} MB</span>
            </label>
            <input
              :value="settings.maxDocSizeMb"
              type="range"
              min="1"
              max="25"
              step="1"
              class="nc-range"
              @input="settings.maxDocSizeMb = Number($event.target.value)"
              @change="save"
            />
          </div>

          <div class="setting-row">
            <NcCheckboxRadioSwitch
              type="switch"
              :model-value="settings.allowSourceUpload"
              @update:model-value="onToggleSourceUpload"
            >
              {{ t('formvox', 'Allow users to upload a source document (PDF/DOCX/ODT)') }}
            </NcCheckboxRadioSwitch>
          </div>

          <div class="setting-row">
            <NcCheckboxRadioSwitch
              type="switch"
              :model-value="settings.allowConditional"
              @update:model-value="onToggleConditional"
            >
              {{ t('formvox', 'Allow AI to add conditional logic (showIf) to generated questions') }}
            </NcCheckboxRadioSwitch>
          </div>
        </fieldset>

        <p v-if="error" class="ai-error">{{ error }}</p>
      </template>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import { NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showSuccess, showError } from '@nextcloud/dialogs';
import CheckIcon from 'vue-material-design-icons/CheckCircle.vue';
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue';

export default {
  name: 'AiSettings',
  components: {
    NcCheckboxRadioSwitch,
    NcLoadingIcon,
    CheckIcon,
    AlertIcon,
  },
  setup() {
    const loading = ref(true);
    const saving = ref(false);
    const error = ref('');
    const settings = ref({
      enabled: false,
      providerAvailable: false,
      providerTaskType: null,
      maxQuestions: 12,
      maxDocSizeMb: 8,
      allowSourceUpload: true,
      allowConditional: true,
    });

    const load = async () => {
      loading.value = true;
      try {
        const resp = await axios.get(generateUrl('/apps/formvox/api/settings/ai'));
        settings.value = { ...settings.value, ...resp.data.settings };
      } catch (e) {
        showError(t('formvox', 'Failed to load AI settings'));
      } finally {
        loading.value = false;
      }
    };

    const save = async () => {
      error.value = '';
      saving.value = true;
      try {
        const resp = await axios.post(
          generateUrl('/apps/formvox/api/settings/ai'),
          {
            enabled: settings.value.enabled,
            maxQuestions: settings.value.maxQuestions,
            maxDocSizeMb: settings.value.maxDocSizeMb,
            allowSourceUpload: settings.value.allowSourceUpload,
            allowConditional: settings.value.allowConditional,
          }
        );
        settings.value = { ...settings.value, ...resp.data.settings };
        showSuccess(t('formvox', 'AI settings saved'));
      } catch (e) {
        error.value = e.response?.data?.error || t('formvox', 'Failed to save AI settings');
        // Revert the toggle in the UI by reloading the known-good server state
        await load();
      } finally {
        saving.value = false;
      }
    };

    const onMaxQuestionsInput = (val) => {
      const n = parseInt(val, 10);
      if (!Number.isNaN(n)) settings.value.maxQuestions = Math.max(3, Math.min(20, n));
    };
    const onMaxDocSizeInput = (val) => {
      const n = parseInt(val, 10);
      if (!Number.isNaN(n)) settings.value.maxDocSizeMb = Math.max(1, Math.min(25, n));
    };

    const onToggleEnabled = (value) => {
      settings.value.enabled = value;
      save();
    };
    const onToggleSourceUpload = (value) => {
      settings.value.allowSourceUpload = value;
      save();
    };
    const onToggleConditional = (value) => {
      settings.value.allowConditional = value;
      save();
    };

    onMounted(load);

    return {
      t: (app, msg) => (typeof window.t === 'function' ? window.t(app, msg) : msg),
      loading,
      saving,
      error,
      settings,
      save,
      onMaxQuestionsInput,
      onMaxDocSizeInput,
      onToggleEnabled,
      onToggleSourceUpload,
      onToggleConditional,
    };
  },
};
</script>

<style scoped lang="scss">
.ai-settings {
  max-width: 780px;
}
.ai-loading {
  padding: 32px;
  display: flex;
  justify-content: center;
}
.provider-status {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 12px 16px;
  border-radius: var(--border-radius);
  margin-bottom: 20px;

  &.ok {
    background: var(--color-success-hover, #e4f1e4);
    color: var(--color-success-text, #1e6f1e);
  }
  &.warning {
    background: var(--color-warning-hover, #fff4d8);
    color: var(--color-warning-text, #8a6d11);
  }
}
.provider-status-icon {
  flex-shrink: 0;
  margin-top: 2px;
}
.provider-status-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 14px;
  code {
    font-size: 12px;
    background: rgba(0, 0, 0, 0.05);
    padding: 1px 6px;
    border-radius: 3px;
  }
}
.setting-row {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 16px;
}
.setting-label {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 14px;
  font-weight: 500;
}
.setting-hint {
  color: var(--color-text-maxcontrast);
  font-size: 12px;
  font-weight: 400;
}
// Nextcloud-style range slider (reset any global input chrome that NC applies)
input[type=range].nc-range {
  width: 100%;
  max-width: 420px;
  height: 6px;
  padding: 0 !important;
  border: none !important;
  background: var(--color-border) !important;
  border-radius: 999px;
  outline: none;
  cursor: pointer;
  margin: 10px 0 4px !important;
  box-shadow: none !important;
  min-height: 0 !important;
  -webkit-appearance: none;
  appearance: none;

  &::-webkit-slider-runnable-track {
    height: 6px;
    background: transparent;
    border-radius: 999px;
  }
  &::-moz-range-track {
    height: 6px;
    background: transparent;
    border-radius: 999px;
  }

  &::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--color-primary-element);
    cursor: grab;
    border: 2px solid var(--color-main-background);
    box-shadow: 0 0 0 1px var(--color-primary-element);
    margin-top: -5px;
    transition: transform 0.1s ease;
  }
  &::-moz-range-thumb {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--color-primary-element);
    cursor: grab;
    border: 2px solid var(--color-main-background);
    box-shadow: 0 0 0 1px var(--color-primary-element);
  }
  &::-webkit-slider-thumb:active,
  &::-moz-range-thumb:active {
    transform: scale(1.15);
    cursor: grabbing;
  }
  &:focus-visible::-webkit-slider-thumb {
    box-shadow: 0 0 0 3px var(--color-primary-element-light);
  }
}
.ai-options {
  border: none;
  padding: 0;
  margin: 0;
  &[disabled] {
    opacity: 0.55;
  }
}
.ai-error {
  color: var(--color-error);
  margin-top: 12px;
}
</style>
