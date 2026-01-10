<template>
  <div class="condition-group" :class="{ nested: !isRoot }">
    <!-- Simple condition -->
    <div v-if="isSimpleCondition" class="simple-condition">
      <select v-model="localCondition.questionId" @change="onQuestionChange">
        <option v-for="q in questions" :key="q.id" :value="q.id">
          {{ q.question || `Question ${questions.indexOf(q) + 1}` }}
        </option>
      </select>

      <select v-model="localCondition.operator" @change="emitUpdate">
        <option value="equals">{{ t('equals') }}</option>
        <option value="notEquals">{{ t('does not equal') }}</option>
        <option value="contains">{{ t('contains') }}</option>
        <option value="notContains">{{ t('does not contain') }}</option>
        <option value="isEmpty">{{ t('is empty') }}</option>
        <option value="isNotEmpty">{{ t('is not empty') }}</option>
        <option value="greaterThan">{{ t('is greater than') }}</option>
        <option value="lessThan">{{ t('is less than') }}</option>
        <option value="in">{{ t('is one of') }}</option>
        <option value="notIn">{{ t('is not one of') }}</option>
      </select>

      <template v-if="needsValue">
        <select
          v-if="selectedQuestionOptions.length > 0"
          v-model="localCondition.value"
          @change="emitUpdate"
        >
          <option value="">{{ t('Select...') }}</option>
          <option v-for="opt in selectedQuestionOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
        <input
          v-else
          v-model="localCondition.value"
          type="text"
          :placeholder="t('Value')"
          @input="emitUpdate"
        >
      </template>

      <div class="condition-actions">
        <NcButton type="tertiary" @click="convertToGroup('and')">
          <template #icon>
            <PlusIcon :size="16" />
          </template>
          AND
        </NcButton>
        <NcButton type="tertiary" @click="convertToGroup('or')">
          <template #icon>
            <PlusIcon :size="16" />
          </template>
          OR
        </NcButton>
        <NcButton type="tertiary" @click="$emit('remove')">
          <template #icon>
            <DeleteIcon :size="16" />
          </template>
        </NcButton>
      </div>
    </div>

    <!-- Combined condition (AND/OR) -->
    <div v-else class="combined-condition">
      <div class="group-header">
        <select v-model="localCondition.operator" @change="emitUpdate">
          <option value="and">{{ t('ALL of the following') }}</option>
          <option value="or">{{ t('ANY of the following') }}</option>
        </select>
        <NcButton type="tertiary" @click="$emit('remove')">
          <template #icon>
            <DeleteIcon :size="16" />
          </template>
        </NcButton>
      </div>

      <div class="conditions-list">
        <ConditionGroup
          v-for="(cond, index) in localCondition.conditions"
          :key="index"
          :condition="cond"
          :questions="questions"
          :is-root="false"
          @update="updateSubCondition(index, $event)"
          @remove="removeSubCondition(index)"
        />
      </div>

      <NcButton @click="addSubCondition">
        <template #icon>
          <PlusIcon :size="16" />
        </template>
        {{ t('Add condition') }}
      </NcButton>
    </div>
  </div>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, computed, watch } from 'vue';
import { NcButton } from '@nextcloud/vue';
import PlusIcon from './icons/PlusIcon.vue';
import DeleteIcon from './icons/DeleteIcon.vue';

export default {
  name: 'ConditionGroup',
  components: {
    NcButton,
    PlusIcon,
    DeleteIcon,
  },
  props: {
    condition: {
      type: Object,
      required: true,
    },
    questions: {
      type: Array,
      required: true,
    },
    isRoot: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['update', 'remove'],
  setup(props, { emit }) {
    const localCondition = ref(JSON.parse(JSON.stringify(props.condition)));

    watch(() => props.condition, (newVal) => {
      localCondition.value = JSON.parse(JSON.stringify(newVal));
    }, { deep: true });

    const isSimpleCondition = computed(() => {
      return localCondition.value.questionId !== undefined;
    });

    const needsValue = computed(() => {
      const op = localCondition.value.operator;
      return !['isEmpty', 'isNotEmpty'].includes(op);
    });

    const selectedQuestion = computed(() => {
      return props.questions.find(q => q.id === localCondition.value.questionId);
    });

    const selectedQuestionOptions = computed(() => {
      if (!selectedQuestion.value || !selectedQuestion.value.options) {
        return [];
      }
      return selectedQuestion.value.options;
    });

    const emitUpdate = () => {
      emit('update', { ...localCondition.value });
    };

    const onQuestionChange = () => {
      localCondition.value.value = '';
      emitUpdate();
    };

    const convertToGroup = (operator) => {
      const currentCondition = { ...localCondition.value };
      localCondition.value = {
        operator,
        conditions: [
          currentCondition,
          {
            questionId: props.questions[0]?.id || '',
            operator: 'equals',
            value: '',
          },
        ],
      };
      emitUpdate();
    };

    const addSubCondition = () => {
      localCondition.value.conditions.push({
        questionId: props.questions[0]?.id || '',
        operator: 'equals',
        value: '',
      });
      emitUpdate();
    };

    const updateSubCondition = (index, newCondition) => {
      localCondition.value.conditions[index] = newCondition;
      emitUpdate();
    };

    const removeSubCondition = (index) => {
      localCondition.value.conditions.splice(index, 1);

      // If only one condition left, simplify to simple condition
      if (localCondition.value.conditions.length === 1) {
        localCondition.value = localCondition.value.conditions[0];
      }

      emitUpdate();
    };

    return {
      localCondition,
      isSimpleCondition,
      needsValue,
      selectedQuestionOptions,
      emitUpdate,
      onQuestionChange,
      convertToGroup,
      addSubCondition,
      updateSubCondition,
      removeSubCondition,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.condition-group {
  &.nested {
    margin-left: 20px;
    padding-left: 20px;
    border-left: 2px solid var(--color-border);
  }
}

.simple-condition {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 10px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
  margin-bottom: 10px;

  select, input {
    padding: 6px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
  }

  .condition-actions {
    margin-left: auto;
    display: flex;
    gap: 4px;
  }
}

.combined-condition {
  .group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;

    select {
      padding: 6px 12px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      background: var(--color-main-background);
      font-weight: bold;
    }
  }

  .conditions-list {
    margin-bottom: 10px;
  }
}
</style>
