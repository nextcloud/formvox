<template>
  <div
    class="question-editor"
    :class="{ collapsed, 'has-color': localQuestion.color }"
    :style="localQuestion.color ? { '--question-color': localQuestion.color } : {}"
  >
    <div class="question-header">
      <span class="drag-handle">
        <DragIcon :size="20" />
      </span>

      <div class="question-number">{{ index + 1 }}</div>

      <select v-model="localQuestion.type" class="type-select" :disabled="readonly" @change="onTypeChange">
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
        <!-- Color picker -->
        <NcActions v-if="!readonly" ref="colorPickerRef" class="color-picker-action">
          <template #icon>
            <span
              class="color-indicator"
              :style="localQuestion.color ? { backgroundColor: localQuestion.color } : {}"
              :class="{ 'no-color': !localQuestion.color }"
            />
          </template>
          <NcActionButton
            v-for="color in colorOptions"
            :key="color.value"
            :close-after-click="true"
            :class="{ 'color-selected': localQuestion.color === color.value }"
            @click="localQuestion.color = color.value; emitUpdate()"
          >
            <template #icon>
              <span
                class="color-swatch"
                :style="color.value ? { backgroundColor: color.value } : {}"
                :class="{ 'no-color-swatch': !color.value }"
              />
            </template>
            {{ color.label }}
          </NcActionButton>
        </NcActions>

        <NcButton
          type="tertiary"
          @click="collapsed = !collapsed"
        >
          <template #icon>
            <ChevronIcon :size="20" :class="{ rotated: collapsed }" />
          </template>
        </NcButton>

        <NcActions v-if="!readonly">
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
      <div class="form-field">
        <label class="form-label">{{ t('Question') }}</label>
        <NcTextField
          v-model="localQuestion.question"
          :disabled="readonly"
          :placeholder="t('Enter your question')"
          class="question-input"
          @update:model-value="emitUpdate"
        />
      </div>

      <div class="form-field">
        <label class="form-label">{{ t('Description (optional)') }}</label>
        <NcTextArea
          v-model="localQuestion.description"
          :disabled="readonly"
          :placeholder="t('Add a description or instructions')"
          :resize="false"
          :rows="2"
          class="description-input"
          @update:model-value="emitUpdate"
        />
      </div>

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

      <!-- File upload settings -->
      <div v-if="localQuestion.type === 'file'" class="file-settings">
        <div class="form-field">
          <label class="form-label">{{ t('Allowed file types') }}</label>
          <select v-model="localQuestion.allowedTypePreset" class="type-select" @change="onFileTypePresetChange">
            <option value="images">{{ t('Images only') }}</option>
            <option value="documents">{{ t('Documents (PDF, Word, Excel)') }}</option>
            <option value="pdf">{{ t('PDF only') }}</option>
            <option value="all">{{ t('All files') }}</option>
            <option value="custom">{{ t('Custom') }}</option>
          </select>
        </div>

        <div v-if="localQuestion.allowedTypePreset === 'custom'" class="form-field">
          <label class="form-label">{{ t('Custom MIME types or extensions') }}</label>
          <NcTextField
            v-model="customTypesString"
            :placeholder="t('e.g. .pdf, .docx, image/*')"
            @update:model-value="onCustomTypesChange"
          />
          <small class="hint">{{ t('Comma-separated list of extensions (.pdf) or MIME types (image/*)') }}</small>
        </div>

        <div class="form-field">
          <label class="form-label">{{ t('Maximum file size (MB)') }}</label>
          <NcTextField
            v-model.number="localQuestion.maxFileSize"
            type="number"
            :min="1"
            :max="100"
            @update:model-value="emitUpdate"
          />
        </div>

        <div class="form-field">
          <NcCheckboxRadioSwitch
            :model-value="localQuestion.maxFiles > 1"
            @update:model-value="localQuestion.maxFiles = $event ? 5 : 1; emitUpdate()"
          >
            {{ t('Allow multiple files') }}
          </NcCheckboxRadioSwitch>
        </div>

        <div v-if="localQuestion.maxFiles > 1" class="form-field">
          <label class="form-label">{{ t('Maximum number of files') }}</label>
          <NcTextField
            v-model.number="localQuestion.maxFiles"
            type="number"
            :min="2"
            :max="20"
            @update:model-value="emitUpdate"
          />
        </div>
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

      <!-- Custom validation (text types only) -->
      <div v-if="supportsValidation" class="validation-settings">
        <NcCheckboxRadioSwitch
          :model-value="hasValidation"
          @update:model-value="toggleValidation"
        >
          {{ t('Validation pattern') }}
        </NcCheckboxRadioSwitch>

        <div v-if="hasValidation" class="validation-fields">
          <!-- Easy mode: preset patterns -->
          <div class="form-field">
            <label class="form-label">{{ t('Validation type') }}</label>
            <NcSelect
              v-model="validationPreset"
              :options="validationPresetOptions"
              :placeholder="t('Select a validation type')"
              label="label"
              track-by="value"
              @update:model-value="onValidationPresetChange"
            />
          </div>

          <!-- Custom regex input (only shown for 'custom' preset) -->
          <div v-if="validationPreset?.value === 'custom'" class="form-field">
            <label class="form-label">{{ t('Regular expression') }}</label>
            <NcTextField
              v-model="localQuestion.validation.pattern"
              :placeholder="t('e.g. ^[0-9]{4}[A-Z]{2}$')"
              @update:model-value="emitUpdate"
            />
            <small class="hint">{{ t('Examples: ^[A-Z]{2}[0-9]{4}$ (license plate), ^\\d{10}$ (10 digits)') }}</small>
          </div>

          <!-- Custom error message -->
          <div class="form-field">
            <label class="form-label">{{ t('Error message') }}</label>
            <NcTextField
              v-model="localQuestion.validation.errorMessage"
              :placeholder="validationPreset?.defaultError || t('e.g. Please enter a valid value')"
              @update:model-value="emitUpdate"
            />
          </div>
        </div>
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
  NcTextArea,
  NcCheckboxRadioSwitch,
  NcSelect,
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
    NcTextArea,
    NcCheckboxRadioSwitch,
    NcSelect,
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
    readonly: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['update', 'delete', 'duplicate', 'move'],
  setup(props, { emit }) {
    const collapsed = ref(false);
    const showConditions = ref(false);
    // Deep copy to preserve nested objects like validation
    const localQuestion = reactive(JSON.parse(JSON.stringify(props.question)));
    const customTypesString = ref('');

    // Color options for question highlighting
    const colorOptions = [
      { value: '', label: t('No color') },
      { value: '#0082c9', label: t('Blue') },
      { value: '#00a86b', label: t('Green') },
      { value: '#f4a100', label: t('Orange') },
      { value: '#e53935', label: t('Red') },
      { value: '#9c27b0', label: t('Purple') },
      { value: '#00bcd4', label: t('Cyan') },
      { value: '#795548', label: t('Brown') },
    ];

    // File type presets
    const fileTypePresets = {
      images: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
      documents: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
      pdf: ['application/pdf'],
      all: ['*/*'],
    };

    watch(() => props.question, (newVal) => {
      // Deep copy to preserve nested objects like validation
      Object.keys(localQuestion).forEach(key => {
        delete localQuestion[key];
      });
      Object.assign(localQuestion, JSON.parse(JSON.stringify(newVal)));
    }, { deep: true });

    const hasOptions = computed(() => {
      return ['choice', 'multiple', 'dropdown'].includes(localQuestion.type);
    });

    const isQuizMode = computed(() => {
      return localQuestion.options?.some(opt => typeof opt.score === 'number');
    });

    // Validation support for text-based types
    const supportsValidation = computed(() => {
      return ['text', 'textarea', 'number'].includes(localQuestion.type);
    });

    const hasValidation = computed(() => {
      return localQuestion.validation !== undefined && localQuestion.validation !== null;
    });

    // Validation presets for easy mode
    const validationPresets = {
      email: { pattern: '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$', defaultError: t('Please enter a valid email address') },
      phone_nl: { pattern: '^(\\+31|0)[1-9][0-9]{8}$', defaultError: t('Please enter a valid Dutch phone number') },
      phone_intl: { pattern: '^\\+?[1-9]\\d{6,14}$', defaultError: t('Please enter a valid phone number') },
      postal_nl: { pattern: '^[1-9][0-9]{3}\\s?[A-Za-z]{2}$', defaultError: t('Please enter a valid Dutch postal code (e.g. 1234 AB)') },
      postal_be: { pattern: '^[1-9][0-9]{3}$', defaultError: t('Please enter a valid Belgian postal code (e.g. 1000)') },
      url: { pattern: '^https?:\\/\\/[\\w\\-]+(\\.[\\w\\-]+)+[/#?]?.*$', defaultError: t('Please enter a valid URL') },
      digits_only: { pattern: '^[0-9]+$', defaultError: t('Please enter only digits') },
      letters_only: { pattern: '^[a-zA-Z]+$', defaultError: t('Please enter only letters') },
      alphanumeric: { pattern: '^[a-zA-Z0-9]+$', defaultError: t('Please enter only letters and numbers') },
      custom: { pattern: '', defaultError: '' },
    };

    const validationPresetOptions = [
      { value: 'email', label: t('Email address'), defaultError: validationPresets.email.defaultError },
      { value: 'phone_nl', label: t('Phone number (NL)'), defaultError: validationPresets.phone_nl.defaultError },
      { value: 'phone_intl', label: t('Phone number (international)'), defaultError: validationPresets.phone_intl.defaultError },
      { value: 'postal_nl', label: t('Postal code (NL)'), defaultError: validationPresets.postal_nl.defaultError },
      { value: 'postal_be', label: t('Postal code (BE)'), defaultError: validationPresets.postal_be.defaultError },
      { value: 'url', label: t('Website URL'), defaultError: validationPresets.url.defaultError },
      { value: 'digits_only', label: t('Digits only'), defaultError: validationPresets.digits_only.defaultError },
      { value: 'letters_only', label: t('Letters only'), defaultError: validationPresets.letters_only.defaultError },
      { value: 'alphanumeric', label: t('Letters and numbers'), defaultError: validationPresets.alphanumeric.defaultError },
      { value: 'custom', label: t('Custom (advanced)'), defaultError: '' },
    ];

    // Determine current validation preset based on pattern
    const validationPreset = ref(null);

    // Initialize validationPreset when question changes
    watch(() => localQuestion.validation?.pattern, (pattern) => {
      if (!pattern) {
        validationPreset.value = null;
        return;
      }
      // Find matching preset
      const found = Object.entries(validationPresets).find(([key, preset]) => preset.pattern === pattern);
      if (found) {
        validationPreset.value = validationPresetOptions.find(opt => opt.value === found[0]) || null;
      } else {
        // Custom pattern
        validationPreset.value = validationPresetOptions.find(opt => opt.value === 'custom') || null;
      }
    }, { immediate: true });

    const onValidationPresetChange = (selected) => {
      if (!selected) {
        localQuestion.validation.pattern = '';
        localQuestion.validation.errorMessage = '';
      } else if (selected.value === 'custom') {
        // Keep existing pattern if any, or clear
        if (!localQuestion.validation.pattern) {
          localQuestion.validation.pattern = '';
        }
      } else {
        const preset = validationPresets[selected.value];
        localQuestion.validation.pattern = preset.pattern;
        // Only set default error if user hasn't customized it
        if (!localQuestion.validation.errorMessage) {
          localQuestion.validation.errorMessage = preset.defaultError;
        }
      }
      emitUpdate();
    };

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
      // Deep copy to preserve nested objects like validation
      emit('update', JSON.parse(JSON.stringify(localQuestion)));
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

      if (localQuestion.type === 'file') {
        localQuestion.allowedTypePreset = localQuestion.allowedTypePreset ?? 'all';
        localQuestion.allowedTypes = localQuestion.allowedTypes ?? fileTypePresets.all;
        localQuestion.maxFileSize = localQuestion.maxFileSize ?? 10;
        localQuestion.maxFiles = localQuestion.maxFiles ?? 1;
      }

      emitUpdate();
    };

    const onFileTypePresetChange = () => {
      const preset = localQuestion.allowedTypePreset;
      if (preset && preset !== 'custom') {
        localQuestion.allowedTypes = fileTypePresets[preset] || fileTypePresets.all;
      }
      emitUpdate();
    };

    const onCustomTypesChange = (value) => {
      customTypesString.value = value;
      // Parse comma-separated values
      localQuestion.allowedTypes = value
        .split(',')
        .map(t => t.trim())
        .filter(t => t.length > 0);
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

    const toggleValidation = (enabled) => {
      if (enabled) {
        localQuestion.validation = {
          pattern: '',
          errorMessage: '',
        };
      } else {
        delete localQuestion.validation;
      }
      emitUpdate();
    };

    const updateValidationPattern = (value) => {
      if (!localQuestion.validation) {
        localQuestion.validation = {};
      }
      localQuestion.validation.pattern = value;
      emitUpdate();
    };

    const updateValidationErrorMessage = (value) => {
      if (!localQuestion.validation) {
        localQuestion.validation = {};
      }
      localQuestion.validation.errorMessage = value;
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
      customTypesString,
      colorOptions,
      hasOptions,
      isQuizMode,
      supportsValidation,
      hasValidation,
      validationPreset,
      validationPresetOptions,
      onValidationPresetChange,
      otherPages,
      emitUpdate,
      onTypeChange,
      onFileTypePresetChange,
      onCustomTypesChange,
      addOption,
      removeOption,
      toggleQuizMode,
      toggleValidation,
      updateValidationPattern,
      updateValidationErrorMessage,
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
  transition: box-shadow 0.2s, border-color 0.2s;

  &.has-color {
    border-left: 4px solid var(--question-color);
  }

  &:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  }

  &:focus-within {
    border-color: var(--color-primary-element);

    &.has-color {
      border-left-color: var(--question-color);
    }
  }

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
    background: var(--color-primary-element);
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

.form-field {
  margin-bottom: 16px;

  &:last-child {
    margin-bottom: 0;
  }
}

.form-label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--color-main-text);
  // Uitlijnen met input padding (12px is Nextcloud standaard input padding)
  padding-left: 12px;
}

.question-input {
  // Standaard NcTextField styling
}

.description-input {
  :deep(textarea) {
    resize: none;
    min-height: 52px;
  }
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

.file-settings {
  margin-bottom: 16px;

  .hint {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    padding-left: 12px;
  }
}

.question-settings {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

/* Color picker in header */
.color-picker-action {
  .color-indicator {
    display: block;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid var(--color-border);

    &.no-color {
      background: linear-gradient(135deg, transparent 45%, var(--color-error) 45%, var(--color-error) 55%, transparent 55%);
      background-color: var(--color-background-hover);
    }
  }
}

.color-swatch {
  display: block;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.1);

  &.no-color-swatch {
    background: linear-gradient(135deg, transparent 45%, var(--color-error) 45%, var(--color-error) 55%, transparent 55%);
    background-color: var(--color-background-hover);
  }
}

.color-selected {
  background: var(--color-primary-element-light);
}

.validation-settings {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);

  .validation-fields {
    margin-top: 12px;
    padding-left: 28px;

    .form-field {
      margin-bottom: 12px;
    }

    .hint {
      display: block;
      margin-top: 8px;
      font-size: 12px;
      color: var(--color-text-maxcontrast);
      padding-left: 12px;
    }
  }
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

/* Fix icon vertical alignment in NcActionButton and NcButton */
:deep(.action-button__icon),
:deep(.button-vue__icon) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
</style>
