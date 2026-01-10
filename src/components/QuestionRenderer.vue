<template>
  <div class="question-renderer">
    <label class="question-label">
      {{ renderedQuestion }}
      <span v-if="question.required" class="required">*</span>
    </label>
    <p v-if="question.description" class="question-description">
      {{ question.description }}
    </p>

    <!-- Text -->
    <NcTextField
      v-if="question.type === 'text'"
      :value="value"
      @update:model-value="$emit('update:value', $event)"
    />

    <!-- Textarea -->
    <NcTextArea
      v-else-if="question.type === 'textarea'"
      :value="value"
      :rows="4"
      resize="vertical"
      @update:model-value="$emit('update:value', $event)"
    />

    <!-- Single Choice (Radio) -->
    <div v-else-if="question.type === 'choice'" class="choice-options">
      <NcCheckboxRadioSwitch
        v-for="option in question.options"
        :key="option.id"
        :model-value="value === option.value"
        type="radio"
        :name="`question-${question.id}`"
        @update:model-value="$emit('update:value', option.value)"
      >
        {{ option.label }}
      </NcCheckboxRadioSwitch>
    </div>

    <!-- Multiple Choice (Checkbox) -->
    <div v-else-if="question.type === 'multiple'" class="choice-options">
      <NcCheckboxRadioSwitch
        v-for="option in question.options"
        :key="option.id"
        :model-value="(value || []).includes(option.value)"
        type="checkbox"
        @update:model-value="toggleMultiple(option.value, $event)"
      >
        {{ option.label }}
      </NcCheckboxRadioSwitch>
    </div>

    <!-- Dropdown -->
    <select
      v-else-if="question.type === 'dropdown'"
      :value="value"
      class="dropdown-select"
      @change="$emit('update:value', $event.target.value)"
    >
      <option value="">{{ t('Select...') }}</option>
      <option v-for="option in question.options" :key="option.id" :value="option.value">
        {{ option.label }}
      </option>
    </select>

    <!-- Date -->
    <NcDateTimePicker
      v-else-if="question.type === 'date'"
      :value="value ? new Date(value) : null"
      type="date"
      @update:value="$emit('update:value', formatDate($event))"
    />

    <!-- DateTime -->
    <NcDateTimePicker
      v-else-if="question.type === 'datetime'"
      :value="value ? new Date(value) : null"
      type="datetime"
      @update:value="$emit('update:value', formatDateTime($event))"
    />

    <!-- Time -->
    <input
      v-else-if="question.type === 'time'"
      type="time"
      :value="value"
      class="time-input"
      @input="$emit('update:value', $event.target.value)"
    >

    <!-- Number -->
    <NcTextField
      v-else-if="question.type === 'number'"
      type="number"
      :value="value"
      @update:model-value="$emit('update:value', $event)"
    />

    <!-- Scale -->
    <div v-else-if="question.type === 'scale'" class="scale-input">
      <span v-if="question.scaleMinLabel" class="scale-label">{{ question.scaleMinLabel }}</span>
      <div class="scale-options">
        <button
          v-for="n in scaleRange"
          :key="n"
          type="button"
          class="scale-option"
          :class="{ selected: value === n }"
          @click="$emit('update:value', n)"
        >
          {{ n }}
        </button>
      </div>
      <span v-if="question.scaleMaxLabel" class="scale-label">{{ question.scaleMaxLabel }}</span>
    </div>

    <!-- Rating -->
    <div v-else-if="question.type === 'rating'" class="rating-input">
      <button
        v-for="n in ratingRange"
        :key="n"
        type="button"
        class="star-button"
        :class="{ filled: n <= value }"
        @click="$emit('update:value', n)"
      >
        <StarIcon :size="24" />
      </button>
    </div>

    <!-- File -->
    <div v-else-if="question.type === 'file'" class="file-input">
      <input
        type="file"
        @change="handleFileChange"
      >
      <p v-if="value" class="file-name">{{ value }}</p>
    </div>

    <!-- Matrix -->
    <div v-else-if="question.type === 'matrix'" class="matrix-input">
      <table class="matrix-table">
        <thead>
          <tr>
            <th></th>
            <th v-for="col in question.columns" :key="col.id">{{ col.label }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in question.rows" :key="row.id">
            <td class="row-label">{{ row.label }}</td>
            <td v-for="col in question.columns" :key="col.id" class="matrix-cell">
              <NcCheckboxRadioSwitch
                :model-value="(value || {})[row.id] === col.value"
                type="radio"
                :name="`matrix-${question.id}-${row.id}`"
                @update:model-value="updateMatrix(row.id, col.value)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import { t } from '@/utils/l10n';
import { computed } from 'vue';
import {
  NcTextField,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
} from '@nextcloud/vue';
import StarIcon from './icons/StarIcon.vue';

export default {
  name: 'QuestionRenderer',
  components: {
    NcTextField,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    StarIcon,
  },
  props: {
    question: {
      type: Object,
      required: true,
    },
    value: {
      type: [String, Number, Array, Object],
      default: '',
    },
    allAnswers: {
      type: Object,
      default: () => ({}),
    },
  },
  emits: ['update:value'],
  setup(props, { emit }) {
    // Piping support - replace {{qX}} with answers
    const renderedQuestion = computed(() => {
      let text = props.question.question || '';
      const matches = text.match(/\{\{(\w+)\}\}/g);
      if (matches) {
        matches.forEach(match => {
          const questionId = match.replace(/\{\{|\}\}/g, '');
          const answer = props.allAnswers[questionId];
          if (answer !== undefined && answer !== '') {
            text = text.replace(match, answer);
          }
        });
      }
      return text;
    });

    const scaleRange = computed(() => {
      const min = props.question.scaleMin || 1;
      const max = props.question.scaleMax || 5;
      const range = [];
      for (let i = min; i <= max; i++) {
        range.push(i);
      }
      return range;
    });

    const ratingRange = computed(() => {
      const max = props.question.ratingMax || 5;
      const range = [];
      for (let i = 1; i <= max; i++) {
        range.push(i);
      }
      return range;
    });

    const toggleMultiple = (optionValue, checked) => {
      const current = [...(props.value || [])];
      if (checked) {
        if (!current.includes(optionValue)) {
          current.push(optionValue);
        }
      } else {
        const index = current.indexOf(optionValue);
        if (index > -1) {
          current.splice(index, 1);
        }
      }
      emit('update:value', current);
    };

    const updateMatrix = (rowId, colValue) => {
      const current = { ...(props.value || {}) };
      current[rowId] = colValue;
      emit('update:value', current);
    };

    const formatDate = (date) => {
      if (!date) return '';
      return date.toISOString().split('T')[0];
    };

    const formatDateTime = (date) => {
      if (!date) return '';
      return date.toISOString();
    };

    const handleFileChange = (event) => {
      const file = event.target.files[0];
      if (file) {
        // For now, just store the filename
        // Full file upload would require additional backend support
        emit('update:value', file.name);
      }
    };

    return {
      renderedQuestion,
      scaleRange,
      ratingRange,
      toggleMultiple,
      updateMatrix,
      formatDate,
      formatDateTime,
      handleFileChange,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.question-renderer {
  .question-label {
    display: block;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 8px;

    .required {
      color: var(--color-error);
    }
  }

  .question-description {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
  }
}

.textarea-input {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  font-family: inherit;
  font-size: 14px;
  resize: vertical;
}

.choice-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.dropdown-select {
  width: 100%;
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  font-size: 14px;
}

.time-input {
  padding: 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  font-size: 14px;
}

.scale-input {
  display: flex;
  align-items: center;
  gap: 12px;

  .scale-label {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
  }

  .scale-options {
    display: flex;
    gap: 8px;
  }

  .scale-option {
    width: 40px;
    height: 40px;
    border: 2px solid var(--color-border);
    border-radius: 50%;
    background: var(--color-main-background);
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    transition: all 0.2s;

    &:hover {
      border-color: var(--color-primary);
    }

    &.selected {
      background: var(--color-primary);
      border-color: var(--color-primary);
      color: white;
    }
  }
}

.rating-input {
  display: flex;
  gap: 4px;

  .star-button {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-border-dark);
    transition: color 0.2s;

    &:hover,
    &.filled {
      color: #ffc107;
    }
  }
}

.file-input {
  input {
    padding: 10px;
  }

  .file-name {
    margin-top: 8px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
  }
}

.matrix-input {
  overflow-x: auto;

  .matrix-table {
    width: 100%;
    border-collapse: collapse;

    th, td {
      padding: 10px;
      text-align: center;
      border: 1px solid var(--color-border);
    }

    th {
      background: var(--color-background-hover);
      font-weight: 600;
    }

    .row-label {
      text-align: left;
      font-weight: 500;
    }

    .matrix-cell {
      padding: 5px;
    }
  }
}
</style>
