<template>
  <div class="response-links">
    <p class="section-description">
      {{ t('formvox', 'Create a batch of unique links that each accept a limited number of responses, for example one link per respondent that can be used only once.') }}
    </p>

    <div class="generate-row">
      <label class="field">
        <!-- TRANSLATORS: Label of the number of unique links to create -->
        <span>{{ t('formvox', 'Number of links') }}</span>
        <input v-model.number="newLinks.count" type="number" min="1" :max="maxPerBatch" class="number-input">
      </label>
      <label class="field">
        <!-- TRANSLATORS: Label of how many responses each unique link accepts -->
        <span>{{ t('formvox', 'Responses per link') }}</span>
        <input v-model.number="newLinks.maxUses" type="number" min="1" class="number-input">
      </label>
      <NcTextField
        v-model="newLinks.label"
        class="label-field"
        :label="t('formvox', 'Label (optional)')"
        :placeholder="t('formvox', 'e.g., Class 3B')"
      />
      <NcButton type="primary" :disabled="generating || !canGenerate" @click="generateLinks">
        {{ generating ? t('formvox', 'Creating …') : t('formvox', 'Create links') }}
      </NcButton>
    </div>

    <template v-if="links.length">
      <p class="summary">
        <!-- TRANSLATORS: {total} links exist, {available} can still be used, {used} are used up -->
        {{ t('formvox', '{total} links: {available} available, {used} used up', { total: links.length, available: availableLinks.length, used: links.length - availableLinks.length }) }}
      </p>

      <div class="bulk-actions">
        <NcButton type="secondary" @click="downloadCsv(false)">
          <template #icon>
            <DownloadIcon :size="20" />
          </template>
          {{ t('formvox', 'Download all as CSV') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="!availableLinks.length" @click="downloadCsv(true)">
          {{ t('formvox', 'Download available as CSV') }}
        </NcButton>
        <NcButton type="tertiary" :disabled="!availableLinks.length" @click="copyAvailable">
          <template #icon>
            <CopyIcon :size="20" />
          </template>
          {{ t('formvox', 'Copy available links') }}
        </NcButton>
      </div>

      <ul class="link-list">
        <li v-for="link in links" :key="link.id" class="link-item" :class="{ 'used-up': isUsedUp(link) }">
          <div class="link-info">
            <code class="link-url">{{ linkUrl(link) }}</code>
            <span class="link-meta">
              <template v-if="link.label">{{ link.label }} · </template>
              <!-- TRANSLATORS: {uses} responses submitted through a link out of {max} allowed -->
              {{ t('formvox', '{uses} / {max} used', { uses: link.uses, max: link.max_uses }) }}
            </span>
          </div>
          <NcButton type="tertiary" :aria-label="t('formvox', 'Copy')" @click="copyLink(link)">
            <template #icon>
              <CopyIcon :size="18" />
            </template>
          </NcButton>
          <NcButton type="tertiary" :aria-label="t('formvox', 'Delete link')" @click="deleteLink(link)">
            <template #icon>
              <DeleteIcon :size="18" />
            </template>
          </NcButton>
        </li>
      </ul>

      <NcButton type="tertiary" @click="deleteAll">
        {{ t('formvox', 'Delete all unique links') }}
      </NcButton>
    </template>
  </div>
</template>

<script>
import { t } from '@/utils/l10n';
import { ref, reactive, computed, onMounted } from 'vue';
import { NcButton, NcTextField } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import CopyIcon from './icons/CopyIcon.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';

// Mirrors ResponseLinkService::MAX_PER_BATCH
const MAX_PER_BATCH = 1000;

export default {
  name: 'ResponseLinks',
  components: {
    NcButton,
    NcTextField,
    CopyIcon,
    DeleteIcon,
    DownloadIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    formTitle: {
      type: String,
      default: '',
    },
  },
  emits: ['update:count'],
  setup(props, { emit }) {
    const links = ref([]);
    const generating = ref(false);
    const newLinks = reactive({
      count: 10,
      maxUses: 1,
      label: '',
    });

    const apiUrl = (path = '') => generateUrl(`/apps/formvox/api/form/{fileId}/response-links${path}`, { fileId: props.fileId });

    const setLinks = (list) => {
      links.value = list;
      emit('update:count', list.length);
    };

    const isUsedUp = (link) => link.uses >= link.max_uses;
    const availableLinks = computed(() => links.value.filter((link) => !isUsedUp(link)));
    const canGenerate = computed(() => newLinks.count >= 1 && newLinks.count <= MAX_PER_BATCH && newLinks.maxUses >= 1);

    const linkUrl = (link) => `${window.location.origin}${generateUrl('/apps/formvox/public/{fileId}/{token}', { fileId: props.fileId, token: link.token })}`;

    const loadLinks = async () => {
      try {
        const response = await axios.get(apiUrl());
        setLinks(response.data.links || []);
      } catch (error) {
        console.error('Failed to load unique links:', error);
      }
    };

    const generateLinks = async () => {
      generating.value = true;
      try {
        const response = await axios.post(apiUrl(), {
          count: newLinks.count,
          maxUses: newLinks.maxUses,
          label: newLinks.label,
        });
        setLinks(response.data.links || []);
        // TRANSLATORS: {count} is the number of unique response links created
        showSuccess(t('formvox', '{count} links created', { count: newLinks.count }));
      } catch (error) {
        showError(error.response?.data?.error || t('formvox', 'Failed to create links'));
        console.error(error);
      } finally {
        generating.value = false;
      }
    };

    const writeClipboard = async (text) => {
      try {
        await navigator.clipboard.writeText(text);
        showSuccess(t('formvox', 'Copied!'));
      } catch (error) {
        showError(t('formvox', 'Could not copy to the clipboard'));
      }
    };

    const copyLink = (link) => writeClipboard(linkUrl(link));
    const copyAvailable = () => writeClipboard(availableLinks.value.map(linkUrl).join('\n'));

    const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

    const downloadCsv = (onlyAvailable) => {
      const rows = (onlyAvailable ? availableLinks.value : links.value).map((link) => [
        linkUrl(link),
        link.label,
        link.max_uses,
        link.uses,
        link.created_at,
      ]);
      const header = ['link', 'label', 'max_responses', 'responses', 'created_at'];
      const csv = [header, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n');

      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${props.formTitle || 'form'}-links.csv`;
      a.click();
      URL.revokeObjectURL(url);
    };

    const deleteLink = async (link) => {
      if (!confirm(t('formvox', 'Delete this link? It can no longer be used to respond. Responses already submitted are kept.'))) {
        return;
      }
      try {
        await axios.delete(apiUrl(`/${encodeURIComponent(link.id)}`));
        setLinks(links.value.filter((l) => l.id !== link.id));
      } catch (error) {
        showError(t('formvox', 'Failed to delete link'));
        console.error(error);
      }
    };

    const deleteAll = async () => {
      if (!confirm(t('formvox', 'Delete all unique links? They can no longer be used to respond. Responses already submitted are kept.'))) {
        return;
      }
      try {
        await axios.delete(apiUrl());
        setLinks([]);
        showSuccess(t('formvox', 'All unique links deleted'));
      } catch (error) {
        showError(t('formvox', 'Failed to delete links'));
        console.error(error);
      }
    };

    onMounted(loadLinks);

    return {
      links,
      generating,
      newLinks,
      maxPerBatch: MAX_PER_BATCH,
      availableLinks,
      canGenerate,
      isUsedUp,
      linkUrl,
      generateLinks,
      copyLink,
      copyAvailable,
      downloadCsv,
      deleteLink,
      deleteAll,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.section-description {
  color: var(--color-text-maxcontrast);
  font-size: 13px;
  margin: 0 0 12px;
}

.generate-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 12px;

  .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
  }

  .number-input {
    width: 100px;
    padding: 6px 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    font-size: 14px;
  }

  .label-field {
    flex: 1;
    min-width: 160px;
  }
}

.summary {
  margin: 0 0 8px;
  font-size: 13px;
}

.bulk-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

.link-list {
  max-height: 240px;
  overflow-y: auto;
  margin: 0 0 8px;
  padding: 0;
  list-style: none;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
}

.link-item {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-bottom: 1px solid var(--color-border);

  &:last-child {
    border-bottom: none;
  }

  &.used-up .link-info {
    opacity: 0.5;
  }

  .link-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
  }

  .link-url {
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .link-meta {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
  }
}
</style>
