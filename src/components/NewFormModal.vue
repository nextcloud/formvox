<template>
  <NcModal :can-close="!creating" @close="onCloseAttempt">
    <div class="new-form-modal">
      <h2>{{ t('Create new form') }}</h2>

      <p class="template-info">
        {{ t('Template') }}: <strong>{{ templateLabel }}</strong>
      </p>

      <NcTextField
        v-model="title"
        :label="t('Form title')"
        :placeholder="t('Enter a title for your form')"
        class="title-input"
        autofocus
      />

      <div v-if="isAi" class="ai-prompt-section">
        <label class="ai-prompt-label" for="ai-prompt-input">
          {{ t('Describe the form you want to create') }}
        </label>
        <textarea
          id="ai-prompt-input"
          v-model="aiDescription"
          class="ai-prompt-input"
          :placeholder="t('e.g. A customer satisfaction survey with 8 questions about service quality, food, and value for money.')"
          rows="4"
          :disabled="creating"
        />

        <div v-if="aiAllowSourceUpload" class="ai-source-row">
          <NcButton type="secondary" :disabled="creating" @click="pickSourceFile">
            <template #icon>
              <PaperclipIcon :size="18" />
            </template>
            {{ aiSourceName ? t('Change source document') : t('Add source document (optional)') }}
          </NcButton>
          <span v-if="aiSourceName" class="ai-source-name">
            {{ aiSourceName }}
            <button type="button" class="ai-source-clear" :disabled="creating" @click="clearSourceFile" :aria-label="t('Remove source')">×</button>
          </span>
        </div>

        <div v-if="aiError" class="ai-error">
          <strong>{{ t('Generation failed') }}</strong>
          <span>{{ aiError }}</span>
        </div>
        <p v-else class="ai-hint">
          {{ aiSourceName
            ? t('AI will base questions on the document and your description. Generation can take up to 2 minutes.')
            : t('The AI will draft questions based on your description. Generation can take up to 2 minutes.') }}
        </p>
      </div>

      <div class="location-section">
        <label class="location-label">{{ t('Save location') }}</label>
        <div class="location-picker">
          <div class="location-display">
            <FolderIcon :size="20" />
            <span class="location-path">{{ selectedPath || '/' }}</span>
          </div>
          <NcButton type="secondary" @click="pickFolder">
            {{ t('Choose folder') }}
          </NcButton>
        </div>
      </div>

      <div class="actions">
        <NcButton :disabled="creating" @click="$emit('close')">
          {{ t('Cancel') }}
        </NcButton>
        <NcButton type="primary" :disabled="!canSubmit" @click="create">
          <template v-if="creating" #icon>
            <NcLoadingIcon :size="20" />
          </template>
          {{ submitLabel }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref, computed, onMounted } from 'vue';
import { NcModal, NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import FolderIcon from './icons/FolderIcon.vue';
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue';

const TEMPLATE_NAMES = {
	blank: 'Blank form',
	survey: 'Survey',
	poll: 'Poll',
	registration: 'Registration',
	demo: 'Demo Form',
	ai: 'Generate with AI',
};

const TEMPLATE_TITLES = {
	blank: 'Untitled Form',
	survey: 'New Survey',
	poll: 'New Poll',
	registration: 'New Registration',
	demo: 'Demo Form',
	ai: 'AI-generated form',
};

export default {
	name: 'NewFormModal',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		FolderIcon,
		PaperclipIcon,
	},
	props: {
		initialTemplate: {
			type: String,
			default: 'blank',
		},
	},
	emits: ['close', 'created'],
	setup(props, { emit }) {
		const title = ref('')
		const creating = ref(false)
		const selectedPath = ref('')
		const aiDescription = ref('')
		const aiError = ref('')
		const aiSourceFileId = ref(null)
		const aiSourceName = ref('')
		const aiAllowSourceUpload = ref(true)

		const isAi = computed(() => props.initialTemplate === 'ai')

		const templateLabel = computed(() => {
			return t(TEMPLATE_NAMES[props.initialTemplate] || TEMPLATE_NAMES.blank)
		})

		const canSubmit = computed(() => {
			if (creating.value) return false
			if (!title.value) return false
			if (isAi.value && !aiDescription.value.trim() && !aiSourceFileId.value) return false
			return true
		})

		const submitLabel = computed(() => {
			if (!creating.value) return isAi.value ? t('Generate') : t('Create')
			return isAi.value ? t('Generating...') : t('Creating...')
		})

		onMounted(async () => {
			// Set default title based on template
			title.value = t(TEMPLATE_TITLES[props.initialTemplate] || TEMPLATE_TITLES.blank)
			// Respect the admin toggle for source-document uploads
			if (isAi.value) {
				try {
					const resp = await axios.get(generateUrl('/apps/formvox/api/ai/status'))
					aiAllowSourceUpload.value = !!resp.data?.allowSourceUpload
				} catch (e) {
					aiAllowSourceUpload.value = true // fail-open: UI stays visible, server still enforces
				}
			}
		})

    const pickSourceFile = async () => {
      try {
        const picker = getFilePickerBuilder(t('Choose source document'))
          .setMultiSelect(false)
          .setType(FilePickerType.Choose)
          .setMimeTypeFilter([
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'text/markdown',
          ])
          .allowDirectories(false)
          .build();

        const result = await picker.pick();
        const path = Array.isArray(result) ? result[0] : result;
        if (!path) return;

        const resp = await axios.get(
          generateUrl('/apps/formvox/api/ai/resolve-file'),
          { params: { path } }
        );
        const fid = resp.data?.fileId;
        if (!fid) {
          showError(t('Could not resolve the selected file.'));
          return;
        }
        aiSourceFileId.value = fid;
        aiSourceName.value = path.split('/').pop() || path;
      } catch (error) {
        if (error?.message?.includes('cancelled')) return;
        console.error('Source picker error:', error);
        showError(t('Could not resolve the selected file.'));
      }
    };

    const clearSourceFile = () => {
      aiSourceFileId.value = null;
      aiSourceName.value = '';
    };

    const pickFolder = async () => {
      try {
        const picker = getFilePickerBuilder(t('Choose save location'))
          .setMultiSelect(false)
          .setType(FilePickerType.Choose)
          .allowDirectories(true)
          .build();

        const path = await picker.pick();
        if (path && path.length > 0) {
          // picker.pick() returns array of paths
          selectedPath.value = Array.isArray(path) ? path[0] : path;
        }
      } catch (error) {
        // User cancelled or error
        console.error('Folder picker error:', error);
      }
    };

    const create = async () => {
      if (!canSubmit.value) return;

      creating.value = true;
      aiError.value = '';

      if (isAi.value) {
        try { sessionStorage.setItem('formvox_ai_generating', '1'); } catch (e) { /* ignore */ }
        window.dispatchEvent(new CustomEvent('formvox-ai-state-change'));
        await runAiFlow();
        return;
      }

      try {
        const response = await axios.post(generateUrl('/apps/formvox/api/forms'), {
          title: title.value,
          path: selectedPath.value || '',
          template: props.initialTemplate === 'blank' ? null : props.initialTemplate,
        });
        emit('created', response.data);
      } catch (error) {
        showError(t('Failed to create form'));
        console.error(error);
      } finally {
        creating.value = false;
      }
    };

    const runAiFlow = async () => {
      // Schedule task. Returns immediately with the task id.
      let taskId = null;
      try {
        const resp = await axios.post(
          generateUrl('/apps/formvox/api/ai/generate-form'),
          {
            title: title.value,
            description: aiDescription.value.trim(),
            sourceFileId: aiSourceFileId.value,
            path: selectedPath.value || '',
          }
        );
        taskId = resp.data?.taskId;
      } catch (err) {
        aiError.value = err.response?.data?.error || t('AI generation failed. Try a different description.');
        creating.value = false;
        try { sessionStorage.removeItem('formvox_ai_generating'); } catch (e) { /* ignore */ }
        window.dispatchEvent(new CustomEvent('formvox-ai-state-change'));
        return;
      }
      if (!taskId) {
        aiError.value = t('AI generation failed. No task returned.');
        creating.value = false;
        try { sessionStorage.removeItem('formvox_ai_generating'); } catch (e) { /* ignore */ }
        window.dispatchEvent(new CustomEvent('formvox-ai-state-change'));
        return;
      }

      // Poll the task status every 2 seconds (matching nextcloud/assistant).
      // Stop after 5 minutes regardless — at that point the user can come back later.
      const maxPolls = 150;
      let polls = 0;
      const interval = setInterval(async () => {
        polls++;
        try {
          const tr = await axios.get(generateUrl('/apps/formvox/api/ai/task/' + taskId));
          const status = tr.data?.status;
          // 1 = scheduled, 2 = running, 4 = successful, 5 = failed, 6 = cancelled
          if (status === 4) {
            // Wait for the listener to materialise the form (pendingRow=false).
            if (tr.data?.pendingRow === false) {
              clearInterval(interval);
              creating.value = false;
              try { sessionStorage.removeItem('formvox_ai_generating'); } catch (e) { /* ignore */ }
              window.dispatchEvent(new CustomEvent('formvox-ai-state-change'));
              emit('created', { fileId: null }); // The notification + listener will have set everything up; close modal.
              showSuccess(t('AI form created. Check your notifications to open it.'));
            }
          } else if (status === 5 || status === 6) {
            clearInterval(interval);
            aiError.value = tr.data?.error || t('AI generation failed.');
            creating.value = false;
            try { sessionStorage.removeItem('formvox_ai_generating'); } catch (e) { /* ignore */ }
            window.dispatchEvent(new CustomEvent('formvox-ai-state-change'));
          } else if (polls >= maxPolls) {
            clearInterval(interval);
            // Don't error — the task is still running server-side and the
            // listener will deliver a notification when done.
            creating.value = false;
            showSuccess(t('AI is still generating. You will be notified when ready.'));
            emit('close');
          }
        } catch (err) {
          // Network blip — keep polling. If it fails for many polls in a row
          // the maxPolls cap above will eventually close the modal cleanly.
        }
      }, 2000);
    };

    const onCloseAttempt = () => {
      if (!creating.value) emit('close');
    };

    return {
      title,
      templateLabel,
      selectedPath,
      creating,
      isAi,
      aiDescription,
      aiError,
      aiSourceName,
      aiAllowSourceUpload,
      canSubmit,
      submitLabel,
      pickSourceFile,
      clearSourceFile,
      pickFolder,
      create,
      onCloseAttempt,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.ai-prompt-section {
  margin: 16px 0;
}
.ai-prompt-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
}
.ai-prompt-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  font-family: inherit;
  font-size: 14px;
  resize: vertical;
  min-height: 80px;

  &:focus {
    outline: none;
    border-color: var(--color-primary-element);
  }
}
.ai-hint {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}
.ai-source-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  flex-wrap: wrap;
}
.ai-source-name {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: var(--border-radius);
  background: var(--color-background-hover);
  font-size: 13px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ai-source-clear {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  padding: 0 2px;
  color: var(--color-text-maxcontrast);

  &:hover:not(:disabled) {
    color: var(--color-error);
  }
  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}

.ai-error {
  margin: 8px 0 0;
  padding: 10px 12px;
  border-radius: var(--border-radius);
  background: var(--color-error);
  color: var(--color-primary-element-text, #fff);
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 13px;

  strong {
    font-weight: 600;
  }
}

.new-form-modal {
  padding: 20px;
  min-width: 550px;

  h2 {
    margin: 0 0 12px;
  }

  .template-info {
    margin: 0 0 16px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);

    strong {
      color: var(--color-main-text);
    }
  }

  .title-input {
    margin-bottom: 16px;
  }

  .location-section {
    margin-bottom: 24px;

    .location-label {
      display: block;
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .location-picker {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .location-display {
      flex: 1;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      background: var(--color-background-hover);
      border-radius: var(--border-radius);
      font-size: 14px;
      color: var(--color-text-maxcontrast);
      overflow: hidden;

      .location-path {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border);
  }
}
</style>
