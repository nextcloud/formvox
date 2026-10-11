<template>
  <div class="admin-templates">
    <!-- TRANSLATORS: Heading of the administration section that manages form templates -->
    <h2>{{ t('formvox', 'Form templates') }}</h2>
    <p class="settings-section-desc">
      {{ t('formvox', 'Templates appear in the "New form" dialog for everyone. Snapshot any existing form into a template here, or remove templates you no longer want to offer.') }}
    </p>

    <div class="template-add">
      <h3>{{ t('formvox', 'Snapshot a form as a template') }}</h3>
      <p class="hint">{{ t('formvox', 'Pick a form you already created — its structure (questions, settings, branding) is copied. Responses and share links are stripped.') }}</p>
      <div v-if="loadingForms">{{ t('formvox', 'Loading your forms …') }}</div>
      <div v-else-if="ownedForms.length === 0" class="empty">
        {{ t('formvox', 'You have no forms yet. Create one first.') }}
      </div>
      <div v-else class="snapshot-row">
        <NcSelect
          v-model="snapshotFormId"
          :options="ownedForms"
          label="title"
          track-by="fileId"
          :placeholder="t('formvox', 'Select a form')"
        />
        <NcTextField
          v-model="snapshotTitle"
          :placeholder="t('formvox', 'Template title (optional)')"
          class="title-input"
        />
        <NcButton
          type="primary"
          :disabled="!snapshotFormId || saving"
          @click="snapshotTemplate"
        >
          {{ saving ? t('formvox', 'Saving …') : t('formvox', 'Save as template') }}
        </NcButton>
      </div>
    </div>

    <div class="template-list">
      <!-- TRANSLATORS: Heading above the list of form templates -->
      <h3>{{ t('formvox', 'Available templates') }}</h3>
      <!-- TRANSLATORS: Shown while a list loads -->
      <div v-if="loading">{{ t('formvox', 'Loading …') }}</div>
      <div v-else-if="templates.length === 0" class="empty">
        {{ t('formvox', 'No custom templates yet.') }}
      </div>
      <table v-else class="templates-table">
        <thead>
          <tr>
            <!-- TRANSLATORS: Column header: the title of a template -->
            <th>{{ t('formvox', 'Title') }}</th>
            <th>{{ t('formvox', 'Description') }}</th>
            <!-- TRANSLATORS: Column header: the number of questions -->
            <th>{{ t('formvox', 'Questions') }}</th>
            <!-- TRANSLATORS: Column header: the date a template was added -->
            <th>{{ t('formvox', 'Added') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tpl in templates" :key="tpl.id">
            <td>{{ tpl.title }}</td>
            <td class="muted">{{ truncate(tpl.description, 80) }}</td>
            <td>{{ tpl.questionCount }}</td>
            <td class="muted">{{ formatDate(tpl.createdAt) }}</td>
            <td>
              <NcButton type="tertiary" @click="removeTemplate(tpl.id)">
                <template #icon>
                  <CloseIcon :size="18" />
                </template>
              </NcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import { t } from '@/utils/l10n';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';

export default {
  name: 'AdminTemplates',
  components: { NcButton, NcTextField, NcSelect, CloseIcon },
  setup() {
    const templates = ref([]);
    const loading = ref(false);
    const saving = ref(false);
    const ownedForms = ref([]);
    const loadingForms = ref(false);
    const snapshotFormId = ref(null);
    const snapshotTitle = ref('');

    const loadTemplates = async () => {
      loading.value = true;
      try {
        const { data } = await axios.get(generateUrl('/apps/formvox/api/admin/templates'));
        templates.value = data?.templates || [];
      } catch (e) {
        showError(t('formvox', 'Failed to load templates'));
        console.error(e);
      } finally {
        loading.value = false;
      }
    };

    const loadOwnedForms = async () => {
      loadingForms.value = true;
      try {
        const { data } = await axios.get(generateUrl('/apps/formvox/api/forms'));
        ownedForms.value = (data || []).map(f => ({ fileId: f.fileId, title: f.title || '(untitled)' }));
      } catch (e) {
        console.error(e);
      } finally {
        loadingForms.value = false;
      }
    };

    const snapshotTemplate = async () => {
      if (!snapshotFormId.value) return;
      const fileId = snapshotFormId.value.fileId || snapshotFormId.value;
      saving.value = true;
      try {
        await axios.post(
          generateUrl('/apps/formvox/api/form/{fileId}/save-as-template', { fileId }),
          { title: snapshotTitle.value, description: '' },
        );
        // TRANSLATORS: Confirmation message
        showSuccess(t('formvox', 'Template saved'));
        snapshotFormId.value = null;
        snapshotTitle.value = '';
        await loadTemplates();
      } catch (e) {
        showError(t('formvox', 'Failed to save template'));
        console.error(e);
      } finally {
        saving.value = false;
      }
    };

    const removeTemplate = async (id) => {
      try {
        await axios.delete(generateUrl('/apps/formvox/api/admin/templates/{id}', { id }));
        // TRANSLATORS: Confirmation message
        showSuccess(t('formvox', 'Template removed'));
        await loadTemplates();
      } catch (e) {
        showError(t('formvox', 'Failed to remove template'));
        console.error(e);
      }
    };

    const truncate = (s, n) => {
      if (!s) return '';
      return s.length <= n ? s : s.slice(0, n) + '…';
    };
    const formatDate = (iso) => {
      if (!iso) return '';
      try { return new Date(iso).toLocaleDateString(); } catch { return iso; }
    };

    onMounted(() => {
      loadTemplates();
      loadOwnedForms();
    });

    return {
      t, templates, loading, saving,
      ownedForms, loadingForms,
      snapshotFormId, snapshotTitle,
      snapshotTemplate, removeTemplate,
      truncate, formatDate,
    };
  },
};
</script>

<style scoped lang="scss">
.admin-templates {
  padding: 16px 0;

  h2 { margin: 0 0 8px; }
  .settings-section-desc {
    color: var(--color-text-maxcontrast);
    margin: 0 0 24px;
  }

  .template-add {
    margin-bottom: 32px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large, 8px);

    h3 { margin: 0 0 4px; font-size: 14px; font-weight: 600; }
    .hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0 0 12px; }

    .snapshot-row {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;

      .title-input { flex: 1; min-width: 200px; }
    }
  }

  .template-list {
    h3 { margin: 0 0 12px; font-size: 14px; font-weight: 600; }
  }

  .templates-table {
    width: 100%;
    border-collapse: collapse;

    th, td {
      text-align: left;
      padding: 8px 12px;
      border-bottom: 1px solid var(--color-border);
    }

    th { font-size: 13px; color: var(--color-text-maxcontrast); }

    .muted { color: var(--color-text-maxcontrast); font-size: 13px; }
  }

  .empty {
    color: var(--color-text-maxcontrast);
    font-style: italic;
  }
}
</style>
