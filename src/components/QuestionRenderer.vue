<template>
  <div
    class="question-renderer"
    :class="{ 'has-color': question.color }"
    :style="question.color ? { '--question-color': question.color } : {}"
    role="group"
    :aria-labelledby="`question-label-${question.id}`"
    :aria-describedby="ariaDescribedBy"
  >
    <div class="question-label-row">
      <label :id="`question-label-${question.id}`" class="question-label" :for="inputId">
        {{ renderedQuestion }}
        <span v-if="question.required" class="required-indicator" :title="t('Required')" aria-hidden="true">*</span>
        <span v-if="question.required" class="required-text">{{ t('(required)') }}</span>
      </label>
      <button
        v-if="ttsSupported"
        type="button"
        class="tts-button"
        :class="{ speaking: isSpeaking }"
        :aria-label="isSpeaking ? t('Stop reading question aloud') : t('Read question aloud')"
        :aria-pressed="isSpeaking"
        @click="toggleTts"
      >
        <SpeakerIcon :size="18" />
      </button>
    </div>
    <p
      v-if="question.description"
      :id="`question-desc-${question.id}`"
      class="question-description"
    >
      {{ renderedDescription }}
    </p>

    <!-- Text -->
    <NcTextField
      v-if="question.type === 'text'"
      :id="inputId"
      :value="value"
      :error="!!effectiveError"
      :aria-required="question.required || undefined"
      :aria-invalid="!!effectiveError || undefined"
      :aria-describedby="ariaDescribedBy"
      @update:model-value="$emit('update:value', $event); clearValidationError()"
      @blur="validatePattern"
    />
    <p
      v-if="question.type === 'text' && effectiveError"
      :id="`question-error-${question.id}`"
      class="validation-error"
      role="alert"
    >
      {{ effectiveError }}
    </p>

    <!-- Textarea -->
    <div v-else-if="question.type === 'textarea'" class="textarea-wrapper">
      <textarea
        :id="inputId"
        :value="value"
        rows="4"
        class="nc-textarea"
        :class="{ 'has-error': !!effectiveError }"
        :aria-required="question.required || undefined"
        :aria-invalid="!!effectiveError || undefined"
        :aria-describedby="ariaDescribedBy"
        @input="$emit('update:value', $event.target.value); clearValidationError()"
        @blur="validatePattern"
      />
      <p
        v-if="effectiveError"
        :id="`question-error-${question.id}`"
        class="validation-error"
        role="alert"
      >
        {{ effectiveError }}
      </p>
    </div>

    <!-- Single Choice (Radio) -->
    <div
      v-else-if="question.type === 'choice'"
      class="choice-options"
      role="radiogroup"
      :aria-labelledby="`question-label-${question.id}`"
      :aria-required="question.required || undefined"
    >
      <NcCheckboxRadioSwitch
        v-for="option in question.options"
        :key="option.id"
        :model-value="value === option.value"
        type="radio"
        :name="`question-${question.id}`"
        @update:model-value="$emit('update:value', option.value)"
      >
        {{ renderPiping(option.label) }}
      </NcCheckboxRadioSwitch>
    </div>

    <!-- Multiple Choice (Checkbox) -->
    <div
      v-else-if="question.type === 'multiple'"
      class="choice-options"
      role="group"
      :aria-labelledby="`question-label-${question.id}`"
      :aria-required="question.required || undefined"
    >
      <NcCheckboxRadioSwitch
        v-for="option in question.options"
        :key="option.id"
        :model-value="(value || []).includes(option.value)"
        type="checkbox"
        @update:model-value="toggleMultiple(option.value, $event)"
      >
        {{ renderPiping(option.label) }}
      </NcCheckboxRadioSwitch>
    </div>

    <!-- Dropdown -->
    <select
      v-else-if="question.type === 'dropdown'"
      :id="inputId"
      :value="value"
      class="dropdown-select"
      :aria-required="question.required || undefined"
      :aria-describedby="ariaDescribedBy"
      :aria-label="renderedQuestion"
      @change="$emit('update:value', $event.target.value)"
    >
      <option value="">{{ t('Select...') }}</option>
      <option v-for="option in question.options" :key="option.id" :value="option.value">
        {{ renderPiping(option.label) }}
      </option>
    </select>

    <!-- Date -->
    <NcDateTimePicker
      v-else-if="question.type === 'date'"
      :model-value="value ? new Date(value) : null"
      type="date"
      @update:model-value="$emit('update:value', formatDate($event))"
    />

    <!-- DateTime -->
    <NcDateTimePicker
      v-else-if="question.type === 'datetime'"
      :model-value="value ? new Date(value) : null"
      type="datetime"
      @update:model-value="$emit('update:value', formatDateTime($event))"
    />

    <!-- Time -->
    <input
      v-else-if="question.type === 'time'"
      :id="inputId"
      type="time"
      :value="value"
      class="time-input"
      :aria-required="question.required || undefined"
      :aria-label="renderedQuestion"
      @input="$emit('update:value', $event.target.value)"
    >

    <!-- Number -->
    <NcTextField
      v-else-if="question.type === 'number'"
      :id="inputId"
      type="number"
      :value="value"
      :error="!!effectiveError"
      :aria-required="question.required || undefined"
      :aria-invalid="!!effectiveError || undefined"
      :aria-describedby="ariaDescribedBy"
      @update:model-value="$emit('update:value', $event); clearValidationError()"
      @blur="validatePattern"
    />
    <p
      v-if="question.type === 'number' && effectiveError"
      :id="`question-error-${question.id}`"
      class="validation-error"
      role="alert"
    >
      {{ effectiveError }}
    </p>

    <!-- Scale -->
    <div v-else-if="question.type === 'scale'" class="scale-input">
      <span v-if="question.scaleMinLabel" class="scale-label">{{ question.scaleMinLabel }}</span>
      <div
        class="scale-options"
        role="radiogroup"
        :aria-labelledby="`question-label-${question.id}`"
        :aria-required="question.required || undefined"
        @keydown="handleScaleKeydown($event)"
      >
        <button
          v-for="n in scaleRange"
          :key="n"
          type="button"
          class="scale-option"
          :class="{ selected: value === n }"
          role="radio"
          :aria-checked="value === n"
          :aria-label="`${n}`"
          :tabindex="getRadioTabindex(n, scaleRange, value)"
          @click="$emit('update:value', n)"
        >
          {{ n }}
        </button>
      </div>
      <span v-if="question.scaleMaxLabel" class="scale-label">{{ question.scaleMaxLabel }}</span>
    </div>

    <!-- Rating -->
    <div
      v-else-if="question.type === 'rating'"
      class="rating-input"
      role="radiogroup"
      :aria-labelledby="`question-label-${question.id}`"
      :aria-required="question.required || undefined"
      @keydown="handleRatingKeydown($event)"
    >
      <button
        v-for="n in ratingRange"
        :key="n"
        type="button"
        class="star-button"
        :class="{ filled: n <= value }"
        role="radio"
        :aria-checked="value === n"
        :aria-label="t('{n} stars', { n })"
        :tabindex="getRadioTabindex(n, ratingRange, value)"
        @click="$emit('update:value', n)"
      >
        <StarIcon :size="24" />
      </button>
    </div>

    <!-- File -->
    <div v-else-if="question.type === 'file'" class="file-input">
      <div
        class="file-upload-zone"
        :class="{ 'drag-over': isDragging, 'has-files': selectedFiles.length > 0 }"
        role="button"
        tabindex="0"
        :aria-label="t('Drop files here or click to upload')"
        @dragover.prevent="isDragging = true"
        @dragleave="isDragging = false"
        @drop.prevent="handleDrop"
        @click="triggerFileInput"
        @keydown.enter="triggerFileInput"
        @keydown.space.prevent="triggerFileInput"
      >
        <input
          ref="fileInput"
          type="file"
          :accept="acceptString"
          :multiple="question.maxFiles > 1"
          hidden
          @change="handleFileSelect"
        >

        <div v-if="selectedFiles.length === 0" class="upload-prompt">
          <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
          </svg>
          <p>{{ t('Drop files here or click to upload') }}</p>
          <small>{{ allowedTypesText }} &bull; {{ t('Max {size} MB', { size: question.maxFileSize || 10 }) }}</small>
        </div>

        <div v-else class="uploaded-files">
          <div v-for="(file, index) in selectedFiles" :key="index" class="file-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
              <polyline points="13 2 13 9 20 9"/>
            </svg>
            <span class="file-name">{{ file.name }}</span>
            <span class="file-size">{{ formatFileSize(file.size) }}</span>
            <button
              type="button"
              class="remove-file"
              :aria-label="t('Remove file {name}', { name: file.name })"
              @click.stop="removeFile(index)"
            >
              &times;
            </button>
          </div>
          <button v-if="canAddMore" type="button" class="add-more-btn" @click.stop="triggerFileInput">
            + {{ t('Add more') }}
          </button>
        </div>
      </div>

      <p v-if="fileError" class="file-error" role="alert">{{ fileError }}</p>
    </div>

    <!-- Matrix -->
    <div
      v-else-if="question.type === 'matrix'"
      class="matrix-input"
      role="group"
      :aria-labelledby="`question-label-${question.id}`"
    >
      <table class="matrix-table">
        <thead>
          <tr>
            <th scope="col"></th>
            <th v-for="col in question.columns" :key="col.id" scope="col">{{ col.label }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in question.rows" :key="row.id">
            <th scope="row" class="row-label">{{ row.label }}</th>
            <td v-for="col in question.columns" :key="col.id" class="matrix-cell">
              <NcCheckboxRadioSwitch
                :model-value="(value || {})[row.id] === col.value"
                type="radio"
                :name="`matrix-${question.id}-${row.id}`"
                :aria-label="`${row.label}: ${col.label}`"
                @update:model-value="updateMatrix(row.id, col.value)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- External validation error (from parent) -->
    <p
      v-if="validationErrorExternal && !validationError"
      :id="`question-error-${question.id}`"
      class="validation-error"
      role="alert"
    >
      {{ validationErrorExternal }}
    </p>
  </div>
</template>

<script>
import { t } from '@/utils/l10n';
import { computed, ref } from 'vue';
import {
  NcTextField,
  NcTextArea,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
} from '@nextcloud/vue';
import StarIcon from './icons/StarIcon.vue';
import SpeakerIcon from './icons/SpeakerIcon.vue';

export default {
  name: 'QuestionRenderer',
  components: {
    NcTextField,
    NcTextArea,
    NcCheckboxRadioSwitch,
    NcDateTimePicker,
    StarIcon,
    SpeakerIcon,
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
    allQuestions: {
      type: Array,
      default: () => [],
    },
    ttsSupported: {
      type: Boolean,
      default: false,
    },
    speakingQuestionId: {
      type: String,
      default: null,
    },
    validationErrorExternal: {
      type: String,
      default: '',
    },
  },
  emits: ['update:value', 'update:files', 'speak'],
  setup(props, { emit }) {
    // File upload state
    const fileInput = ref(null);
    const isDragging = ref(false);
    const selectedFiles = ref([]);
    const fileError = ref('');

    // Validation state
    const validationError = ref('');

    // Effective error (internal or external)
    const effectiveError = computed(() =>
      validationError.value || props.validationErrorExternal
    );

    // Input ID for label association (not for group types)
    const inputId = computed(() => {
      if (['choice', 'multiple', 'scale', 'rating', 'matrix'].includes(props.question.type)) {
        return undefined;
      }
      return `input-${props.question.id}`;
    });

    // Computed aria-describedby
    const ariaDescribedBy = computed(() => {
      const ids = [];
      if (props.question.description) {
        ids.push(`question-desc-${props.question.id}`);
      }
      if (effectiveError.value) {
        ids.push(`question-error-${props.question.id}`);
      }
      return ids.length > 0 ? ids.join(' ') : undefined;
    });

    // TTS
    const isSpeaking = computed(() =>
      props.speakingQuestionId === props.question.id
    );

    const toggleTts = () => {
      emit('speak', props.question.id);
    };

    // Roving tabindex for radio-like groups
    const getRadioTabindex = (n, range, currentValue) => {
      if (currentValue !== '' && currentValue !== null && currentValue !== undefined) {
        return n === currentValue ? 0 : -1;
      }
      return n === range[0] ? 0 : -1;
    };

    const handleRadioGroupKeydown = (event, range, currentValue, onSelect) => {
      const currentIndex = range.indexOf(currentValue);
      let newIndex = -1;

      switch (event.key) {
        case 'ArrowRight':
        case 'ArrowDown':
          event.preventDefault();
          newIndex = currentIndex < range.length - 1 ? currentIndex + 1 : 0;
          break;
        case 'ArrowLeft':
        case 'ArrowUp':
          event.preventDefault();
          newIndex = currentIndex > 0 ? currentIndex - 1 : range.length - 1;
          break;
        case 'Home':
          event.preventDefault();
          newIndex = 0;
          break;
        case 'End':
          event.preventDefault();
          newIndex = range.length - 1;
          break;
        default:
          return;
      }

      if (newIndex >= 0) {
        onSelect(range[newIndex]);
        const buttons = event.currentTarget.querySelectorAll('[role="radio"]');
        if (buttons[newIndex]) {
          buttons[newIndex].focus();
        }
      }
    };

    const handleScaleKeydown = (event) => {
      handleRadioGroupKeydown(event, scaleRange.value, props.value, (n) => {
        emit('update:value', n);
      });
    };

    const handleRatingKeydown = (event) => {
      handleRadioGroupKeydown(event, ratingRange.value, props.value, (n) => {
        emit('update:value', n);
      });
    };

    const validatePattern = () => {
      validationError.value = '';
      if (!props.question.validation?.pattern || !props.value) return true;

      try {
        const regex = new RegExp(props.question.validation.pattern);
        if (!regex.test(String(props.value))) {
          validationError.value = props.question.validation.errorMessage
            || t('This field does not match the required format');
          return false;
        }
      } catch (e) {
        // Invalid regex - skip validation
      }
      return true;
    };

    const clearValidationError = () => {
      validationError.value = '';
    };
    // Helper function to format answer for display
    const formatAnswerForDisplay = (answer) => {
      if (answer === undefined || answer === null || answer === '') {
        return null; // Don't replace if no answer
      }
      if (Array.isArray(answer)) {
        // Multiple choice - join with commas
        return answer.length > 0 ? answer.join(', ') : null;
      }
      if (typeof answer === 'object') {
        // Matrix - show row:value pairs
        const entries = Object.entries(answer);
        return entries.length > 0 ? entries.map(([k, v]) => `${k}: ${v}`).join('; ') : null;
      }
      return String(answer);
    };

    // Piping support - replace {{Q1}} or {{qXXX}} with answers
    // {{Q1}}, {{Q2}} etc. = 1-based question number
    // {{qXXXX}} = question ID (e.g. q1a2b3c4)
    const applyPiping = (text) => {
      if (!text) return '';
      const matches = text.match(/\{\{(\w+)\}\}/g);
      if (matches) {
        matches.forEach(match => {
          const ref = match.replace(/\{\{|\}\}/g, '');
          let questionId = null;

          // Check if it's a numeric reference like Q1, Q2 (1-based index)
          const numMatch = ref.match(/^Q(\d+)$/i);
          if (numMatch) {
            const index = parseInt(numMatch[1], 10) - 1; // Convert to 0-based
            if (props.allQuestions && props.allQuestions[index]) {
              questionId = props.allQuestions[index].id;
            }
          } else {
            // Direct question ID reference (e.g. qXXXX)
            questionId = ref;
          }

          if (questionId) {
            const answer = props.allAnswers[questionId];
            const displayValue = formatAnswerForDisplay(answer);
            if (displayValue !== null) {
              text = text.replace(match, displayValue);
            }
          }
        });
      }
      return text;
    };

    const renderedQuestion = computed(() => applyPiping(props.question.question || ''));
    const renderedDescription = computed(() => applyPiping(props.question.description || ''));

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

    // Computed properties for file upload
    const acceptString = computed(() => {
      const types = props.question.allowedTypes || [];
      if (types.length === 0 || types.includes('*/*')) {
        return '';
      }
      return types.join(',');
    });

    const allowedTypesText = computed(() => {
      const preset = props.question.allowedTypePreset;
      const presetLabels = {
        images: t('Images'),
        documents: t('Documents'),
        pdf: t('PDF'),
        all: t('All files'),
      };
      return presetLabels[preset] || t('All files');
    });

    const canAddMore = computed(() => {
      const maxFiles = props.question.maxFiles || 1;
      return selectedFiles.value.length < maxFiles;
    });

    // File upload methods
    const triggerFileInput = () => {
      if (fileInput.value) {
        fileInput.value.click();
      }
    };

    const handleFileSelect = (event) => {
      const files = Array.from(event.target.files || []);
      addFiles(files);
      // Reset input so same file can be selected again
      if (fileInput.value) {
        fileInput.value.value = '';
      }
    };

    const handleDrop = (event) => {
      isDragging.value = false;
      const files = Array.from(event.dataTransfer?.files || []);
      addFiles(files);
    };

    const addFiles = (files) => {
      fileError.value = '';
      const maxFiles = props.question.maxFiles || 1;
      const maxSizeMB = props.question.maxFileSize || 10;
      const maxSizeBytes = maxSizeMB * 1024 * 1024;
      const allowedTypes = props.question.allowedTypes || [];

      for (const file of files) {
        // Check if we can add more
        if (selectedFiles.value.length >= maxFiles) {
          fileError.value = t('Maximum {n} files allowed', { n: maxFiles });
          break;
        }

        // Validate size
        if (file.size > maxSizeBytes) {
          fileError.value = t('File "{name}" is too large. Maximum size is {size} MB', {
            name: file.name,
            size: maxSizeMB
          });
          continue;
        }

        // Validate type
        if (!isFileTypeAllowed(file, allowedTypes)) {
          fileError.value = t('File "{name}" is not an allowed file type', { name: file.name });
          continue;
        }

        selectedFiles.value.push(file);
      }

      // Emit the files for the parent component to handle upload
      emit('update:files', selectedFiles.value);
      // Also emit filenames for backwards compatibility
      if (selectedFiles.value.length === 1) {
        emit('update:value', selectedFiles.value[0].name);
      } else {
        emit('update:value', selectedFiles.value.map(f => f.name));
      }
    };

    const isFileTypeAllowed = (file, allowedTypes) => {
      if (allowedTypes.length === 0 || allowedTypes.includes('*/*')) {
        // Block dangerous types even when "all" is allowed
        const dangerousExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phar', 'ps1', 'vbs'];
        const extension = file.name.split('.').pop()?.toLowerCase() || '';
        return !dangerousExtensions.includes(extension);
      }

      const mimeType = file.type;
      const extension = '.' + (file.name.split('.').pop()?.toLowerCase() || '');

      for (const allowed of allowedTypes) {
        // Exact MIME match
        if (mimeType === allowed) return true;
        // Wildcard MIME (e.g., image/*)
        if (allowed.endsWith('/*') && mimeType.startsWith(allowed.slice(0, -1))) return true;
        // Extension match
        if (allowed.startsWith('.') && extension === allowed.toLowerCase()) return true;
      }
      return false;
    };

    const removeFile = (index) => {
      selectedFiles.value.splice(index, 1);
      fileError.value = '';
      emit('update:files', selectedFiles.value);
      if (selectedFiles.value.length === 0) {
        emit('update:value', '');
      } else if (selectedFiles.value.length === 1) {
        emit('update:value', selectedFiles.value[0].name);
      } else {
        emit('update:value', selectedFiles.value.map(f => f.name));
      }
    };

    const formatFileSize = (bytes) => {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };

    const handleFileChange = (event) => {
      // Legacy handler - kept for backwards compatibility
      const file = event.target.files[0];
      if (file) {
        emit('update:value', file.name);
      }
    };

    // Method wrapper for template use
    const renderPiping = (text) => applyPiping(text);

    return {
      renderedQuestion,
      renderedDescription,
      renderPiping,
      scaleRange,
      ratingRange,
      toggleMultiple,
      updateMatrix,
      formatDate,
      formatDateTime,
      handleFileChange,
      // File upload
      fileInput,
      isDragging,
      selectedFiles,
      fileError,
      acceptString,
      allowedTypesText,
      canAddMore,
      triggerFileInput,
      handleFileSelect,
      handleDrop,
      removeFile,
      formatFileSize,
      // Validation
      validationError,
      effectiveError,
      validatePattern,
      clearValidationError,
      // Accessibility
      inputId,
      ariaDescribedBy,
      isSpeaking,
      toggleTts,
      getRadioTabindex,
      handleScaleKeydown,
      handleRatingKeydown,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.question-renderer {
  max-width: 100%;

  &.has-color {
    padding-left: 16px;
    border-left: 4px solid var(--question-color);
  }

  .question-label {
    display: block;
    font-size: 16px;
    font-weight: 600;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }

  .required-indicator {
    color: #e53935;
    font-size: 18px;
    font-weight: 700;
    margin-left: 2px;
    vertical-align: top;
    line-height: 1;
  }

  .required-text {
    font-size: 12px;
    font-weight: 400;
    color: var(--color-text-maxcontrast);
    margin-left: 6px;
  }

  .question-description {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }

  // NcTextArea styling
  :deep(.textarea) {
    width: 100%;
    max-width: 100%;
    min-height: 100px;
  }

  :deep(textarea) {
    width: 100%;
    max-width: 100%;
    min-height: 100px;
    resize: vertical;
  }

  // Fix NcTextField and NcDateTimePicker overflow
  :deep(.input-field),
  :deep(.input-field__main-wrapper),
  :deep(.mx-datepicker) {
    max-width: 100%;
    width: 100%;
  }
}

.question-label-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;

  .question-label {
    flex: 1;
    margin-bottom: 0;
  }
}

.tts-button {
  flex-shrink: 0;
  background: none;
  border: 1px solid var(--color-border);
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  transition: all 0.2s;
  padding: 0;

  &:hover {
    color: var(--color-primary-element);
    border-color: var(--color-primary-element);
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
  }

  &.speaking {
    color: var(--color-primary-element);
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
    animation: tts-pulse 1.5s ease-in-out infinite;
  }
}

:global {
  @keyframes tts-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
}

.textarea-wrapper {
  width: 100%;
}

.nc-textarea {
  width: 100%;
  min-height: 100px;
  padding: 12px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-large);
  background-color: var(--color-main-background);
  font-family: inherit;
  font-size: 14px;
  line-height: 1.5;
  resize: vertical;
  box-sizing: border-box;

  &:focus {
    border-color: var(--color-primary-element);
    outline: none;
  }

  &:hover:not(:focus) {
    border-color: var(--color-primary-element-light);
  }

  &.has-error {
    border-color: var(--color-error);
  }
}

.validation-error {
  margin-top: 4px;
  padding: 6px 10px;
  background: var(--color-error);
  color: white;
  border-radius: var(--border-radius);
  font-size: 13px;
}

.choice-options {
  display: flex;
  flex-direction: column;
  gap: 8px;

  :deep(.checkbox-radio-switch) {
    width: 100%;

    .checkbox-radio-switch__label {
      white-space: normal;
      word-wrap: break-word;
      overflow-wrap: break-word;
    }
  }

  :deep(.checkbox-radio-switch__content) {
    white-space: normal;
    word-wrap: break-word;
  }
}

.dropdown-select {
  display: block;
  width: 100%;
  max-width: 100%;
  height: auto;
  min-height: 48px;
  padding: 14px 12px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-large);
  background-color: var(--color-main-background);
  color: var(--color-main-text);
  font-family: inherit;
  font-size: 14px;
  cursor: pointer;
  box-sizing: border-box;

  &:focus {
    border-color: var(--color-primary-element);
    outline: none;
  }

  &:hover:not(:focus) {
    border-color: var(--color-primary-element-light);
  }

  option {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
  }
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
  flex-wrap: wrap;

  .scale-label {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
  }

  .scale-options {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
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

    &:focus-visible {
      outline: 2px solid var(--color-primary-element);
      outline-offset: 2px;
    }

    &.selected {
      background: var(--color-primary);
      border-color: var(--color-primary);
      color: white;
    }
  }
}

@media (max-width: 480px) {
  .scale-input {
    flex-direction: column;
    align-items: stretch;

    .scale-label {
      text-align: center;
    }

    .scale-option {
      width: 36px;
      height: 36px;
      font-size: 13px;
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

    &:focus-visible {
      outline: 2px solid var(--color-primary-element);
      outline-offset: 2px;
      border-radius: 4px;
    }
  }
}

.file-input {
  .file-upload-zone {
    border: 2px dashed var(--color-border-dark);
    border-radius: var(--border-radius-large);
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: var(--color-background-hover);

    &:hover {
      border-color: var(--color-primary-element);
      background: var(--color-primary-element-light);
    }

    &:focus-visible {
      outline: 2px solid var(--color-primary-element);
      outline-offset: 2px;
    }

    &.drag-over {
      border-color: var(--color-primary-element);
      background: var(--color-primary-element-light);
      border-style: solid;
    }

    &.has-files {
      padding: 16px;
      text-align: left;
    }
  }

  .upload-prompt {
    color: var(--color-text-maxcontrast);

    svg {
      margin-bottom: 12px;
      color: var(--color-primary-element);
    }

    p {
      margin: 0 0 8px;
      font-size: 15px;
      font-weight: 500;
      color: var(--color-main-text);
    }

    small {
      font-size: 13px;
    }
  }

  .uploaded-files {
    .file-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      background: var(--color-main-background);
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      margin-bottom: 8px;

      svg {
        flex-shrink: 0;
        color: var(--color-primary-element);
      }

      .file-name {
        flex: 1;
        font-size: 14px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .file-size {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        flex-shrink: 0;
      }

      .remove-file {
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        color: var(--color-text-maxcontrast);
        padding: 0 4px;
        line-height: 1;

        &:hover {
          color: var(--color-error);
        }
      }
    }

    .add-more-btn {
      background: none;
      border: 1px dashed var(--color-border);
      border-radius: var(--border-radius);
      padding: 8px 16px;
      cursor: pointer;
      color: var(--color-primary-element);
      font-size: 14px;
      width: 100%;

      &:hover {
        background: var(--color-background-hover);
        border-color: var(--color-primary-element);
      }
    }
  }

  .file-error {
    margin-top: 8px;
    padding: 8px 12px;
    background: var(--color-error);
    color: white;
    border-radius: var(--border-radius);
    font-size: 13px;
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
