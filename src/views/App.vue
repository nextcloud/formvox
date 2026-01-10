<template>
  <NcContent app-name="formvox">
    <NcAppNavigation>
      <template #list>
        <NcAppNavigationNew
          :text="t('New form')"
          @click="showNewFormModal = true"
        />
        <NcAppNavigationItem
          v-for="form in forms"
          :key="form.fileId"
          :name="form.title"
          :to="getFormUrl(form)"
        >
          <template #icon>
            <FormIcon :size="20" />
          </template>
          <template #counter>
            <NcCounterBubble>{{ form.responseCount }}</NcCounterBubble>
          </template>
        </NcAppNavigationItem>
      </template>
    </NcAppNavigation>

    <NcAppContent>
      <div v-if="loading" class="loading-container">
        <NcLoadingIcon :size="64" />
      </div>

      <div v-else-if="forms.length === 0" class="empty-state">
        <FormIcon :size="64" />
        <h2>{{ t('No forms yet') }}</h2>
        <p>{{ t('Create your first form to get started.') }}</p>
        <NcButton type="primary" @click="showNewFormModal = true">
          {{ t('Create form') }}
        </NcButton>
      </div>

      <div v-else class="forms-grid">
        <FormCard
          v-for="form in forms"
          :key="form.fileId"
          :form="form"
          @click="openForm(form)"
          @delete="deleteForm(form)"
        />
      </div>
    </NcAppContent>

    <NewFormModal
      v-if="showNewFormModal"
      @close="showNewFormModal = false"
      @created="onFormCreated"
    />
  </NcContent>
</template>

<script>
import { ref, onMounted } from 'vue';
import {
  NcContent,
  NcAppNavigation,
  NcAppNavigationNew,
  NcAppNavigationItem,
  NcAppContent,
  NcButton,
  NcCounterBubble,
  NcLoadingIcon,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import FormCard from '../components/FormCard.vue';
import NewFormModal from '../components/NewFormModal.vue';
import FormIcon from '../components/icons/FormIcon.vue';

export default {
  name: 'App',
  components: {
    NcContent,
    NcAppNavigation,
    NcAppNavigationNew,
    NcAppNavigationItem,
    NcAppContent,
    NcButton,
    NcCounterBubble,
    NcLoadingIcon,
    FormCard,
    NewFormModal,
    FormIcon,
  },
  setup() {
    const forms = ref([]);
    const loading = ref(true);
    const showNewFormModal = ref(false);

    const loadForms = async () => {
      loading.value = true;
      try {
        const response = await axios.get(generateUrl('/apps/formvox/api/forms'));
        forms.value = response.data;
      } catch (error) {
        showError(t('Failed to load forms'));
        console.error(error);
      } finally {
        loading.value = false;
      }
    };

    const getFormUrl = (form) => {
      return generateUrl('/apps/formvox/edit/{fileId}', { fileId: form.fileId });
    };

    const openForm = (form) => {
      window.location.href = getFormUrl(form);
    };

    const deleteForm = async (form) => {
      if (!confirm(t('Are you sure you want to delete this form?'))) {
        return;
      }

      try {
        await axios.delete(generateUrl('/apps/formvox/api/form/{fileId}', { fileId: form.fileId }));
        forms.value = forms.value.filter(f => f.fileId !== form.fileId);
        showSuccess(t('Form deleted'));
      } catch (error) {
        showError(t('Failed to delete form'));
        console.error(error);
      }
    };

    const onFormCreated = (newForm) => {
      forms.value.unshift(newForm);
      showNewFormModal.value = false;
      openForm(newForm);
    };

    onMounted(() => {
      loadForms();
    });

    return {
      forms,
      loading,
      showNewFormModal,
      getFormUrl,
      openForm,
      deleteForm,
      onFormCreated,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.loading-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100%;
}

.empty-state {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  height: 100%;
  text-align: center;
  color: var(--color-text-maxcontrast);

  h2 {
    margin-top: 20px;
    margin-bottom: 10px;
  }

  p {
    margin-bottom: 20px;
  }
}

.forms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
  padding: 20px;
}
</style>
