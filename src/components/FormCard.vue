<template>
  <div class="form-card" @click="$emit('click')">
    <div class="card-icon">
      <FormIcon :size="32" />
    </div>
    <div class="card-content">
      <h3 class="card-title">{{ form.title }}</h3>
      <p v-if="form.description" class="card-description">{{ truncate(form.description, 80) }}</p>
      <div class="card-meta">
        <span class="response-count">
          {{ t('{count} responses', { count: form.responseCount }) }}
        </span>
        <span class="modified-date">
          {{ formatDate(form.modifiedAt) }}
        </span>
      </div>
    </div>
    <NcActions>
      <NcActionButton @click.stop="$emit('delete')">
        <template #icon>
          <DeleteIcon :size="20" />
        </template>
        {{ t('Delete') }}
      </NcActionButton>
    </NcActions>
  </div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue';
import { t } from '@/utils/l10n';
import FormIcon from './icons/FormIcon.vue';
import DeleteIcon from './icons/DeleteIcon.vue';

export default {
  name: 'FormCard',
  components: {
    NcActions,
    NcActionButton,
    FormIcon,
    DeleteIcon,
  },
  props: {
    form: {
      type: Object,
      required: true,
    },
  },
  emits: ['click', 'delete'],
  setup() {
    const truncate = (text, length) => {
      if (!text) return '';
      if (text.length <= length) return text;
      return text.substring(0, length) + '...';
    };

    const formatDate = (dateString) => {
      if (!dateString) return '';
      const date = new Date(dateString);
      return date.toLocaleDateString();
    };

    return {
      truncate,
      formatDate,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.form-card {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 16px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: box-shadow 0.2s, border-color 0.2s;

  &:hover {
    border-color: var(--color-primary);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  }

  .card-icon {
    color: var(--color-primary);
    flex-shrink: 0;
  }

  .card-content {
    flex: 1;
    min-width: 0;
  }

  .card-title {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .card-description {
    margin: 0 0 8px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
  }

  .card-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);

    .response-count {
      font-weight: 500;
    }
  }
}
</style>
