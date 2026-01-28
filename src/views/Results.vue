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

            <div v-if="isChoiceType(question.type)" class="chart-section">
              <div class="chart-toggle">
                <NcButton
                  :type="getChartType(question.id) === 'bar' ? 'primary' : 'tertiary'"
                  @click="setChartType(question.id, 'bar')"
                >
                  {{ t('Bar') }}
                </NcButton>
                <NcButton
                  :type="getChartType(question.id) === 'pie' ? 'primary' : 'tertiary'"
                  @click="setChartType(question.id, 'pie')"
                >
                  {{ t('Pie') }}
                </NcButton>
                <NcButton
                  :type="getChartType(question.id) === 'doughnut' ? 'primary' : 'tertiary'"
                  @click="setChartType(question.id, 'doughnut')"
                >
                  {{ t('Doughnut') }}
                </NcButton>
              </div>

              <div class="chart-display">
                <BarChart
                  v-if="getChartType(question.id) === 'bar'"
                  :data="question.answerCounts"
                  :horizontal="true"
                />
                <PieChart
                  v-else-if="getChartType(question.id) === 'pie'"
                  :data="question.answerCounts"
                />
                <DoughnutChart
                  v-else-if="getChartType(question.id) === 'doughnut'"
                  :data="question.answerCounts"
                />
              </div>

              <div class="chart-legend">
                <div
                  v-for="(count, answer) in question.answerCounts"
                  :key="answer"
                  class="legend-item"
                >
                  <span class="legend-label">{{ answer }}</span>
                  <span class="legend-value">{{ count }} ({{ getPercentage(count, summary.responseCount) }}%)</span>
                </div>
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

            <div v-else-if="isMatrixType(question.type)" class="matrix-summary">
              <table class="matrix-results-table">
                <thead>
                  <tr>
                    <th></th>
                    <th
                      v-for="col in getMatrixColumns(question.id)"
                      :key="col.id"
                    >
                      {{ col.label }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="row in getMatrixRows(question.id)"
                    :key="row.id"
                  >
                    <td class="row-label">{{ row.label }}</td>
                    <td
                      v-for="col in getMatrixColumns(question.id)"
                      :key="col.id"
                      class="matrix-cell"
                    >
                      <span class="cell-count">{{ getMatrixCount(question.answerCounts, row.id, col.value) }}</span>
                      <span class="cell-percent">({{ getMatrixPercentage(question.answerCounts, row.id, col.value) }}%)</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else-if="isFileType(question.type)" class="file-responses">
              <div class="file-header">
                <p class="file-count">
                  {{ question.answerCounts['[file]'] || 0 }} {{ t('files uploaded') }}
                </p>
                <NcButton
                  v-if="(question.answerCounts['[file]'] || 0) > 0"
                  type="secondary"
                  @click="downloadAllUploads"
                >
                  <template #icon>
                    <DownloadIcon :size="20" />
                  </template>
                  {{ t('Download all') }}
                </NcButton>
              </div>
              <div class="file-list">
                <div
                  v-for="file in getFilesForQuestion(question.id)"
                  :key="file.responseId + file.filename"
                  class="file-item"
                >
                  <a
                    :href="getFileDownloadUrl(file.responseId, file.filename)"
                    target="_blank"
                    class="file-link"
                  >
                    <FileIcon :size="16" />
                    {{ file.originalName || file.filename }}
                  </a>
                </div>
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
          <div class="pagination-controls">
            <div class="pagination-info">
              {{ t('Showing {start}-{end} of {total}', {
                start: (currentPage - 1) * pageSize + 1,
                end: Math.min(currentPage * pageSize, responses.length),
                total: responses.length
              }) }}
            </div>
            <div class="pagination-buttons">
              <NcButton
                type="tertiary"
                :disabled="currentPage === 1"
                @click="currentPage = 1"
              >
                &laquo;
              </NcButton>
              <NcButton
                type="tertiary"
                :disabled="currentPage === 1"
                @click="currentPage--"
              >
                &lsaquo;
              </NcButton>
              <span class="page-indicator">{{ currentPage }} / {{ totalPages }}</span>
              <NcButton
                type="tertiary"
                :disabled="currentPage === totalPages"
                @click="currentPage++"
              >
                &rsaquo;
              </NcButton>
              <NcButton
                type="tertiary"
                :disabled="currentPage === totalPages"
                @click="currentPage = totalPages"
              >
                &raquo;
              </NcButton>
            </div>
            <div class="page-size-selector">
              <label>{{ t('Per page:') }}</label>
              <select v-model="pageSize" @change="currentPage = 1">
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
                <option :value="250">250</option>
              </select>
            </div>
          </div>

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
              <tr v-for="response in paginatedResponses" :key="response.id">
                <td>{{ formatDate(response.submitted_at) }}</td>
                <td>
                  <span v-if="response.respondent.type === 'user'">
                    {{ response.respondent.display_name }}
                  </span>
                  <span v-else class="anonymous">{{ t('Anonymous') }}</span>
                </td>
                <td v-for="question in form.questions" :key="question.id">
                  <template v-if="isFileAnswer(response.answers[question.id])">
                    <div class="file-answer">
                      <a
                        v-for="file in normalizeFileAnswer(response.answers[question.id])"
                        :key="file.filename"
                        :href="getFileDownloadUrl(file.responseId, file.filename)"
                        target="_blank"
                        class="file-link"
                      >
                        <FileIcon :size="16" />
                        {{ file.originalName || file.filename }}
                      </a>
                    </div>
                  </template>
                  <template v-else>
                    {{ formatAnswer(response.answers[question.id], question) }}
                  </template>
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

          <div v-if="totalPages > 1" class="pagination-controls bottom">
            <div class="pagination-buttons">
              <NcButton
                type="tertiary"
                :disabled="currentPage === 1"
                @click="currentPage = 1"
              >
                &laquo;
              </NcButton>
              <NcButton
                type="tertiary"
                :disabled="currentPage === 1"
                @click="currentPage--"
              >
                &lsaquo;
              </NcButton>
              <span class="page-indicator">{{ currentPage }} / {{ totalPages }}</span>
              <NcButton
                type="tertiary"
                :disabled="currentPage === totalPages"
                @click="currentPage++"
              >
                &rsaquo;
              </NcButton>
              <NcButton
                type="tertiary"
                :disabled="currentPage === totalPages"
                @click="currentPage = totalPages"
              >
                &raquo;
              </NcButton>
            </div>
          </div>
        </div>
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script>
import { ref, computed, onMounted } from 'vue';
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
import FileIcon from '../components/icons/FileIcon.vue';
import DownloadIcon from '../components/icons/DownloadIcon.vue';
import PieChart from '../components/charts/PieChart.vue';
import BarChart from '../components/charts/BarChart.vue';
import DoughnutChart from '../components/charts/DoughnutChart.vue';

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
    FileIcon,
    DownloadIcon,
    PieChart,
    BarChart,
    DoughnutChart,
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
    const chartTypes = ref({}); // Store chart type per question

    // Pagination
    const currentPage = ref(1);
    const pageSize = ref(50);

    const totalPages = computed(() => {
      return Math.max(1, Math.ceil(responses.value.length / pageSize.value));
    });

    const paginatedResponses = computed(() => {
      const start = (currentPage.value - 1) * pageSize.value;
      const end = start + pageSize.value;
      return responses.value.slice(start, end);
    });

    const getChartType = (questionId) => {
      return chartTypes.value[questionId] || 'bar';
    };

    const setChartType = (questionId, type) => {
      chartTypes.value[questionId] = type;
    };

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

    const isMatrixType = (type) => {
      return type === 'matrix';
    };

    const isFileType = (type) => {
      return type === 'file';
    };

    // Get all files for a specific question from all responses
    const getFilesForQuestion = (questionId) => {
      const files = [];
      for (const response of responses.value) {
        const answer = response.answers[questionId];
        if (answer) {
          const normalized = normalizeFileAnswer(answer);
          files.push(...normalized);
        }
      }
      return files;
    };

    // Check if answer is a file upload (has fileId and filename properties)
    const isFileAnswer = (answer) => {
      if (!answer) return false;
      if (Array.isArray(answer)) {
        return answer.length > 0 && answer[0]?.filename && answer[0]?.responseId;
      }
      return answer?.filename && answer?.responseId;
    };

    // Normalize file answer to always be an array
    const normalizeFileAnswer = (answer) => {
      if (!answer) return [];
      if (Array.isArray(answer)) return answer;
      return [answer];
    };

    // Generate download URL for uploaded file
    const getFileDownloadUrl = (responseId, filename) => {
      return generateUrl('/apps/formvox/api/form/{fileId}/uploads/{responseId}/{filename}', {
        fileId: props.fileId,
        responseId,
        filename,
      });
    };

    // Get matrix rows from form question definition
    const getMatrixRows = (questionId) => {
      const question = props.form.questions.find(q => q.id === questionId);
      return question?.rows || [];
    };

    // Get matrix columns from form question definition
    const getMatrixColumns = (questionId) => {
      const question = props.form.questions.find(q => q.id === questionId);
      return question?.columns || [];
    };

    // Get count for a specific row/column combination from answerCounts
    // answerCounts format is "row1:1": 123, "row1:2": 456, etc.
    const getMatrixCount = (answerCounts, rowId, colValue) => {
      const key = `${rowId}:${colValue}`;
      return answerCounts[key] || 0;
    };

    // Get percentage for a specific row/column combination
    const getMatrixPercentage = (answerCounts, rowId, colValue) => {
      const count = getMatrixCount(answerCounts, rowId, colValue);
      if (summary.value.responseCount === 0) return 0;
      return Math.round((count / summary.value.responseCount) * 100);
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

    const formatAnswer = (answer, question = null) => {
      if (Array.isArray(answer)) {
        // For multiple choice, try to show labels instead of values
        if (question && question.options) {
          const labels = answer.map(val => {
            const option = question.options.find(o => o.value === val);
            return option ? option.label : val;
          });
          return labels.join(', ');
        }
        return answer.join(', ');
      }
      if (typeof answer === 'object' && answer !== null) {
        // Matrix type - show row labels with column labels
        if (question && question.type === 'matrix' && question.rows && question.columns) {
          const parts = [];
          for (const [rowId, colValue] of Object.entries(answer)) {
            const row = question.rows.find(r => r.id === rowId);
            const col = question.columns.find(c => c.value === colValue);
            const rowLabel = row ? row.label : rowId;
            const colLabel = col ? col.label : colValue;
            parts.push(`${rowLabel}: ${colLabel}`);
          }
          return parts.join(', ');
        }
        return JSON.stringify(answer);
      }
      // For single choice, try to show label instead of value
      if (question && question.options && answer) {
        const option = question.options.find(o => o.value === answer);
        if (option) {
          return option.label;
        }
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

    const downloadAllUploads = () => {
      window.location.href = generateUrl('/apps/formvox/api/form/{fileId}/uploads', { fileId: props.fileId });
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
      chartTypes,
      // Pagination
      currentPage,
      pageSize,
      totalPages,
      paginatedResponses,
      // Methods
      getChartType,
      setChartType,
      isChoiceType,
      isNumericType,
      isMatrixType,
      isFileType,
      isFileAnswer,
      normalizeFileAnswer,
      getFileDownloadUrl,
      getFilesForQuestion,
      getMatrixRows,
      getMatrixColumns,
      getMatrixCount,
      getMatrixPercentage,
      getBarWidth,
      getPercentage,
      formatDate,
      formatAnswer,
      truncate,
      goToEditor,
      exportCsv,
      exportJson,
      downloadAllUploads,
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

.chart-section {
  .chart-toggle {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
  }

  .chart-display {
    margin-bottom: 20px;
    min-height: 200px;
  }

  .chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 15px;
    border-top: 1px solid var(--color-border);

    .legend-item {
      display: flex;
      gap: 8px;
      font-size: 14px;
    }

    .legend-label {
      font-weight: 500;
    }

    .legend-value {
      color: var(--color-text-maxcontrast);
    }
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

.matrix-summary {
  overflow-x: auto;

  .matrix-results-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;

    th, td {
      padding: 10px 15px;
      text-align: center;
      border: 1px solid var(--color-border);
    }

    th {
      background: var(--color-background-dark);
      font-weight: 600;
    }

    .row-label {
      text-align: left;
      font-weight: 500;
      background: var(--color-background-dark);
    }

    .matrix-cell {
      .cell-count {
        font-weight: 600;
        display: block;
      }

      .cell-percent {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
      }
    }
  }
}

.text-responses {
  .text-count {
    color: var(--color-text-maxcontrast);
    margin: 0;
  }
}

.file-responses {
  .file-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 15px;
    gap: 15px;
  }

  .file-count {
    color: var(--color-text-maxcontrast);
    margin: 0;
  }

  .file-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;

    .file-item {
      .file-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: var(--color-main-background);
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius);
        color: var(--color-primary-element);
        text-decoration: none;
        font-size: 13px;
        transition: all 0.15s ease;

        &:hover {
          background: var(--color-primary-element-light);
          border-color: var(--color-primary-element);
        }

        svg {
          flex-shrink: 0;
        }
      }
    }
  }
}

.pagination-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px 0;
  gap: 20px;
  flex-wrap: wrap;

  &.bottom {
    justify-content: center;
    border-top: 1px solid var(--color-border);
    margin-top: 20px;
  }

  .pagination-info {
    color: var(--color-text-maxcontrast);
    font-size: 14px;
  }

  .pagination-buttons {
    display: flex;
    align-items: center;
    gap: 5px;

    .page-indicator {
      padding: 0 15px;
      font-weight: 500;
    }
  }

  .page-size-selector {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;

    label {
      color: var(--color-text-maxcontrast);
    }

    select {
      padding: 6px 10px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      background: var(--color-main-background);
      font-size: 14px;
    }
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

  .file-answer {
    display: flex;
    flex-direction: column;
    gap: 4px;

    .file-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--color-primary-element);
      text-decoration: none;
      font-size: 13px;

      &:hover {
        text-decoration: underline;
      }

      svg {
        flex-shrink: 0;
      }
    }
  }
}
</style>
