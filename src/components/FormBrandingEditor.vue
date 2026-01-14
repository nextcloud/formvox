<template>
  <NcModal :show="true" size="large" @close="$emit('close')">
    <div class="form-branding-editor">
      <div class="modal-header">
        <h2>{{ t('Form Theme') }}</h2>
        <p class="description">{{ t('Customize the look and feel of this form. Leave disabled to use the admin defaults.') }}</p>
      </div>

      <div class="branding-toggle">
        <NcCheckboxRadioSwitch
          :model-value="useCustomBranding"
          @update:model-value="toggleCustomBranding"
        >
          {{ t('Use custom theme for this form') }}
        </NcCheckboxRadioSwitch>
      </div>

      <template v-if="useCustomBranding">
        <PageBuilder
          :initial-branding="localBranding"
          :embedded="true"
          @update:branding="onBrandingUpdate"
        />
      </template>

      <div v-else class="using-defaults">
        <div class="info-box">
          <InfoIcon :size="20" />
          <span>{{ t('This form uses the default theme. Enable custom theme above to customize.') }}</span>
        </div>
      </div>

      <div class="modal-actions">
        <NcButton @click="$emit('close')">
          {{ t('Close') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script>
import { ref, reactive, computed, watch } from 'vue';
import { NcModal, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue';
import { t } from '@/utils/l10n';
import PageBuilder from './pagebuilder/PageBuilder.vue';
import InfoIcon from './icons/InfoIcon.vue';

const DEFAULT_BRANDING = {
  layout: {
    header: [],
    footer: [],
    thankYou: [
      {
        id: 'default-thankyou-heading',
        type: 'heading',
        alignment: 'center',
        settings: {
          level: 'h1',
          text: 'Thank you!',
        },
      },
      {
        id: 'default-thankyou-text',
        type: 'text',
        alignment: 'center',
        settings: {
          content: 'Your response has been submitted successfully.',
        },
      },
    ],
  },
  globalStyles: {
    primaryColor: '#0082c9',
    backgroundColor: '#ffffff',
    fontFamily: 'default',
  },
};

export default {
  name: 'FormBrandingEditor',
  components: {
    NcModal,
    NcButton,
    NcCheckboxRadioSwitch,
    PageBuilder,
    InfoIcon,
  },
  props: {
    branding: {
      type: Object,
      default: null,
    },
  },
  emits: ['close', 'update:branding'],
  setup(props, { emit }) {
    // Check if custom branding is enabled (branding is not null/undefined)
    // We use Boolean check because branding could be undefined for old forms
    const useCustomBranding = ref(props.branding != null && Object.keys(props.branding).length > 0);

    // Local branding state
    const localBranding = reactive(
      (props.branding != null && Object.keys(props.branding).length > 0)
        ? JSON.parse(JSON.stringify(props.branding))
        : JSON.parse(JSON.stringify(DEFAULT_BRANDING))
    );

    const toggleCustomBranding = (enabled) => {
      useCustomBranding.value = enabled;
      if (enabled) {
        // Enable custom branding - emit the current localBranding
        emit('update:branding', JSON.parse(JSON.stringify(localBranding)));
      } else {
        // Disable custom branding - emit null to use defaults
        emit('update:branding', null);
      }
    };

    const onBrandingUpdate = (newBranding) => {
      // Update local state
      Object.assign(localBranding, newBranding);
      // Emit to parent
      emit('update:branding', JSON.parse(JSON.stringify(localBranding)));
    };

    return {
      useCustomBranding,
      localBranding,
      toggleCustomBranding,
      onBrandingUpdate,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.form-branding-editor {
  padding: 20px;
  max-height: 80vh;
  overflow-y: auto;
}

.modal-header {
  margin-bottom: 20px;

  h2 {
    margin: 0 0 8px;
  }

  .description {
    color: var(--color-text-maxcontrast);
    margin: 0;
  }
}

.branding-toggle {
  padding: 16px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  margin-bottom: 20px;
}

.using-defaults {
  padding: 40px 20px;
  text-align: center;
}

.info-box {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  padding: 16px 24px;
  background: var(--color-primary-element-light);
  border-radius: var(--border-radius-large);
  color: var(--color-primary);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}
</style>
