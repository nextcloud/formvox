<template>
  <NcContent app-name="formvox">
    <NcAppContent>
      <div class="editor-container">
        <div class="editor-header">
          <NcTextField
            v-model="form.title"
            :label="t('Form title')"
            class="title-input"
            @update:model-value="debouncedSave"
          />
          <NcTextField
            v-model="form.description"
            :label="t('Description')"
            class="description-input"
            @update:model-value="debouncedSave"
          />
        </div>

        <div class="editor-toolbar">
          <NcButton @click="addQuestion">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('Add question') }}
          </NcButton>

          <NcButton @click="showPreview = !showPreview">
            <template #icon>
              <EyeIcon :size="20" />
            </template>
            {{ showPreview ? t('Edit') : t('Preview') }}
          </NcButton>

          <NcActions>
            <NcActionButton @click="showSettings = true">
              <template #icon>
                <CogIcon :size="20" />
              </template>
              {{ t('Settings') }}
            </NcActionButton>
            <NcActionButton @click="showShare = true">
              <template #icon>
                <ShareIcon :size="20" />
              </template>
              {{ t('Share') }}
            </NcActionButton>
            <NcActionButton @click="viewResults">
              <template #icon>
                <ChartIcon :size="20" />
              </template>
              {{ t('View results') }}
            </NcActionButton>
          </NcActions>
        </div>

        <div v-if="showPreview" class="preview-container">
          <Respond
            :form="form"
            :is-preview="true"
            @submit="() => {}"
          />
        </div>

        <div v-else class="questions-container">
          <draggable
            v-model="form.questions"
            item-key="id"
            handle=".drag-handle"
            @end="debouncedSave"
          >
            <template #item="{ element, index }">
              <QuestionEditor
                :question="element"
                :index="index"
                :questions="form.questions"
                @update="updateQuestion(index, $event)"
                @delete="deleteQuestion(index)"
                @duplicate="duplicateQuestion(index)"
              />
            </template>
          </draggable>

          <div v-if="form.questions.length === 0" class="empty-questions">
            <p>{{ t('No questions yet. Click "Add question" to get started.') }}</p>
          </div>
        </div>

        <div class="save-status" :class="{ saving: saving }">
          {{ saving ? t('Saving...') : t('All changes saved') }}
        </div>
      </div>
    </NcAppContent>

    <SettingsPanel
      v-if="showSettings"
      :settings="form.settings"
      :permissions="form.permissions"
      :can-edit-settings="permissions.editSettings"
      @update:settings="updateSettings"
      @update:permissions="updatePermissions"
      @close="showSettings = false"
    />

    <ShareDialog
      v-if="showShare"
      :file-id="fileId"
      :form="form"
      @close="showShare = false"
    />
  </NcContent>
</template>

<script>
import { ref, reactive, onMounted, watch } from 'vue';
import {
  NcContent,
  NcAppContent,
  NcButton,
  NcActions,
  NcActionButton,
  NcTextField,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import { v4 as uuidv4 } from 'uuid';
import draggable from 'vuedraggable';
import QuestionEditor from '../components/QuestionEditor.vue';
import SettingsPanel from '../components/SettingsPanel.vue';
import ShareDialog from '../components/ShareDialog.vue';
import Respond from './Respond.vue';
import PlusIcon from '../components/icons/PlusIcon.vue';
import EyeIcon from '../components/icons/EyeIcon.vue';
import CogIcon from '../components/icons/CogIcon.vue';
import ShareIcon from '../components/icons/ShareIcon.vue';
import ChartIcon from '../components/icons/ChartIcon.vue';

export default {
  name: 'Editor',
  components: {
    NcContent,
    NcAppContent,
    NcButton,
    NcActions,
    NcActionButton,
    NcTextField,
    draggable,
    QuestionEditor,
    SettingsPanel,
    ShareDialog,
    Respond,
    PlusIcon,
    EyeIcon,
    CogIcon,
    ShareIcon,
    ChartIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    initialForm: {
      type: Object,
      required: true,
    },
    role: {
      type: String,
      required: true,
    },
    permissions: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    const form = reactive({ ...props.initialForm });
    const saving = ref(false);
    const showSettings = ref(false);
    const showShare = ref(false);
    const showPreview = ref(false);

    let saveTimeout = null;

    const save = async () => {
      saving.value = true;
      try {
        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          {
            title: form.title,
            description: form.description,
            questions: form.questions,
            settings: form.settings,
            pages: form.pages,
          }
        );
      } catch (error) {
        showError(t('Failed to save form'));
        console.error(error);
      } finally {
        saving.value = false;
      }
    };

    const debouncedSave = () => {
      if (saveTimeout) {
        clearTimeout(saveTimeout);
      }
      saveTimeout = setTimeout(save, 500);
    };

    const addQuestion = () => {
      const newQuestion = {
        id: `q${uuidv4().split('-')[0]}`,
        type: 'text',
        question: '',
        description: '',
        required: false,
        options: [],
        showIf: null,
      };
      form.questions.push(newQuestion);
      debouncedSave();
    };

    const updateQuestion = (index, question) => {
      form.questions[index] = question;
      debouncedSave();
    };

    const deleteQuestion = (index) => {
      form.questions.splice(index, 1);
      debouncedSave();
    };

    const duplicateQuestion = (index) => {
      const original = form.questions[index];
      const duplicate = {
        ...JSON.parse(JSON.stringify(original)),
        id: `q${uuidv4().split('-')[0]}`,
      };
      form.questions.splice(index + 1, 0, duplicate);
      debouncedSave();
    };

    const updateSettings = (newSettings) => {
      Object.assign(form.settings, newSettings);
      debouncedSave();
    };

    const updatePermissions = (newPermissions) => {
      Object.assign(form.permissions, newPermissions);
      debouncedSave();
    };

    const viewResults = () => {
      window.location.href = generateUrl('/apps/formvox/results/{fileId}', { fileId: props.fileId });
    };

    return {
      form,
      saving,
      showSettings,
      showShare,
      showPreview,
      debouncedSave,
      addQuestion,
      updateQuestion,
      deleteQuestion,
      duplicateQuestion,
      updateSettings,
      updatePermissions,
      viewResults,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.editor-container {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
}

.editor-header {
  margin-bottom: 20px;

  .title-input {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 10px;
  }

  .description-input {
    font-size: 16px;
    color: var(--color-text-maxcontrast);
  }
}

.editor-toolbar {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--color-border);
}

.questions-container {
  min-height: 200px;
}

.empty-questions {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-maxcontrast);
  border: 2px dashed var(--color-border);
  border-radius: var(--border-radius-large);
}

.preview-container {
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  padding: 20px;
}

.save-status {
  position: fixed;
  bottom: 20px;
  right: 20px;
  padding: 8px 16px;
  background: var(--color-success);
  color: white;
  border-radius: var(--border-radius);
  font-size: 14px;
  transition: opacity 0.3s;

  &.saving {
    background: var(--color-warning);
  }
}
</style>
