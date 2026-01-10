<template>
  <NcModal @close="$emit('close')">
    <div class="new-form-modal">
      <h2>{{ t('Create new form') }}</h2>

      <NcTextField
        v-model="title"
        :label="t('Form title')"
        :placeholder="t('Enter a title for your form')"
        class="title-input"
        autofocus
      />

      <div class="template-section">
        <h3>{{ t('Start from a template') }}</h3>
        <div class="templates">
          <button
            v-for="tmpl in templates"
            :key="tmpl.id"
            type="button"
            class="template-card"
            :class="{ selected: selectedTemplate === tmpl.id }"
            @click="selectedTemplate = tmpl.id"
          >
            <component :is="tmpl.icon" :size="24" />
            <span class="template-name">{{ tmpl.name }}</span>
            <span class="template-description">{{ tmpl.description }}</span>
          </button>
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
import { ref } from 'vue';
import { NcModal, NcButton, NcTextField } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import FormIcon from './icons/FormIcon.vue';
import PollIcon from './icons/PollIcon.vue';
import SurveyIcon from './icons/SurveyIcon.vue';
import RegistrationIcon from './icons/RegistrationIcon.vue';
import DemoIcon from './icons/DemoIcon.vue';

export default {
  name: 'NewFormModal',
  components: {
    NcModal,
    NcButton,
    NcTextField,
    FormIcon,
    PollIcon,
    SurveyIcon,
    RegistrationIcon,
    DemoIcon,
  },
  emits: ['close', 'created'],
  setup(props, { emit }) {
    const title = ref('');
    const selectedTemplate = ref('blank');
    const creating = ref(false);

    const templates = [
      {
        id: 'blank',
        name: t('Blank form'),
        description: t('Start from scratch'),
        icon: FormIcon,
      },
      {
        id: 'poll',
        name: t('Poll'),
        description: t('Quick voting form'),
        icon: PollIcon,
      },
      {
        id: 'survey',
        name: t('Survey'),
        description: t('Feedback and opinions'),
        icon: SurveyIcon,
      },
      {
        id: 'registration',
        name: t('Registration'),
        description: t('Collect contact info'),
        icon: RegistrationIcon,
      },
      {
        id: 'demo',
        name: t('Demo Form'),
        description: t('All features showcase'),
        icon: DemoIcon,
      },
    ];

    const create = async () => {
      if (!title.value) return;

      creating.value = true;
      try {
        const response = await axios.post(generateUrl('/apps/formvox/api/forms'), {
          title: title.value,
          template: selectedTemplate.value === 'blank' ? null : selectedTemplate.value,
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
      selectedTemplate,
      creating,
      templates,
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
    margin: 0 0 20px;
  }

  .title-input {
    margin-bottom: 24px;
  }

  .template-section {
    h3 {
      margin: 0 0 12px;
      font-size: 14px;
      font-weight: 600;
      color: var(--color-text-maxcontrast);
    }

    .templates {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
    }

    .template-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 16px;
      border: 2px solid var(--color-border);
      border-radius: var(--border-radius-large);
      background: var(--color-main-background);
      cursor: pointer;
      transition: border-color 0.2s;

      &:hover {
        border-color: var(--color-primary);
      }

      &.selected {
        border-color: var(--color-primary);
        background: var(--color-primary-element-light);
      }

      .template-name {
        margin-top: 8px;
        font-weight: 600;
      }

      .template-description {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
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
