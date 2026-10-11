<template>
  <NcModal
    :show="show"
    @close="$emit('close')"
    size="normal"
  >
    <div class="odt-template-dialog">
      <!-- TRANSLATORS: Heading of the dialog for an OpenDocument text template -->
      <h2>{{ t('formvox', 'ODT template') }}</h2>

      <p class="description">
        {{ t('formvox', 'Design your own document layout in LibreOffice or Word, and use placeholders where you want answers to appear. When you export responses as ODT, each response will be filled into your template.') }}
      </p>

      <div v-if="hasTemplate" class="template-status">
        <p class="status-active">{{ t('formvox', 'Template active — exports will use this template.') }}</p>
        <div class="template-actions">
          <NcButton type="secondary" @click="downloadTemplate">
            <!-- TRANSLATORS: Verb: button -->
            {{ t('formvox', 'Download template') }}
          </NcButton>
          <NcButton type="error" @click="deleteTemplate">
            <!-- TRANSLATORS: Verb: button -->
            {{ t('formvox', 'Delete template') }}
          </NcButton>
        </div>
      </div>

      <div class="upload-section">
        <!-- TRANSLATORS: Heading: replace the uploaded template -->
        <h3 v-if="hasTemplate">{{ t('formvox', 'Replace template') }}</h3>
        <!-- TRANSLATORS: Heading: upload a template -->
        <h3 v-else>{{ t('formvox', 'Upload template') }}</h3>
        <p class="help-text">
          <!-- TRANSLATORS: {Q1} and {Q2} are literal placeholders the user types in the document; keep them exactly as they are -->
          {{ t('formvox', 'Upload an ODT file with placeholders like {Q1}, {Q2}, etc. These will be replaced with the actual answers when exporting.') }}
        </p>
        <input
          ref="fileInput"
          type="file"
          accept=".odt"
          @change="onFileSelected"
        />
        <NcButton
          v-if="selectedFile"
          type="primary"
          :disabled="uploading"
          @click="uploadTemplate"
        >
          <!-- TRANSLATORS: Button text while uploading -->
          {{ uploading ? t('formvox', 'Uploading …') : t('formvox', 'Upload') }}
        </NcButton>
      </div>

      <div class="placeholders-section">
        <!-- TRANSLATORS: Heading above the placeholders a template can use -->
        <h3>{{ t('formvox', 'Available placeholders') }}</h3>
        <div class="placeholders-list">
          <div class="placeholder-item">
            <code>{form_title}</code>
            <!-- TRANSLATORS: Label of the title field of a form -->
            <span>{{ t('formvox', 'Form title') }}</span>
          </div>
          <div class="placeholder-item">
            <code>{submitted_at}</code>
            <!-- TRANSLATORS: Explanation of a template placeholder: the date a response was sent -->
            <span>{{ t('formvox', 'Submission date') }}</span>
          </div>
          <div class="placeholder-item">
            <code>{respondent_name}</code>
            <!-- TRANSLATORS: Explanation of a template placeholder: who answered -->
            <span>{{ t('formvox', 'Respondent name') }}</span>
          </div>
          <div
            v-for="(question, index) in form.questions"
            :key="question.id"
            class="placeholder-item"
          >
            <code>{Q{{ index + 1 }}}</code>
            <span>{{ truncate(question.question, 50) }}</span>
          </div>
        </div>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref } from 'vue';
import { NcModal, NcButton } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';

export default {
  name: 'OdtTemplateDialog',
  components: { NcModal, NcButton },
  props: {
    show: { type: Boolean, default: false },
    fileId: { type: Number, required: true },
    form: { type: Object, required: true },
  },
  emits: ['close', 'template-changed'],
  setup(props, { emit }) {
    const hasTemplate = ref(false);
    const selectedFile = ref(null);
    const uploading = ref(false);
    const fileInput = ref(null);

    const checkTemplate = async () => {
      try {
        const response = await axios.get(
          generateUrl('/apps/formvox/api/form/{fileId}/odt-template/status', { fileId: props.fileId })
        );
        hasTemplate.value = response.data.hasTemplate;
      } catch (error) {
        hasTemplate.value = false;
      }
    };

    const onFileSelected = (event) => {
      selectedFile.value = event.target.files[0] || null;
    };

    const uploadTemplate = async () => {
      if (!selectedFile.value) return;
      uploading.value = true;
      try {
        const formData = new FormData();
        formData.append('template', selectedFile.value);
        await axios.post(
          generateUrl('/apps/formvox/api/form/{fileId}/odt-template', { fileId: props.fileId }),
          formData
        );
        hasTemplate.value = true;
        selectedFile.value = null;
        if (fileInput.value) fileInput.value.value = '';
        // TRANSLATORS: Confirmation message
        showSuccess(t('formvox', 'Template uploaded'));
        emit('template-changed', true);
      } catch (error) {
        showError(t('formvox', 'Failed to upload template'));
        console.error(error);
      } finally {
        uploading.value = false;
      }
    };

    const downloadTemplate = () => {
      window.location.href = generateUrl('/apps/formvox/api/form/{fileId}/odt-template', { fileId: props.fileId });
    };

    const deleteTemplate = async () => {
      if (!confirm(t('formvox', 'Are you sure you want to delete the template?'))) return;
      try {
        await axios.delete(
          generateUrl('/apps/formvox/api/form/{fileId}/odt-template', { fileId: props.fileId })
        );
        hasTemplate.value = false;
        // TRANSLATORS: Confirmation message
        showSuccess(t('formvox', 'Template deleted'));
        emit('template-changed', false);
      } catch (error) {
        showError(t('formvox', 'Failed to delete template'));
        console.error(error);
      }
    };

    const truncate = (text, length) => {
      if (!text) return '';
      if (text.length <= length) return text;
      return text.substring(0, length) + '...';
    };

    // Check on mount
    checkTemplate();

    return {
      hasTemplate,
      selectedFile,
      uploading,
      fileInput,
      onFileSelected,
      uploadTemplate,
      downloadTemplate,
      deleteTemplate,
      truncate,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.odt-template-dialog {
  padding: 20px;

  h2 {
    margin: 0 0 10px;
  }

  .description {
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    line-height: 1.5;
    margin: 0 0 20px;
  }

  h3 {
    margin: 20px 0 10px;
    font-size: 16px;
  }
}

.template-status {
  padding: 15px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  margin-bottom: 20px;

  .status-active {
    margin: 0 0 10px;
    font-weight: 500;
  }

  .template-actions {
    display: flex;
    gap: 10px;
  }
}

.upload-section {
  margin-bottom: 20px;

  .help-text {
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    margin: 0 0 10px;
  }

  input[type="file"] {
    margin-bottom: 10px;
  }
}

.placeholders-section {
  border-top: 1px solid var(--color-border);
  padding-top: 10px;
}

.placeholders-list {
  max-height: 200px;
  overflow-y: auto;
}

.placeholder-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
  font-size: 14px;

  code {
    background: var(--color-background-dark);
    padding: 2px 6px;
    border-radius: var(--border-radius);
    font-size: 13px;
    white-space: nowrap;
  }
}
</style>
