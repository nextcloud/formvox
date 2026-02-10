<template>
  <div class="respond-container" :style="containerStyles">
    <!-- Skip link -->
    <a v-if="!submitted && !isLimitReached" href="#formvox-form-content" class="sr-only sr-only-focusable">
      {{ t('Skip to form questions') }}
    </a>

    <!-- Header Zone -->
    <div v-if="headerBlocks.length > 0" class="zone-header">
      <BlockRenderer
        v-for="block in headerBlocks"
        :key="block.id"
        :block="block"
        :global-styles="globalStyles"
      />
    </div>

    <!-- Response Limit Reached -->
    <div v-if="isLimitReached" class="limit-reached-zone" role="alert">
      <div class="limit-message">
        {{ form.settings?.limit_message || t('This form is no longer accepting responses.') }}
      </div>
    </div>

    <!-- Thank You Page (after submission) -->
    <div
      v-else-if="submitted"
      class="thank-you-zone"
      role="status"
      aria-live="polite"
      ref="thankYouRef"
      tabindex="-1"
    >
      <template v-if="thankYouBlocks.length > 0">
        <BlockRenderer
          v-for="block in thankYouBlocks"
          :key="block.id"
          :block="block"
          :global-styles="globalStyles"
        />
      </template>
      <template v-else>
        <CheckIcon :size="64" :fill-color="globalStyles.primaryColor || '#0082c9'" />
        <h2>{{ t('Thank you!') }}</h2>
        <p>{{ t('Your response has been recorded.') }}</p>
      </template>

      <div v-if="score" class="score-display">
        <h3>{{ t('Your score') }}</h3>
        <div class="score-value">{{ score.total }} / {{ score.max }}</div>
        <div class="score-percentage" :style="{ color: globalStyles.primaryColor }">{{ score.percentage }}%</div>
      </div>
    </div>

    <!-- Form -->
    <form v-else-if="!isLimitReached" @submit.prevent="submit" novalidate :aria-label="form.title">
      <div class="form-header">
        <h1>{{ form.title }}</h1>
        <p v-if="form.description" class="form-description">{{ form.description }}</p>
      </div>

      <div
        v-if="currentPage && pages.length > 1"
        class="page-indicator"
        role="status"
        aria-live="polite"
      >
        {{ t('Page {current} of {total}', { current: currentPageIndex + 1, total: pages.length }) }}
      </div>

      <div id="formvox-form-content" class="questions">
        <div
          v-for="question in visibleQuestions"
          :key="question.id"
          :id="`question-${question.id}`"
          class="question-container"
        >
          <QuestionRenderer
            :question="question"
            :value="answers[question.id]"
            :all-answers="answers"
            :all-questions="form.questions"
            :tts-supported="ttsIsSupported"
            :speaking-question-id="speakingQuestionId"
            :validation-error-external="validationErrors[question.id] || ''"
            @update:value="updateAnswer(question.id, $event)"
            @update:files="updatePendingFiles(question.id, $event)"
            @speak="handleSpeak"
          />
        </div>
      </div>

      <div class="form-actions">
        <NcButton
          v-if="hasPreviousPage"
          @click="previousPage"
        >
          {{ t('Previous') }}
        </NcButton>

        <NcButton
          v-if="hasNextPage"
          type="primary"
          @click="nextPage"
        >
          {{ t('Next') }}
        </NcButton>

        <NcButton
          v-else
          type="primary"
          native-type="submit"
          :disabled="submitting || isPreview"
          :style="submitButtonStyles"
        >
          {{ uploadProgress || (submitting ? t('Submitting...') : t('Submit')) }}
        </NcButton>
      </div>

      <!-- Submission status for screen readers -->
      <div class="sr-only" aria-live="polite" role="status">
        <span v-if="submitting">{{ t('Submitting your response...') }}</span>
        <span v-if="uploadProgress">{{ uploadProgress }}</span>
      </div>

      <div class="error-message-container" aria-live="assertive" role="alert">
        <div v-if="error" class="error-message">
          {{ error }}
        </div>
      </div>
    </form>

    <!-- Footer Zone -->
    <div v-if="footerBlocks.length > 0" class="zone-footer">
      <BlockRenderer
        v-for="block in footerBlocks"
        :key="block.id"
        :block="block"
        :global-styles="globalStyles"
      />
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, nextTick, onBeforeUnmount } from 'vue';
import { NcButton } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { t } from '@/utils/l10n';
import { useTts } from '../composables/useTts';
import QuestionRenderer from '../components/QuestionRenderer.vue';
import BlockRenderer from '../components/pagebuilder/BlockRenderer.vue';
import CheckIcon from '../components/icons/CheckIcon.vue';

export default {
  name: 'Respond',
  components: {
    NcButton,
    QuestionRenderer,
    BlockRenderer,
    CheckIcon,
  },
  props: {
    fileId: {
      type: Number,
      default: 0,
    },
    token: {
      type: String,
      default: '',
    },
    form: {
      type: Object,
      required: true,
    },
    branding: {
      type: Object,
      default: () => ({}),
    },
    isPreview: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['submit'],
  setup(props, { emit }) {
    const answers = reactive({});
    const pendingFiles = reactive({});  // questionId -> File[]
    const tempResponseId = ref(null);   // Shared ID for all file uploads in this response
    const submitted = ref(false);
    const submitting = ref(false);
    const uploadProgress = ref('');
    const error = ref(null);
    const score = ref(null);
    const currentPageIndex = ref(0);
    const validationErrors = reactive({});
    const thankYouRef = ref(null);

    // TTS
    const {
      isSupported: ttsIsSupported,
      speakingQuestionId,
      buildSpeechText,
      speak: ttsSpeak,
      stop: ttsStop,
    } = useTts();

    const globalStyles = computed(() => props.branding?.globalStyles || {
      primaryColor: '#0082c9',
      backgroundColor: '#ffffff',
    });

    // Check if response limit is reached
    const isLimitReached = computed(() => {
      const maxResponses = props.form.settings?.max_responses || 0;
      if (maxResponses <= 0) return false;
      const currentCount = props.form._index?.response_count || 0;
      return currentCount >= maxResponses;
    });

    // Container styles based on global styles
    const containerStyles = computed(() => {
      const bg = globalStyles.value.backgroundColor;
      if (bg && bg !== '#ffffff') {
        return { backgroundColor: bg };
      }
      return {};
    });

    const submitButtonStyles = computed(() => {
      const primary = globalStyles.value.primaryColor;
      if (primary) {
        return {
          backgroundColor: primary,
          borderColor: primary,
        };
      }
      return {};
    });

    // Initialize answers
    props.form.questions?.forEach(q => {
      if (q.type === 'multiple') {
        answers[q.id] = [];
      } else if (q.type === 'matrix') {
        answers[q.id] = {};
      } else {
        answers[q.id] = '';
      }
    });

    // Pages support
    const pages = computed(() => {
      if (props.form.pages && Array.isArray(props.form.pages) && props.form.pages.length > 0) {
        return props.form.pages;
      }
      // No pages defined - show all questions on a single page
      return [{ id: 'default', questions: props.form.questions?.map(q => q.id) || [] }];
    });

    const currentPage = computed(() => pages.value[currentPageIndex.value]);

    const hasPreviousPage = computed(() => currentPageIndex.value > 0);
    const hasNextPage = computed(() => currentPageIndex.value < pages.value.length - 1);

    // Calculate real progress based on answered questions
    const realProgress = computed(() => {
      const questions = props.form.questions || [];
      if (questions.length === 0) return 0;

      let answeredCount = 0;
      for (const question of questions) {
        const answer = answers[question.id];
        // Check if question has been answered
        if (answer !== undefined && answer !== '' && answer !== null) {
          if (Array.isArray(answer)) {
            if (answer.length > 0) answeredCount++;
          } else if (typeof answer === 'object') {
            // Matrix questions - check if at least one row is answered
            if (Object.keys(answer).length > 0) answeredCount++;
          } else {
            answeredCount++;
          }
        }
      }

      return Math.round((answeredCount / questions.length) * 100);
    });

    // Inject real progress into progress bar blocks
    const injectProgress = (blocks) => {
      if (!blocks || blocks.length === 0) return [];
      return blocks.map(block => {
        if (block.type === 'progressBar') {
          return {
            ...block,
            settings: {
              ...block.settings,
              progress: realProgress.value,
            },
          };
        }
        return block;
      });
    };

    // Layout zones from branding (with injected progress)
    const headerBlocks = computed(() => injectProgress(props.branding?.layout?.header || []));
    const footerBlocks = computed(() => injectProgress(props.branding?.layout?.footer || []));
    const thankYouBlocks = computed(() => props.branding?.layout?.thankYou || []);

    // Visible questions based on current page and showIf conditions
    const visibleQuestions = computed(() => {
      const pageQuestionIds = currentPage.value?.questions || [];
      return props.form.questions?.filter(q => {
        // Check if question is on current page
        if (!pageQuestionIds.includes(q.id)) {
          return false;
        }
        // Check showIf condition
        if (q.showIf) {
          return evaluateCondition(q.showIf, answers);
        }
        return true;
      }) || [];
    });

    const evaluateCondition = (condition, answers) => {
      // Combined conditions
      if (condition.operator === 'and' || condition.operator === 'or') {
        const results = condition.conditions.map(c => evaluateCondition(c, answers));
        return condition.operator === 'and'
          ? results.every(Boolean)
          : results.some(Boolean);
      }

      // Simple condition
      const answer = answers[condition.questionId];
      const value = condition.value;

      switch (condition.operator) {
        case 'equals':
          return answer === value;
        case 'notEquals':
          return answer !== value;
        case 'contains':
          return typeof answer === 'string' && answer.includes(value);
        case 'notContains':
          return typeof answer !== 'string' || !answer.includes(value);
        case 'isEmpty':
          return !answer || answer === '' || (Array.isArray(answer) && answer.length === 0);
        case 'isNotEmpty':
          return answer && answer !== '' && (!Array.isArray(answer) || answer.length > 0);
        case 'greaterThan':
          return Number(answer) > Number(value);
        case 'lessThan':
          return Number(answer) < Number(value);
        case 'in':
          return Array.isArray(value) && value.includes(answer);
        case 'notIn':
          return !Array.isArray(value) || !value.includes(answer);
        default:
          return true;
      }
    };

    const updateAnswer = (questionId, value) => {
      answers[questionId] = value;
      // Clear validation error when user answers
      if (validationErrors[questionId]) {
        delete validationErrors[questionId];
      }
    };

    const updatePendingFiles = (questionId, files) => {
      pendingFiles[questionId] = files;
    };

    // Piping helper for TTS
    const applyPipingForTts = (text) => {
      if (!text) return '';
      const matches = text.match(/\{\{(\w+)\}\}/g);
      if (!matches) return text;

      matches.forEach(match => {
        const ref = match.replace(/\{\{|\}\}/g, '');
        let questionId = null;
        const numMatch = ref.match(/^Q(\d+)$/i);
        if (numMatch) {
          const index = parseInt(numMatch[1], 10) - 1;
          if (props.form.questions && props.form.questions[index]) {
            questionId = props.form.questions[index].id;
          }
        } else {
          questionId = ref;
        }
        if (questionId) {
          const answer = answers[questionId];
          if (answer && answer !== '') {
            text = text.replace(match, Array.isArray(answer) ? answer.join(', ') : String(answer));
          }
        }
      });
      return text;
    };

    const handleSpeak = (questionId) => {
      const question = props.form.questions.find(q => q.id === questionId);
      if (!question) return;

      const renderedQ = applyPipingForTts(question.question);
      const renderedDesc = question.description ? applyPipingForTts(question.description) : '';
      const text = buildSpeechText(
        question,
        renderedQ,
        renderedDesc,
        (label) => applyPipingForTts(label)
      );
      ttsSpeak(questionId, text);
    };

    const focusFirstQuestion = () => {
      nextTick(() => {
        const firstQuestion = document.querySelector('.question-container');
        if (firstQuestion) {
          firstQuestion.scrollIntoView({ behavior: 'smooth', block: 'start' });
          const focusable = firstQuestion.querySelector(
            'input, textarea, select, [tabindex="0"]'
          );
          if (focusable) focusable.focus();
        }
      });
    };

    const validateCurrentPage = () => {
      // Clear previous errors
      Object.keys(validationErrors).forEach(k => delete validationErrors[k]);
      let firstErrorQuestionId = null;

      for (const question of visibleQuestions.value) {
        const answer = answers[question.id];

        // Required check
        if (question.required) {
          if (!answer || answer === '' || (Array.isArray(answer) && answer.length === 0)) {
            if (!firstErrorQuestionId) firstErrorQuestionId = question.id;
            validationErrors[question.id] = t('This question is required');
          }
        }

        // Pattern validation (only for non-empty answers)
        if (question.validation?.pattern && answer && answer !== '') {
          try {
            const regex = new RegExp(question.validation.pattern);
            if (!regex.test(String(answer))) {
              if (!firstErrorQuestionId) firstErrorQuestionId = question.id;
              validationErrors[question.id] = question.validation.errorMessage
                || t('"{question}" does not match the required format', { question: question.question });
            }
          } catch (e) {
            // Invalid regex - skip validation
          }
        }
      }

      if (firstErrorQuestionId) {
        error.value = t('Please answer all required questions');
        // Focus the first question with an error
        nextTick(() => {
          const el = document.getElementById(`question-${firstErrorQuestionId}`);
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusable = el.querySelector(
              'input, textarea, select, [tabindex="0"], button:not(.tts-button)'
            );
            if (focusable) focusable.focus();
          }
        });
        return false;
      }

      error.value = null;
      return true;
    };

    const previousPage = () => {
      if (hasPreviousPage.value) {
        ttsStop();
        currentPageIndex.value--;
        focusFirstQuestion();
      }
    };

    const nextPage = () => {
      if (validateCurrentPage() && hasNextPage.value) {
        ttsStop();
        currentPageIndex.value++;
        focusFirstQuestion();
      }
    };

    const uploadFiles = async () => {
      const fileQuestionIds = Object.keys(pendingFiles).filter(qId =>
        pendingFiles[qId] && pendingFiles[qId].length > 0
      );

      if (fileQuestionIds.length === 0) {
        return {}; // No files to upload
      }

      // Generate a temp response ID if we don't have one yet
      if (!tempResponseId.value) {
        tempResponseId.value = crypto.randomUUID ? crypto.randomUUID() :
          'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
          });
      }

      const fileAnswers = {};

      for (const questionId of fileQuestionIds) {
        const files = pendingFiles[questionId];
        const uploadedFiles = [];

        for (let i = 0; i < files.length; i++) {
          const file = files[i];
          uploadProgress.value = t('Uploading {name}...', { name: file.name });

          const formData = new FormData();
          formData.append('file', file);
          formData.append('questionId', questionId);
          formData.append('tempResponseId', tempResponseId.value);

          const uploadUrl = generateUrl('/apps/formvox/public/{fileId}/{token}/upload', {
            fileId: props.fileId,
            token: props.token
          });

          const response = await axios.post(uploadUrl, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
          });

          uploadedFiles.push(response.data);
        }

        // Store file metadata as the answer
        fileAnswers[questionId] = uploadedFiles.length === 1 ? uploadedFiles[0] : uploadedFiles;
      }

      uploadProgress.value = '';
      return fileAnswers;
    };

    const submit = async () => {
      if (!validateCurrentPage()) {
        return;
      }

      if (props.isPreview) {
        emit('submit', answers);
        return;
      }

      submitting.value = true;
      error.value = null;

      try {
        // Step 1: Upload any pending files
        const fileAnswers = await uploadFiles();

        // Step 2: Merge file metadata into answers
        const finalAnswers = { ...answers, ...fileAnswers };

        // Step 3: Submit the response
        const url = generateUrl('/apps/formvox/public/{fileId}/{token}/submit', {
          fileId: props.fileId,
          token: props.token
        });

        const response = await axios.post(url, { answers: finalAnswers });

        submitted.value = true;
        ttsStop();

        if (response.data.score) {
          score.value = response.data.score;
        }

        // Focus the thank you zone
        nextTick(() => {
          if (thankYouRef.value) {
            thankYouRef.value.focus();
          }
        });
      } catch (err) {
        error.value = err.response?.data?.error || t('Failed to submit response');
      } finally {
        submitting.value = false;
        uploadProgress.value = '';
      }
    };

    // Clean up TTS on unmount
    onBeforeUnmount(() => {
      ttsStop();
    });

    return {
      answers,
      submitted,
      submitting,
      uploadProgress,
      error,
      score,
      currentPageIndex,
      pages,
      currentPage,
      hasPreviousPage,
      hasNextPage,
      visibleQuestions,
      headerBlocks,
      footerBlocks,
      thankYouBlocks,
      globalStyles,
      containerStyles,
      submitButtonStyles,
      isLimitReached,
      updateAnswer,
      updatePendingFiles,
      previousPage,
      nextPage,
      submit,
      // Accessibility
      validationErrors,
      thankYouRef,
      ttsIsSupported,
      speakingQuestionId,
      handleSpeak,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.respond-container {
  max-width: 700px;
  margin: 0 auto;
  padding: 20px;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.sr-only-focusable:focus {
  position: static;
  width: auto;
  height: auto;
  overflow: visible;
  clip: auto;
  white-space: normal;
  padding: 8px 16px;
  background: var(--color-primary-element);
  color: white;
  border-radius: var(--border-radius);
  display: block;
  margin-bottom: 16px;
  text-decoration: none;
}

.zone-header {
  margin-bottom: 24px;
}

.zone-footer {
  margin-top: 40px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.limit-reached-zone {
  text-align: center;
  padding: 60px 20px;

  .limit-message {
    font-size: 18px;
    color: var(--color-text-maxcontrast);
    max-width: 500px;
    margin: 0 auto;
    line-height: 1.5;
  }
}

.thank-you-zone {
  text-align: center;
  padding: 40px 20px;

  &:focus {
    outline: none;
  }

  h2 {
    margin: 20px 0 10px;
  }

  p {
    color: var(--color-text-maxcontrast);
    margin-bottom: 20px;
  }
}

.form-header {
  margin-bottom: 30px;

  h1 {
    margin: 0 0 10px;
    font-size: 28px;
  }

  .form-description {
    color: var(--color-text-maxcontrast);
    font-size: 16px;
    margin: 0;
  }
}

.page-indicator {
  text-align: center;
  color: var(--color-text-maxcontrast);
  margin-bottom: 20px;
  font-size: 14px;
}

.questions {
  margin-bottom: 30px;
}

.question-container {
  margin-bottom: 24px;
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.error-message-container {
  min-height: 0;
}

.error-message {
  margin-top: 20px;
  padding: 12px 16px;
  background: var(--color-error);
  color: white;
  border-radius: var(--border-radius);
}

.score-display {
  margin: 30px 0;
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  h3 {
    margin: 0 0 10px;
    font-size: 18px;
  }

  .score-value {
    font-size: 36px;
    font-weight: bold;
  }

  .score-percentage {
    font-size: 24px;
    color: var(--color-primary);
  }
}
</style>
