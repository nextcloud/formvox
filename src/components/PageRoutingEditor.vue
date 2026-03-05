<template>
  <NcModal @close="$emit('close')">
    <div class="routing-editor">
      <h2>{{ t('Page routing') }}</h2>
      <p class="routing-description">
        {{ t('Define rules to skip to a specific page based on answers. If no rule matches, the next page is shown.') }}
      </p>

      <div v-if="localRules.length === 0" class="no-rules">
        <p>{{ t('No routing rules yet. Add one to get started.') }}</p>
      </div>

      <div v-for="(rule, index) in localRules" :key="rule.id" class="routing-rule">
        <div class="rule-header">
          <span class="rule-number">{{ index + 1 }}</span>
          <button type="button" class="remove-rule" @click="removeRule(index)" :title="t('Remove rule')">
            &times;
          </button>
        </div>

        <div class="rule-fields">
          <div class="rule-field">
            <label>{{ t('If question') }}</label>
            <select v-model="rule.questionId" @change="onRuleChange">
              <option value="">{{ t('Select question...') }}</option>
              <option v-for="q in availableQuestions" :key="q.id" :value="q.id">
                {{ q.question || t('(untitled)') }}
              </option>
            </select>
          </div>

          <div class="rule-field">
            <label>{{ t('Operator') }}</label>
            <select v-model="rule.operator" @change="onRuleChange">
              <option value="equals">{{ t('equals') }}</option>
              <option value="notEquals">{{ t('not equals') }}</option>
              <option value="contains">{{ t('contains') }}</option>
              <option value="isEmpty">{{ t('is empty') }}</option>
              <option value="isNotEmpty">{{ t('is not empty') }}</option>
              <option value="greaterThan">{{ t('greater than') }}</option>
              <option value="lessThan">{{ t('less than') }}</option>
            </select>
          </div>

          <div v-if="!['isEmpty', 'isNotEmpty'].includes(rule.operator)" class="rule-field">
            <label>{{ t('Value') }}</label>
            <template v-if="getQuestionOptions(rule.questionId).length > 0">
              <select v-model="rule.value" @change="onRuleChange">
                <option value="">{{ t('Select value...') }}</option>
                <option v-for="opt in getQuestionOptions(rule.questionId)" :key="opt" :value="opt">
                  {{ opt }}
                </option>
              </select>
            </template>
            <input v-else type="text" v-model="rule.value" :placeholder="t('Enter value...')" @input="onRuleChange">
          </div>

          <div class="rule-field">
            <label>{{ t('Go to page') }}</label>
            <select v-model="rule.targetPageId" @change="onRuleChange">
              <option value="">{{ t('Select page...') }}</option>
              <option v-for="(page, pIndex) in otherPages" :key="page.id" :value="page.id">
                {{ page.title || t('Page {n}', { n: getPageDisplayIndex(page.id) }) }}
              </option>
            </select>
          </div>
        </div>
      </div>

      <NcButton type="secondary" @click="addRule">
        <template #icon>
          <PlusIcon :size="20" />
        </template>
        {{ t('Add rule') }}
      </NcButton>

      <div class="routing-actions">
        <NcButton @click="$emit('close')">
          {{ t('Done') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref, computed } from 'vue';
import { NcModal, NcButton } from '@nextcloud/vue';
import { t } from '@/utils/l10n';
import { v4 as uuidv4 } from 'uuid';
import PlusIcon from './icons/PlusIcon.vue';

export default {
  name: 'PageRoutingEditor',
  components: {
    NcModal,
    NcButton,
    PlusIcon,
  },
  props: {
    page: {
      type: Object,
      required: true,
    },
    pages: {
      type: Array,
      required: true,
    },
    questions: {
      type: Array,
      required: true,
    },
  },
  emits: ['close', 'update:routing'],
  setup(props, { emit }) {
    const localRules = ref(
      (props.page.routing || []).map(r => ({ ...r }))
    );

    const availableQuestions = computed(() => {
      const pageQuestionIds = props.page.questions || [];
      return props.questions.filter(q => pageQuestionIds.includes(q.id));
    });

    const otherPages = computed(() => {
      return props.pages.filter(p => p.id !== props.page.id);
    });

    const getPageDisplayIndex = (pageId) => {
      return props.pages.findIndex(p => p.id === pageId) + 1;
    };

    const getQuestionOptions = (questionId) => {
      const question = props.questions.find(q => q.id === questionId);
      if (!question) return [];
      if (['choice', 'multiple', 'dropdown'].includes(question.type) && question.options) {
        return question.options.map(o => o.label).filter(Boolean);
      }
      return [];
    };

    const addRule = () => {
      localRules.value.push({
        id: `r${uuidv4().split('-')[0]}`,
        questionId: '',
        operator: 'equals',
        value: '',
        targetPageId: '',
      });
    };

    const removeRule = (index) => {
      localRules.value.splice(index, 1);
      onRuleChange();
    };

    const onRuleChange = () => {
      emit('update:routing', localRules.value.filter(r => r.questionId && r.targetPageId));
    };

    return {
      localRules,
      availableQuestions,
      otherPages,
      getPageDisplayIndex,
      getQuestionOptions,
      addRule,
      removeRule,
      onRuleChange,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.routing-editor {
  padding: 20px;
  min-width: 450px;

  h2 {
    margin: 0 0 8px;
  }

  .routing-description {
    color: var(--color-text-maxcontrast);
    margin: 0 0 20px;
    font-size: 14px;
  }
}

.no-rules {
  text-align: center;
  padding: 20px;
  color: var(--color-text-maxcontrast);
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  margin-bottom: 16px;

  p { margin: 0; }
}

.routing-rule {
  padding: 16px;
  margin-bottom: 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  border-left: 3px solid var(--color-primary-element);

  .rule-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }

  .rule-number {
    font-weight: 600;
    font-size: 13px;
    color: var(--color-primary-element);
  }

  .remove-rule {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 20px;
    color: var(--color-text-maxcontrast);
    padding: 0 4px;

    &:hover {
      color: var(--color-error);
    }
  }
}

.rule-fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.rule-field {
  label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--color-text-maxcontrast);
  }

  select, input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    font-size: 14px;
    background: var(--color-main-background);
  }
}

.routing-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
