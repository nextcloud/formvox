<template>
  <NcModal @close="$emit('close')">
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
        <NcButton @click="$emit('close')">
          {{ t('Cancel') }}
        </NcButton>
        <NcButton type="primary" :disabled="!title || creating" @click="create">
          {{ creating ? t('Creating...') : t('Create') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref, computed, onMounted } from 'vue';
import { NcModal, NcButton, NcTextField } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import FolderIcon from './icons/FolderIcon.vue';

const TEMPLATE_NAMES = {
	blank: 'Blank form',
	survey: 'Survey',
	poll: 'Poll',
	registration: 'Registration',
	demo: 'Demo Form',
};

const TEMPLATE_TITLES = {
	blank: 'Untitled Form',
	survey: 'New Survey',
	poll: 'New Poll',
	registration: 'New Registration',
	demo: 'Demo Form',
};

export default {
	name: 'NewFormModal',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		FolderIcon,
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

		const templateLabel = computed(() => {
			return t(TEMPLATE_NAMES[props.initialTemplate] || TEMPLATE_NAMES.blank)
		})

		onMounted(() => {
			// Set default title based on template
			title.value = t(TEMPLATE_TITLES[props.initialTemplate] || TEMPLATE_TITLES.blank)
		})

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
      if (!title.value) return;

      creating.value = true;
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

    return {
      title,
      templateLabel,
      selectedPath,
      creating,
      pickFolder,
      create,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
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
