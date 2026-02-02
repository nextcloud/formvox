<template>
  <NcModal
    :show="show"
    @close="$emit('close')"
    size="large"
    :title="t('formvox', 'Import Form')"
  >
    <div class="import-modal">
      <!-- Step Indicator -->
      <div class="step-indicator">
        <div
          v-for="(stepInfo, index) in steps"
          :key="index"
          :class="['step', { active: currentStep === index, completed: currentStep > index }]"
        >
          <span class="step-number">{{ currentStep > index ? '✓' : index + 1 }}</span>
          <span class="step-label">{{ stepInfo.label }}</span>
        </div>
      </div>

      <!-- Step Content -->
      <div class="step-content">
        <!-- Step 0: Choose Source -->
        <div v-if="currentStep === 0" class="step-panel">
          <h2>{{ t('formvox', 'Choose import source') }}</h2>
          <p class="step-description">
            {{ t('formvox', 'Select how you want to import your form.') }}
          </p>

          <div class="source-options">
            <button
              type="button"
              class="source-option"
              :class="{ selected: importSource === 'msforms' }"
              @click="selectSource('msforms')"
            >
              <MicrosoftIcon :size="32" />
              <span class="option-title">{{ t('formvox', 'Microsoft Forms') }}</span>
              <span class="option-desc">{{ t('formvox', 'Import form structure and responses') }}</span>
            </button>

            <button
              type="button"
              class="source-option"
              :class="{ selected: importSource === 'excel', disabled: true }"
              disabled
            >
              <FileExcelIcon :size="32" />
              <span class="option-title">{{ t('formvox', 'Excel File') }}</span>
              <span class="option-desc">{{ t('formvox', 'Import responses only (coming soon)') }}</span>
            </button>
          </div>
        </div>

        <!-- Step 1: Connect Microsoft -->
        <div v-if="currentStep === 1" class="step-panel">
          <h2>{{ t('formvox', 'Connect to Microsoft') }}</h2>

          <div v-if="!msConfigured" class="not-configured">
            <NcNoteCard type="warning">
              {{ t('formvox', 'Microsoft Forms import is not configured. Please ask your administrator to configure it in Settings > FormVox > Integrations.') }}
            </NcNoteCard>
          </div>

          <div v-else-if="msConnected" class="connected-status">
            <NcNoteCard type="success">
              {{ t('formvox', 'You are connected to Microsoft. Click Next to select a form to import.') }}
            </NcNoteCard>
            <NcButton type="tertiary" @click="disconnectMs">
              {{ t('formvox', 'Disconnect') }}
            </NcButton>
          </div>

          <div v-else class="connect-prompt">
            <p>{{ t('formvox', 'Connect your Microsoft account to access your forms.') }}</p>
            <NcButton type="primary" @click="connectMs" :disabled="connecting">
              <template #icon>
                <MicrosoftIcon :size="20" />
              </template>
              {{ connecting ? t('formvox', 'Connecting...') : t('formvox', 'Connect Microsoft Account') }}
            </NcButton>
          </div>
        </div>

        <!-- Step 2: Select Form -->
        <div v-if="currentStep === 2" class="step-panel">
          <h2>{{ t('formvox', 'Select a form to import') }}</h2>

          <div v-if="loadingForms" class="loading">
            <NcLoadingIcon :size="32" />
            <span>{{ t('formvox', 'Loading your forms...') }}</span>
          </div>

          <div v-else-if="msFormsList.length === 0" class="no-forms">
            <NcNoteCard type="info">
              {{ t('formvox', 'No forms found in your Microsoft Forms account.') }}
            </NcNoteCard>
          </div>

          <div v-else class="forms-list">
            <div
              v-for="form in msFormsList"
              :key="form.id"
              :class="['form-item', { selected: selectedMsForm?.id === form.id }]"
              @click="selectMsForm(form)"
            >
              <div class="form-info">
                <span class="form-title">{{ form.title }}</span>
                <span class="form-meta">
                  {{ form.responseCount }} {{ t('formvox', 'responses') }}
                </span>
              </div>
              <CheckIcon v-if="selectedMsForm?.id === form.id" :size="20" />
            </div>
          </div>
        </div>

        <!-- Step 3: Import Options -->
        <div v-if="currentStep === 3" class="step-panel">
          <h2>{{ t('formvox', 'Import options') }}</h2>

          <div v-if="selectedMsForm" class="selected-form-preview">
            <h3>{{ selectedMsForm.title }}</h3>
            <p v-if="selectedMsForm.description">{{ selectedMsForm.description }}</p>
          </div>

          <div class="import-options">
            <NcCheckboxRadioSwitch
              :model-value="importOptions.includeResponses"
              @update:model-value="importOptions.includeResponses = $event"
            >
              {{ t('formvox', 'Import existing responses') }}
            </NcCheckboxRadioSwitch>

            <div class="path-selector">
              <label>{{ t('formvox', 'Save location') }}</label>
              <input
                type="text"
                v-model="importOptions.path"
                :placeholder="t('formvox', '/ (root folder)')"
                class="path-input"
              />
            </div>
          </div>

          <div v-if="previewWarnings.length > 0" class="warnings">
            <h4>{{ t('formvox', 'Notes') }}</h4>
            <ul>
              <li v-for="(warning, idx) in previewWarnings" :key="idx">{{ warning }}</li>
            </ul>
          </div>
        </div>

        <!-- Step 4: Importing -->
        <div v-if="currentStep === 4" class="step-panel">
          <h2>{{ t('formvox', 'Importing...') }}</h2>

          <div v-if="importing" class="importing">
            <NcLoadingIcon :size="48" />
            <p>{{ t('formvox', 'Please wait while we import your form...') }}</p>
          </div>

          <div v-else-if="importError" class="import-error">
            <NcNoteCard type="error">
              {{ importError }}
            </NcNoteCard>
            <NcButton @click="currentStep = 3">
              {{ t('formvox', 'Go Back') }}
            </NcButton>
          </div>

          <div v-else-if="importResult" class="import-success">
            <NcNoteCard type="success">
              {{ t('formvox', 'Form imported successfully!') }}
            </NcNoteCard>

            <div class="import-summary">
              <p><strong>{{ importResult.title }}</strong></p>
              <p>{{ importResult.questionsImported }} {{ t('formvox', 'questions imported') }}</p>
              <p v-if="importResult.responsesImported > 0">
                {{ importResult.responsesImported }} {{ t('formvox', 'responses imported') }}
              </p>
            </div>

            <div v-if="importResult.warnings?.length > 0" class="import-warnings">
              <h4>{{ t('formvox', 'Warnings') }}</h4>
              <ul>
                <li v-for="(warning, idx) in importResult.warnings" :key="idx">{{ warning }}</li>
              </ul>
            </div>

            <div class="import-actions">
              <NcButton type="primary" @click="openImportedForm">
                {{ t('formvox', 'Open Form') }}
              </NcButton>
              <NcButton @click="$emit('close')">
                {{ t('formvox', 'Close') }}
              </NcButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Navigation -->
      <div v-if="currentStep < 4 || importError" class="step-navigation">
        <NcButton v-if="currentStep > 0" @click="prevStep">
          {{ t('formvox', 'Back') }}
        </NcButton>
        <div class="spacer"></div>
        <NcButton @click="$emit('close')">
          {{ t('formvox', 'Cancel') }}
        </NcButton>
        <NcButton
          v-if="currentStep < 3"
          type="primary"
          :disabled="!canProceed"
          @click="nextStep"
        >
          {{ t('formvox', 'Next') }}
        </NcButton>
        <NcButton
          v-if="currentStep === 3"
          type="primary"
          :disabled="!selectedMsForm"
          @click="startImport"
        >
          {{ t('formvox', 'Import') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref, reactive, computed, onMounted } from 'vue';
import { NcModal, NcButton, NcNoteCard, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';
import MicrosoftIcon from 'vue-material-design-icons/Microsoft.vue';
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue';
import CheckIcon from 'vue-material-design-icons/Check.vue';

export default {
  name: 'ImportModal',
  components: {
    NcModal,
    NcButton,
    NcNoteCard,
    NcCheckboxRadioSwitch,
    NcLoadingIcon,
    MicrosoftIcon,
    FileExcelIcon,
    CheckIcon,
  },
  props: {
    show: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['close', 'imported'],
  setup(props, { emit }) {
    const currentStep = ref(0);
    const importSource = ref('msforms');

    // MS Forms connection state
    const msConfigured = ref(false);
    const msConnected = ref(false);
    const connecting = ref(false);

    // Forms list
    const loadingForms = ref(false);
    const msFormsList = ref([]);
    const selectedMsForm = ref(null);

    // Import options
    const importOptions = reactive({
      includeResponses: true,
      path: '/',
    });

    // Preview/warnings
    const previewWarnings = ref([]);

    // Import state
    const importing = ref(false);
    const importError = ref(null);
    const importResult = ref(null);

    const steps = computed(() => {
      if (importSource.value === 'msforms') {
        return [
          { label: t('formvox', 'Source') },
          { label: t('formvox', 'Connect') },
          { label: t('formvox', 'Select') },
          { label: t('formvox', 'Options') },
          { label: t('formvox', 'Import') },
        ];
      }
      return [
        { label: t('formvox', 'Source') },
        { label: t('formvox', 'Upload') },
        { label: t('formvox', 'Map') },
        { label: t('formvox', 'Import') },
      ];
    });

    const canProceed = computed(() => {
      switch (currentStep.value) {
        case 0:
          return importSource.value !== null;
        case 1:
          return msConnected.value;
        case 2:
          return selectedMsForm.value !== null;
        case 3:
          return selectedMsForm.value !== null;
        default:
          return false;
      }
    });

    const checkMsConnection = async () => {
      try {
        const response = await axios.get(generateUrl('/apps/formvox/api/import/ms-forms/status'));
        msConfigured.value = response.data.configured;
        msConnected.value = response.data.connected;
      } catch (error) {
        console.error('Failed to check MS connection:', error);
      }
    };

    const connectMs = async () => {
      connecting.value = true;
      try {
        const response = await axios.get(generateUrl('/apps/formvox/api/import/ms-forms/auth'));
        // Open popup for OAuth
        const popup = window.open(
          response.data.authUrl,
          'ms-auth',
          'width=600,height=700,popup=1'
        );

        // Poll for completion
        const pollInterval = setInterval(async () => {
          if (popup?.closed) {
            clearInterval(pollInterval);
            connecting.value = false;
            await checkMsConnection();
          }
        }, 500);
      } catch (error) {
        console.error('Failed to start MS auth:', error);
        showError(t('formvox', 'Failed to connect to Microsoft'));
        connecting.value = false;
      }
    };

    const disconnectMs = async () => {
      try {
        await axios.post(generateUrl('/apps/formvox/api/import/ms-forms/disconnect'));
        msConnected.value = false;
      } catch (error) {
        console.error('Failed to disconnect:', error);
      }
    };

    const loadMsForms = async () => {
      loadingForms.value = true;
      try {
        const response = await axios.get(generateUrl('/apps/formvox/api/import/ms-forms/list'));
        msFormsList.value = response.data.forms || [];
      } catch (error) {
        console.error('Failed to load forms:', error);
        if (error.response?.data?.needsAuth) {
          msConnected.value = false;
          currentStep.value = 1;
        } else {
          showError(t('formvox', 'Failed to load forms'));
        }
      } finally {
        loadingForms.value = false;
      }
    };

    const selectSource = (source) => {
      importSource.value = source;
    };

    const selectMsForm = (form) => {
      selectedMsForm.value = form;
    };

    const nextStep = async () => {
      if (currentStep.value === 1 && msConnected.value) {
        await loadMsForms();
      }
      currentStep.value++;
    };

    const prevStep = () => {
      currentStep.value--;
    };

    const startImport = async () => {
      currentStep.value = 4;
      importing.value = true;
      importError.value = null;
      importResult.value = null;

      try {
        const response = await axios.post(
          generateUrl(`/apps/formvox/api/import/ms-forms/${selectedMsForm.value.id}`),
          {
            path: importOptions.path,
            includeResponses: importOptions.includeResponses,
          }
        );

        importResult.value = response.data;
        emit('imported', response.data);
      } catch (error) {
        console.error('Import failed:', error);
        importError.value = error.response?.data?.error || t('formvox', 'Import failed. Please try again.');
      } finally {
        importing.value = false;
      }
    };

    const openImportedForm = () => {
      if (importResult.value?.fileId) {
        window.location.href = generateUrl(`/apps/formvox/edit/${importResult.value.fileId}`);
      }
    };

    const t = (app, text, vars) => {
      if (window.t) {
        return vars ? window.t(app, text, vars) : window.t(app, text);
      }
      return text;
    };

    onMounted(() => {
      checkMsConnection();
    });

    return {
      currentStep,
      importSource,
      steps,
      canProceed,
      msConfigured,
      msConnected,
      connecting,
      loadingForms,
      msFormsList,
      selectedMsForm,
      importOptions,
      previewWarnings,
      importing,
      importError,
      importResult,
      selectSource,
      selectMsForm,
      connectMs,
      disconnectMs,
      nextStep,
      prevStep,
      startImport,
      openImportedForm,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.import-modal {
  padding: 20px;
  min-height: 400px;
  display: flex;
  flex-direction: column;
}

.step-indicator {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-border);

  .step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    font-size: 13px;

    &.active {
      background: var(--color-primary-element);
      color: var(--color-primary-element-text);
    }

    &.completed {
      background: var(--color-success-hover);
      color: var(--color-success-text);
    }

    .step-number {
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      font-weight: 600;
      font-size: 12px;
    }

    .step-label {
      display: none;

      @media (min-width: 600px) {
        display: inline;
      }
    }
  }
}

.step-content {
  flex: 1;
}

.step-panel {
  h2 {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 600;
  }

  .step-description {
    color: var(--color-text-maxcontrast);
    margin-bottom: 20px;
  }
}

.source-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;

  .source-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 24px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    cursor: pointer;
    transition: all 0.2s ease;

    &:hover:not(.disabled) {
      border-color: var(--color-primary-element);
      background: var(--color-primary-element-light);
    }

    &.selected {
      border-color: var(--color-primary-element);
      background: var(--color-primary-element-light);
    }

    &.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .option-title {
      font-weight: 600;
      font-size: 16px;
    }

    .option-desc {
      font-size: 13px;
      color: var(--color-text-maxcontrast);
      text-align: center;
    }
  }
}

.not-configured,
.connected-status,
.connect-prompt {
  text-align: center;
  padding: 20px;
}

.connected-status {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.loading,
.no-forms {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 40px;
  text-align: center;
}

.forms-list {
  max-height: 300px;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);

  .form-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);

    &:last-child {
      border-bottom: none;
    }

    &:hover {
      background: var(--color-background-hover);
    }

    &.selected {
      background: var(--color-primary-element-light);
    }

    .form-info {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .form-title {
        font-weight: 500;
      }

      .form-meta {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
      }
    }
  }
}

.selected-form-preview {
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  margin-bottom: 20px;

  h3 {
    margin: 0 0 8px;
    font-size: 16px;
  }

  p {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
  }
}

.import-options {
  display: flex;
  flex-direction: column;
  gap: 16px;

  .path-selector {
    label {
      display: block;
      font-weight: 500;
      margin-bottom: 8px;
    }

    .path-input {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      font-size: 14px;
    }
  }
}

.warnings,
.import-warnings {
  margin-top: 20px;
  padding: 16px;
  background: var(--color-warning-hover);
  border-radius: var(--border-radius-large);

  h4 {
    margin: 0 0 12px;
    font-size: 14px;
  }

  ul {
    margin: 0;
    padding-left: 20px;
    font-size: 13px;
  }

  li {
    margin-bottom: 6px;
  }
}

.importing {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  padding: 40px;
  text-align: center;
}

.import-error,
.import-success {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  text-align: center;
}

.import-summary {
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  p {
    margin: 0 0 8px;

    &:last-child {
      margin-bottom: 0;
    }
  }
}

.import-actions {
  display: flex;
  gap: 12px;
}

.step-navigation {
  display: flex;
  gap: 12px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
  margin-top: 20px;

  .spacer {
    flex: 1;
  }
}
</style>
