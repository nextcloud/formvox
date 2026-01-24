<template>
  <div class="question-editor" :class="{ collapsed }">
    <div class="question-header">
      <span class="drag-handle">
        <DragIcon :size="20" />
      </span>

      <div class="question-number">{{ index + 1 }}</div>

      <select v-model="localQuestion.type" class="type-select" @change="onTypeChange">
        <optgroup :label="t('Text')">
          <option value="text">{{ t('Short text') }}</option>
          <option value="textarea">{{ t('Long text') }}</option>
          <option value="number">{{ t('Number') }}</option>
        </optgroup>
        <optgroup :label="t('Choice')">
          <option value="choice">{{ t('Single choice') }}</option>
          <option value="multiple">{{ t('Multiple choice') }}</option>
          <option value="dropdown">{{ t('Dropdown') }}</option>
        </optgroup>
        <optgroup :label="t('Date & Time')">
          <option value="date">{{ t('Date') }}</option>
          <option value="datetime">{{ t('Date & Time') }}</option>
          <option value="time">{{ t('Time') }}</option>
        </optgroup>
        <optgroup :label="t('Rating')">
          <option value="scale">{{ t('Scale') }}</option>
          <option value="rating">{{ t('Stars') }}</option>
          <option value="matrix">{{ t('Matrix') }}</option>
        </optgroup>
        <optgroup :label="t('Other')">
          <option value="file">{{ t('File upload') }}</option>
        </optgroup>
      </select>

      <div class="question-actions">
        <NcButton
          type="tertiary"
          @click="collapsed = !collapsed"
        >
          <template #icon>
            <ChevronIcon :size="20" :class="{ rotated: collapsed }" />
          </template>
        </NcButton>

        <NcActions>
          <NcActionButton @click="$emit('duplicate')">
            <template #icon>
              <CopyIcon :size="20" />
            </template>
            {{ t('Duplicate') }}
          </NcActionButton>
          <NcActionButton @click="showConditions = true">
            <template #icon>
              <BranchIcon :size="20" />
            </template>
            {{ t('Conditions') }}
          </NcActionButton>
          <template v-if="otherPages.length > 0">
            <NcActionButton
              v-for="page in otherPages"
              :key="page.id"
              @click="moveToPage(page.index)"
            >
              <template #icon>
                <PagesIcon :size="20" />
              </template>
              {{ t('Move to {page}', { page: page.title || t('Page {n}', { n: page.index + 1 }) }) }}
            </NcActionButton>
          </template>
          <NcActionButton @click="$emit('delete')">
            <template #icon>
              <DeleteIcon :size="20" />
            </template>
            {{ t('Delete') }}
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div v-show="!collapsed" class="question-body">
      <NcTextField
        v-model="localQuestion.question"
        :label="t('Question')"
        class="question-input"
        @update:model-value="emitUpdate"
      />

      <NcTextField
        v-model="localQuestion.description"
        :label="t('Description (optional)')"
        class="description-input"
        @update:model-value="emitUpdate"
      />

      <!-- Options for choice-based questions -->
      <div v-if="hasOptions" class="options-editor">
        <h4>{{ t('Options') }}</h4>
        <draggable
          v-model="localQuestion.options"
          item-key="id"
          handle=".option-handle"
          @end="emitUpdate"
        >
          <template #item="{ element: option, index: optIndex }">
            <div class="option-item">
              <span class="option-handle">
                <DragIcon :size="16" />
              </span>
              <NcTextField
                v-model="option.label"
                :placeholder="t('Option {n}', { n: optIndex + 1 })"
                @update:model-value="emitUpdate"
              />
              <NcTextField
                v-if="isQuizMode"
                v-model.number="option.score"
                type="number"
                :placeholder="t('Score')"
                class="score-input"
                @update:model-value="emitUpdate"
              />
              <NcButton
                type="tertiary"
                @click="removeOption(optIndex)"
              >
                <template #icon>
                  <CloseIcon :size="20" />
                </template>
              </NcButton>
            </div>
          </template>
        </draggable>
        <NcButton @click="addOption">
          <template #icon>
            <PlusIcon :size="20" />
          </template>
          {{ t('Add option') }}
        </NcButton>
      </div>

      <!-- Scale settings -->
      <div v-if="localQuestion.type === 'scale'" class="scale-settings">
        <div class="scale-row">
          <NcTextField
            v-model.number="localQuestion.scaleMin"
            type="number"
            :label="t('Min')"
            @update:model-value="emitUpdate"
          />
          <NcTextField
            v-model.number="localQuestion.scaleMax"
            type="number"
            :label="t('Max')"
            @update:model-value="emitUpdate"
          />
        </div>
        <div class="scale-row">
          <NcTextField
            v-model="localQuestion.scaleMinLabel"
            :label="t('Min label')"
            :placeholder="t('e.g. Not at all')"
            @update:model-value="emitUpdate"
          />
          <NcTextField
            v-model="localQuestion.scaleMaxLabel"
            :label="t('Max label')"
            :placeholder="t('e.g. Very much')"
            @update:model-value="emitUpdate"
          />
        </div>
      </div>

      <!-- Rating settings -->
      <div v-if="localQuestion.type === 'rating'" class="rating-settings">
        <NcTextField
          v-model.number="localQuestion.ratingMax"
          type="number"
          :label="t('Maximum stars')"
          @update:model-value="emitUpdate"
        />
      </div>

      <!-- Matrix settings -->
      <div v-if="localQuestion.type === 'matrix'" class="matrix-settings">
        <div class="matrix-section">
          <h4>{{ t('Rows') }}</h4>
          <div v-for="(row, rowIndex) in localQuestion.rows" :key="row.id" class="matrix-item">
            <NcTextField
              v-model="row.label"
              :placeholder="t('Row {n}', { n: rowIndex + 1 })"
              @update:model-value="emitUpdate"
            />
            <NcButton type="tertiary" @click="removeRow(rowIndex)">
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcButton @click="addRow">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('Add row') }}
          </NcButton>
        </div>

        <div class="matrix-section">
          <h4>{{ t('Columns') }}</h4>
          <div v-for="(col, colIndex) in localQuestion.columns" :key="col.id" class="matrix-item">
            <NcTextField
              v-model="col.label"
              :placeholder="t('Column {n}', { n: colIndex + 1 })"
              @update:model-value="emitUpdate"
            />
            <NcButton type="tertiary" @click="removeColumn(colIndex)">
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcButton @click="addColumn">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('Add column') }}
          </NcButton>
        </div>
      </div>

      <!-- Question settings -->
      <div class="question-settings">
        <NcCheckboxRadioSwitch
          :model-value="localQuestion.required"
          @update:model-value="localQuestion.required = $event; emitUpdate()"
        >
          {{ t('Required') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-if="hasOptions"
          :model-value="isQuizMode"
          @update:model-value="toggleQuizMode"
        >
          {{ t('Quiz mode (with scores)') }}
        </NcCheckboxRadioSwitch>
      </div>

      <!-- Condition indicator -->
      <div v-if="localQuestion.showIf" class="condition-indicator">
        <BranchIcon :size="16" />
        {{ t('This question has conditions') }}
        <NcButton type="tertiary" @click="showConditions = true">
          {{ t('Edit') }}
        </NcButton>
      </div>
    </div>

    <ConditionEditor
      v-if="showConditions"
      :condition="localQuestion.showIf"
      :questions="questions"
      :current-question-id="localQuestion.id"
      @update="updateCondition"
      @close="showConditions = false"
    />
  </div>
</template>

<script>
import { ref, reactive, computed, watch } from 'vue';
import {
  NcButton,
  NcActions,
  NcActionButton,
  NcTextField,
  NcCheckboxRadioSwitch,
} from '@nextcloud/vue';
import { v4 as uuidv4 } from 'uuid';
import { t } from '@/utils/l10n';
import draggable from 'vuedraggable';
import ConditionEditor from './ConditionEditor.vue';
import DragIcon from './icons/DragIcon.vue';
import ChevronIcon from './icons/ChevronIcon.vue';
import CopyIcon from './icons/CopyIcon.vue';
import BranchIcon from './icons/BranchIcon.vue';
import DeleteIcon from './icons/DeleteIcon.vue';
import CloseIcon from './icons/CloseIcon.vue';
import PlusIcon from './icons/PlusIcon.vue';
import PagesIcon from './icons/PagesIcon.vue';

export default {
  name: 'QuestionEditor',
  components: {
    NcButton,
    NcActions,
    NcActionButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    draggable,
    ConditionEditor,
    DragIcon,
    ChevronIcon,
    CopyIcon,
    BranchIcon,
    DeleteIcon,
    CloseIcon,
    PlusIcon,
    PagesIcon,
  },
  props: {
    question: {
      type: Object,
      required: true,
    },
    index: {
      type: Number,
      required: true,
    },
    questions: {
      type: Array,
      required: true,
    },
    pages: {
      type: Array,
      default: () => [],
    },
    currentPageIndex: {
      type: Number,
      default: 0,
    },
  },
  emits: ['update', 'delete', 'duplicate', 'move'],
  setup(props, { emit }) {
    const collapsed = ref(false);
    const showConditions = ref(false);
    const localQuestion = reactive({ ...props.question });

    watch(() => props.question, (newVal) => {
      Object.assign(localQuestion, newVal);
    }, { deep: true });

    const hasOptions = computed(() => {
      return ['choice', 'multiple', 'dropdown'].includes(localQuestion.type);
    });

    const isQuizMode = computed(() => {
      return localQuestion.options?.some(opt => typeof opt.score === 'number');
    });

    // Get other pages (excluding current page) for "Move to page" menu
    const otherPages = computed(() => {
      if (!props.pages || props.pages.length <= 1) return [];
      return props.pages
        .map((page, index) => ({ ...page, index }))
        .filter((_, index) => index !== props.currentPageIndex);
    });

    const moveToPage = (targetPageIndex) => {
      emit('move', targetPageIndex);
    };

    const emitUpdate = () => {
      emit('update', { ...localQuestion });
    };

    const onTypeChange = () => {
      // Initialize type-specific properties
      if (hasOptions.value && (!localQuestion.options || localQuestion.options.length === 0)) {
        localQuestion.options = [
          { id: `opt${uuidv4().split('-')[0]}`, label: '', value: '' },
          { id: `opt${uuidv4().split('-')[0]}`, label: '', value: '' },
        ];
      }

      if (localQuestion.type === 'scale') {
        localQuestion.scaleMin = localQuestion.scaleMin ?? 1;
        localQuestion.scaleMax = localQuestion.scaleMax ?? 5;
      }

      if (localQuestion.type === 'rating') {
        localQuestion.ratingMax = localQuestion.ratingMax ?? 5;
      }

      if (localQuestion.type === 'matrix') {
        localQuestion.rows = localQuestion.rows ?? [
          { id: 'r1', label: '' },
          { id: 'r2', label: '' },
        ];
        localQuestion.columns = localQuestion.columns ?? [
          { id: 'c1', label: '', value: 1 },
          { id: 'c2', label: '', value: 2 },
          { id: 'c3', label: '', value: 3 },
        ];
      }

      emitUpdate();
    };

    const addOption = () => {
      if (!localQuestion.options) {
        localQuestion.options = [];
      }
      localQuestion.options.push({
        id: `opt${uuidv4().split('-')[0]}`,
        label: '',
        value: '',
      });
      emitUpdate();
    };

    const removeOption = (index) => {
      localQuestion.options.splice(index, 1);
      emitUpdate();
    };

    const toggleQuizMode = (enabled) => {
      if (enabled) {
        localQuestion.options.forEach((opt, i) => {
          opt.score = 0;
        });
      } else {
        localQuestion.options.forEach(opt => {
          delete opt.score;
        });
      }
      emitUpdate();
    };

    const addRow = () => {
      if (!localQuestion.rows) {
        localQuestion.rows = [];
      }
      localQuestion.rows.push({
        id: `r${uuidv4().split('-')[0]}`,
        label: '',
      });
      emitUpdate();
    };

    const removeRow = (index) => {
      localQuestion.rows.splice(index, 1);
      emitUpdate();
    };

    const addColumn = () => {
      if (!localQuestion.columns) {
        localQuestion.columns = [];
      }
      const nextValue = localQuestion.columns.length + 1;
      localQuestion.columns.push({
        id: `c${uuidv4().split('-')[0]}`,
        label: '',
        value: nextValue,
      });
      emitUpdate();
    };

    const removeColumn = (index) => {
      localQuestion.columns.splice(index, 1);
      emitUpdate();
    };

    const updateCondition = (condition) => {
      localQuestion.showIf = condition;
      emitUpdate();
      showConditions.value = false;
    };

    return {
      collapsed,
      showConditions,
      localQuestion,
      hasOptions,
      isQuizMode,
      otherPages,
      emitUpdate,
      onTypeChange,
      addOption,
      removeOption,
      toggleQuizMode,
      addRow,
      removeRow,
      addColumn,
      removeColumn,
      updateCondition,
      moveToPage,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.question-editor {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  margin-bottom: 16px;

  &.collapsed {
    .question-body {
      display: none;
    }
  }
}

.question-header {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  gap: 12px;
  border-bottom: 1px solid var(--color-border);

  .drag-handle {
    cursor: grab;
    color: var(--color-text-maxcontrast);

    &:active {
      cursor: grabbing;
    }
  }

  .question-number {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-primary);
    color: white;
    border-radius: 50%;
    font-size: 12px;
    font-weight: bold;
  }

  .type-select {
    padding: 8px 32px 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    font-size: 14px;
    cursor: pointer;
    min-width: 140px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;

    &:hover {
      border-color: var(--color-primary-element);
    }

    &:focus {
      outline: 2px solid var(--color-primary-element);
      outline-offset: -2px;
    }
  }

  .question-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 4px;

    :deep(.action-item__menutoggle) {
      svg {
        display: block;
      }
    }
  }
}

/* Fix NcActionButton icon alignment */
:deep(.action-item) {
  .action-button {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;

    .action-button__icon {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;

      svg {
        display: block;
      }
    }

    .action-button__text {
      flex: 1;
      text-align: left;
    }
  }
}

.question-body {
  padding: 16px;
}

.question-input {
  margin-bottom: 20px;
}

.description-input {
  margin-bottom: 16px;
}

.options-editor {
  margin-bottom: 16px;

  h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 600;
  }

  .option-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;

    .option-handle {
      cursor: grab;
      color: var(--color-text-maxcontrast);
    }

    .score-input {
      width: 80px;
    }
  }
}

.scale-settings,
.rating-settings {
  margin-bottom: 16px;

  .scale-row {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
  }
}

.matrix-settings {
  margin-bottom: 16px;

  .matrix-section {
    margin-bottom: 16px;

    h4 {
      margin: 0 0 12px;
      font-size: 14px;
      font-weight: 600;
    }

    .matrix-item {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
  }
}

.question-settings {
  display: flex;
  gap: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

.condition-indicator {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  padding: 8px 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
  font-size: 14px;
  color: var(--color-text-maxcontrast);
}

.rotated {
  transform: rotate(-90deg);
}
</style>
