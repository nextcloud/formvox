<template>
  <div class="respond-container">
    <div v-if="submitted" class="success-message">
      <CheckIcon :size="64" />
      <h2>{{ t('Thank you!') }}</h2>
      <p>{{ t('Your response has been recorded.') }}</p>

      <div v-if="score" class="score-display">
        <h3>{{ t('Your score') }}</h3>
        <div class="score-value">{{ score.total }} / {{ score.max }}</div>
        <div class="score-percentage">{{ score.percentage }}%</div>
      </div>

      <NcButton v-if="showResultsLink" @click="viewResults">
        {{ t('View results') }}
      </NcButton>
    </div>

    <form v-else @submit.prevent="submit">
      <div class="form-header">
        <h1>{{ form.title }}</h1>
        <p v-if="form.description" class="form-description">{{ form.description }}</p>
      </div>

      <div v-if="currentPage" class="page-indicator">
        {{ t('Page {current} of {total}', { current: currentPageIndex + 1, total: pages.length }) }}
      </div>

      <div class="questions">
        <div
          v-for="question in visibleQuestions"
          :key="question.id"
          class="question-container"
        >
          <QuestionRenderer
            :question="question"
            :value="answers[question.id]"
            :all-answers="answers"
            @update:value="updateAnswer(question.id, $event)"
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
        >
          {{ submitting ? t('Submitting...') : t('Submit') }}
        </NcButton>
      </div>

      <div v-if="error" class="error-message">
        {{ error }}
      </div>
    </form>
  </div>
</template>

<script>
import { ref, reactive, computed } from 'vue';
import { NcButton } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { t } from '@/utils/l10n';
import QuestionRenderer from '../components/QuestionRenderer.vue';
import CheckIcon from '../components/icons/CheckIcon.vue';

export default {
  name: 'Respond',
  components: {
    NcButton,
    QuestionRenderer,
    CheckIcon,
  },
  props: {
    fileId: {
      type: Number,
      default: null,
    },
    token: {
      type: String,
      default: null,
    },
    form: {
      type: Object,
      required: true,
    },
    isPublic: {
      type: Boolean,
      default: false,
    },
    isPreview: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['submit'],
  setup(props, { emit }) {
    const answers = reactive({});
    const submitted = ref(false);
    const submitting = ref(false);
    const error = ref(null);
    const score = ref(null);
    const showResultsLink = ref(false);
    const currentPageIndex = ref(0);

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
    };

    const validateCurrentPage = () => {
      for (const question of visibleQuestions.value) {
        if (question.required) {
          const answer = answers[question.id];
          if (!answer || answer === '' || (Array.isArray(answer) && answer.length === 0)) {
            error.value = t('Please answer all required questions');
            return false;
          }
        }
      }
      error.value = null;
      return true;
    };

    const previousPage = () => {
      if (hasPreviousPage.value) {
        currentPageIndex.value--;
      }
    };

    const nextPage = () => {
      if (validateCurrentPage() && hasNextPage.value) {
        currentPageIndex.value++;
      }
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
        let url;
        if (props.isPublic && props.token) {
          url = generateUrl('/apps/formvox/public/{fileId}/{token}/submit', { fileId: props.fileId, token: props.token });
        } else {
          url = generateUrl('/apps/formvox/api/form/{fileId}/respond', { fileId: props.fileId });
        }

        const response = await axios.post(url, { answers });

        submitted.value = true;

        if (response.data.score) {
          score.value = response.data.score;
        }

        if (response.data.showResults) {
          showResultsLink.value = true;
        }
      } catch (err) {
        error.value = err.response?.data?.error || t('Failed to submit response');
      } finally {
        submitting.value = false;
      }
    };

    const viewResults = () => {
      if (props.isPublic && props.token) {
        window.location.href = generateUrl('/apps/formvox/public/{fileId}/{token}/results', { fileId: props.fileId, token: props.token });
      } else {
        window.location.href = generateUrl('/apps/formvox/results/{fileId}', { fileId: props.fileId });
      }
    };

    return {
      answers,
      submitted,
      submitting,
      error,
      score,
      showResultsLink,
      currentPageIndex,
      pages,
      currentPage,
      hasPreviousPage,
      hasNextPage,
      visibleQuestions,
      updateAnswer,
      previousPage,
      nextPage,
      submit,
      viewResults,
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

.error-message {
  margin-top: 20px;
  padding: 12px 16px;
  background: var(--color-error);
  color: white;
  border-radius: var(--border-radius);
}

.success-message {
  text-align: center;
  padding: 60px 20px;

  h2 {
    margin: 20px 0 10px;
  }

  p {
    color: var(--color-text-maxcontrast);
    margin-bottom: 20px;
  }
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
