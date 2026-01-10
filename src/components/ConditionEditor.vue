<template>
  <NcModal @close="$emit('close')">
    <div class="condition-editor">
      <h2>{{ t('Show this question when...') }}</h2>

      <div v-if="!localCondition" class="no-condition">
        <p>{{ t('This question will always be shown.') }}</p>
        <NcButton
          type="primary"
          :disabled="availableQuestions.length === 0"
          @click="addSimpleCondition"
        >
          {{ t('Add condition') }}
        </NcButton>
        <p v-if="availableQuestions.length === 0" class="no-questions-hint">
          {{ t('No previous questions available. Conditions can only reference questions that appear before this one.') }}
        </p>
      </div>

      <div v-else class="condition-builder">
        <ConditionGroup
          :condition="localCondition"
          :questions="availableQuestions"
          :is-root="true"
          @update="updateCondition"
          @remove="localCondition = null"
        />
      </div>

      <div class="actions">
        <NcButton @click="$emit('close')">
          {{ t('Cancel') }}
        </NcButton>
        <NcButton type="primary" @click="save">
          {{ t('Save') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, computed } from 'vue';
import { NcModal, NcButton } from '@nextcloud/vue';
import ConditionGroup from './ConditionGroup.vue';

export default {
  name: 'ConditionEditor',
  components: {
    NcModal,
    NcButton,
    ConditionGroup,
  },
  props: {
    condition: {
      type: Object,
      default: null,
    },
    questions: {
      type: Array,
      required: true,
    },
    currentQuestionId: {
      type: String,
      required: true,
    },
  },
  emits: ['update', 'close'],
  setup(props, { emit }) {
    const localCondition = ref(props.condition ? JSON.parse(JSON.stringify(props.condition)) : null);

    const availableQuestions = computed(() => {
      // Only show questions that come before the current question
      const currentIndex = props.questions.findIndex(q => q.id === props.currentQuestionId);
      return props.questions.slice(0, currentIndex);
    });

    const addSimpleCondition = () => {
      if (availableQuestions.value.length === 0) {
        return;
      }

      localCondition.value = {
        questionId: availableQuestions.value[0].id,
        operator: 'equals',
        value: '',
      };
    };

    const updateCondition = (newCondition) => {
      localCondition.value = newCondition;
    };

    const save = () => {
      emit('update', localCondition.value);
    };

    return {
      localCondition,
      availableQuestions,
      addSimpleCondition,
      updateCondition,
      save,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.condition-editor {
  padding: 20px;
  min-width: 500px;

  h2 {
    margin: 0 0 20px;
  }
}

.no-condition {
  text-align: center;
  padding: 30px;

  p {
    color: var(--color-text-maxcontrast);
    margin-bottom: 20px;
  }

  .no-questions-hint {
    margin-top: 16px;
    font-size: 13px;
    font-style: italic;
  }
}

.condition-builder {
  margin-bottom: 20px;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}
</style>
