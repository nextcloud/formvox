<template>
  <NcContent app-name="formvox">
    <NcAppContent>
      <div class="results-container">
        <div class="results-header">
          <h1>{{ form.title }}</h1>
          <p class="response-count">
            {{ t('{count} responses', { count: summary.responseCount }) }}
          </p>

          <div class="header-actions">
            <NcButton @click="goToEditor">
              <template #icon>
                <EditIcon :size="20" />
              </template>
              {{ t('Edit form') }}
            </NcButton>

            <NcActions>
              <NcActionButton @click="exportCsv">
                {{ t('Export CSV') }}
              </NcActionButton>
              <NcActionButton @click="exportJson">
                {{ t('Export JSON') }}
              </NcActionButton>
            </NcActions>
          </div>
        </div>

        <div class="view-toggle">
          <NcButton
            :type="view === 'summary' ? 'primary' : 'secondary'"
            @click="view = 'summary'"
          >
            {{ t('Summary') }}
          </NcButton>
          <NcButton
            :type="view === 'responses' ? 'primary' : 'secondary'"
            @click="view = 'responses'"
          >
            {{ t('Individual responses') }}
          </NcButton>
        </div>

        <div v-if="loading" class="loading">
          <NcLoadingIcon :size="64" />
        </div>

        <div v-else-if="view === 'summary'" class="summary-view">
          <div
            v-for="question in summary.questions"
            :key="question.id"
            class="question-summary"
          >
            <h3>{{ question.question }}</h3>

            <div v-if="isChoiceType(question.type)" class="chart-container">
              <div
                v-for="(count, answer) in question.answerCounts"
                :key="answer"
                class="bar-item"
              >
                <div class="bar-label">{{ answer }}</div>
                <div class="bar-wrapper">
                  <div
                    class="bar"
                    :style="{ width: getBarWidth(count, summary.responseCount) }"
                  ></div>
                </div>
                <div class="bar-count">{{ count }} ({{ getPercentage(count, summary.responseCount) }}%)</div>
              </div>
            </div>

            <div v-else-if="isNumericType(question.type)" class="numeric-stats">
              <div class="stat">
                <span class="stat-label">{{ t('Average') }}</span>
                <span class="stat-value">{{ question.average }}</span>
              </div>
              <div class="stat">
                <span class="stat-label">{{ t('Min') }}</span>
                <span class="stat-value">{{ question.min }}</span>
              </div>
              <div class="stat">
                <span class="stat-label">{{ t('Max') }}</span>
                <span class="stat-value">{{ question.max }}</span>
              </div>
            </div>

            <div v-else class="text-responses">
              <p class="text-count">
                {{ Object.keys(question.answerCounts).length }} {{ t('unique answers') }}
              </p>
            </div>
          </div>
        </div>

        <div v-else class="responses-view">
          <table class="responses-table">
            <thead>
              <tr>
                <th>{{ t('Date') }}</th>
                <th>{{ t('Respondent') }}</th>
                <th v-for="question in form.questions" :key="question.id">
                  {{ truncate(question.question, 30) }}
                </th>
                <th v-if="permissions.deleteResponses"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="response in responses" :key="response.id">
                <td>{{ formatDate(response.submitted_at) }}</td>
                <td>
                  <span v-if="response.respondent.type === 'user'">
                    {{ response.respondent.display_name }}
                  </span>
                  <span v-else class="anonymous">{{ t('Anonymous') }}</span>
                </td>
                <td v-for="question in form.questions" :key="question.id">
                  {{ formatAnswer(response.answers[question.id]) }}
                </td>
                <td v-if="permissions.deleteResponses">
                  <NcButton
                    type="error"
                    @click="deleteResponse(response.id)"
                  >
                    <template #icon>
                      <DeleteIcon :size="20" />
                    </template>
                  </NcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script>
import { ref, onMounted } from 'vue';
import {
  NcContent,
  NcAppContent,
  NcButton,
  NcActions,
  NcActionButton,
  NcLoadingIcon,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import { t } from '@/utils/l10n';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import EditIcon from '../components/icons/EditIcon.vue';
import DeleteIcon from '../components/icons/DeleteIcon.vue';

export default {
  name: 'Results',
  components: {
    NcContent,
    NcAppContent,
    NcButton,
    NcActions,
    NcActionButton,
    NcLoadingIcon,
    EditIcon,
    DeleteIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    form: {
      type: Object,
      required: true,
    },
    role: {
      type: String,
      required: true,
    },
    permissions: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    const loading = ref(true);
    const view = ref('summary');
    const summary = ref({ responseCount: 0, questions: [] });
    const responses = ref([]);

    const loadData = async () => {
      loading.value = true;
      try {
        const response = await axios.get(
          generateUrl('/apps/formvox/api/form/{fileId}/responses', { fileId: props.fileId })
        );
        summary.value = response.data.summary;
        responses.value = response.data.responses;
      } catch (error) {
        showError(t('Failed to load responses'));
        console.error(error);
      } finally {
        loading.value = false;
      }
    };

    const isChoiceType = (type) => {
      return ['choice', 'multiple', 'dropdown'].includes(type);
    };

    const isNumericType = (type) => {
      return ['number', 'scale', 'rating'].includes(type);
    };

    const getBarWidth = (count, total) => {
      if (total === 0) return '0%';
      return `${(count / total) * 100}%`;
    };

    const getPercentage = (count, total) => {
      if (total === 0) return 0;
      return Math.round((count / total) * 100);
    };

    const formatDate = (dateString) => {
      const date = new Date(dateString);
      return date.toLocaleString();
    };

    const formatAnswer = (answer) => {
      if (Array.isArray(answer)) {
        return answer.join(', ');
      }
      if (typeof answer === 'object' && answer !== null) {
        return JSON.stringify(answer);
      }
      return answer || '-';
    };

    const truncate = (text, length) => {
      if (!text) return '';
      if (text.length <= length) return text;
      return text.substring(0, length) + '...';
    };

    const goToEditor = () => {
      window.location.href = generateUrl('/apps/formvox/edit/{fileId}', { fileId: props.fileId });
    };

    const exportCsv = () => {
      window.location.href = generateUrl('/apps/formvox/api/form/{fileId}/export/csv', { fileId: props.fileId });
    };

    const exportJson = () => {
      window.location.href = generateUrl('/apps/formvox/api/form/{fileId}/export/json', { fileId: props.fileId });
    };

    const deleteResponse = async (responseId) => {
      if (!confirm(t('Are you sure you want to delete this response?'))) {
        return;
      }

      try {
        await axios.delete(
          generateUrl('/apps/formvox/api/form/{fileId}/responses/{responseId}', {
            fileId: props.fileId,
            responseId,
          })
        );
        responses.value = responses.value.filter(r => r.id !== responseId);
        summary.value.responseCount--;
        showSuccess(t('Response deleted'));
      } catch (error) {
        showError(t('Failed to delete response'));
        console.error(error);
      }
    };

    onMounted(() => {
      loadData();
    });

    return {
      loading,
      view,
      summary,
      responses,
      isChoiceType,
      isNumericType,
      getBarWidth,
      getPercentage,
      formatDate,
      formatAnswer,
      truncate,
      goToEditor,
      exportCsv,
      exportJson,
      deleteResponse,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.results-container {
  max-width: 1000px;
  margin: 0 auto;
  padding: 20px;
}

.results-header {
  margin-bottom: 30px;

  h1 {
    margin: 0 0 5px;
  }

  .response-count {
    color: var(--color-text-maxcontrast);
    margin: 0 0 20px;
  }

  .header-actions {
    display: flex;
    gap: 10px;
  }
}

.view-toggle {
  display: flex;
  gap: 10px;
  margin-bottom: 30px;
}

.loading {
  display: flex;
  justify-content: center;
  padding: 60px;
}

.question-summary {
  margin-bottom: 30px;
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  h3 {
    margin: 0 0 20px;
    font-size: 18px;
  }
}

.chart-container {
  .bar-item {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    gap: 10px;
  }

  .bar-label {
    width: 150px;
    text-align: right;
    font-size: 14px;
  }

  .bar-wrapper {
    flex: 1;
    height: 24px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    overflow: hidden;
  }

  .bar {
    height: 100%;
    background: var(--color-primary);
    border-radius: var(--border-radius);
    transition: width 0.3s ease;
  }

  .bar-count {
    width: 80px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
  }
}

.numeric-stats {
  display: flex;
  gap: 30px;

  .stat {
    text-align: center;

    .stat-label {
      display: block;
      font-size: 14px;
      color: var(--color-text-maxcontrast);
      margin-bottom: 5px;
    }

    .stat-value {
      display: block;
      font-size: 24px;
      font-weight: bold;
    }
  }
}

.text-responses {
  .text-count {
    color: var(--color-text-maxcontrast);
    margin: 0;
  }
}

.responses-table {
  width: 100%;
  border-collapse: collapse;

  th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
  }

  th {
    font-weight: bold;
    background: var(--color-background-hover);
  }

  .anonymous {
    color: var(--color-text-maxcontrast);
    font-style: italic;
  }
}
</style>
