<template>
  <div class="formvox-admin-settings">
    <!-- License Banner -->
    <NcNoteCard v-if="licenseBanner"
      :type="licenseBanner.type"
      class="license-banner">
      {{ licenseBanner.message }}
      <NcButton type="tertiary" @click="activeTab = 'support'">
        {{ t('formvox', 'View subscription options') }}
      </NcButton>
    </NcNoteCard>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
      <button
        :class="['tab-button', { active: activeTab === 'branding' }]"
        @click="activeTab = 'branding'">
        <Palette :size="16" />
        {{ t('formvox', 'Branding') }}
      </button>
      <button
        :class="['tab-button', { active: activeTab === 'statistics' }]"
        @click="activeTab = 'statistics'">
        <ChartBox :size="16" />
        {{ t('formvox', 'Statistics') }}
      </button>
      <button
        :class="['tab-button', { active: activeTab === 'settings' }]"
        @click="activeTab = 'settings'">
        <Cog :size="16" />
        {{ t('formvox', 'Settings') }}
      </button>
      <button
        :class="['tab-button', { active: activeTab === 'integrations' }]"
        @click="activeTab = 'integrations'">
        <MicrosoftIcon :size="16" />
        {{ t('formvox', 'Integrations') }}
      </button>
      <button
        :class="['tab-button', { active: activeTab === 'support' }]"
        @click="activeTab = 'support'">
        <HeartIcon :size="16" />
        {{ t('formvox', 'Support') }}
      </button>
    </div>

    <!-- Branding Tab -->
    <div v-if="activeTab === 'branding'" class="tab-content">
      <PageBuilder :initial-branding="branding" />
    </div>

    <!-- Statistics Tab -->
    <div v-if="activeTab === 'statistics'" class="tab-content">
      <!-- Form Statistics Section -->
      <div class="settings-section">
        <h2>{{ t('formvox', 'Form Statistics') }}</h2>
        <p class="settings-section-desc">
          {{ t('formvox', 'Overview of forms and responses in your FormVox installation.') }}
        </p>

        <div v-if="loadingStatistics" class="stats-loading">
          <NcLoadingIcon :size="32" />
          <span>{{ t('formvox', 'Loading statistics...') }}</span>
        </div>
        <div v-else class="stats-overview">
          <div class="stat-row">
            <div class="stat-info">
              <span class="stat-icon">📋</span>
              <span class="stat-label">{{ t('formvox', 'Total Forms') }}</span>
            </div>
            <span class="stat-value">{{ stats.totalForms }}</span>
          </div>
          <div class="stat-row">
            <div class="stat-info">
              <span class="stat-icon">📝</span>
              <span class="stat-label">{{ t('formvox', 'Total Responses') }}</span>
            </div>
            <span class="stat-value">{{ stats.totalResponses }}</span>
          </div>
          <div class="stat-row">
            <div class="stat-info">
              <span class="stat-icon">👥</span>
              <span class="stat-label">{{ t('formvox', 'Active Users (30 days)') }}</span>
            </div>
            <span class="stat-value">{{ stats.activeUsers30d }}</span>
          </div>
        </div>

      </div>
    </div>

    <!-- Settings Tab -->
    <div v-if="activeTab === 'settings'" class="tab-content">
      <div class="settings-section">
        <h2>{{ t('formvox', 'Embed Settings') }}</h2>
        <p class="settings-section-desc">
          {{ t('formvox', 'Configure which external websites can embed FormVox forms.') }}
        </p>

        <div class="setting-row">
          <label class="setting-label">{{ t('formvox', 'Allowed domains for embedding') }}</label>
          <p class="setting-help">
            {{ t('formvox', 'Enter * to allow all domains, or a comma-separated list of domains (e.g., sharepoint.com, intranet.company.nl). Forms can only be embedded on these domains.') }}
          </p>
          <div class="setting-input-row">
            <input
              type="text"
              v-model="embedSettings.allowedDomains"
              class="setting-input"
              :placeholder="t('formvox', '* (all domains)')"
            />
            <NcButton type="primary" @click="saveEmbedSettings" :disabled="savingEmbedSettings">
              {{ savingEmbedSettings ? t('formvox', 'Saving...') : t('formvox', 'Save') }}
            </NcButton>
          </div>
        </div>

      </div>
    </div>

    <!-- Support Tab -->
    <div v-if="activeTab === 'support'" class="tab-content">
      <SupportSettings
        :initial-telemetry-enabled="telemetryEnabled"
        :initial-telemetry-last-report="telemetryStatus.lastReport"
        @license-changed="onLicenseChanged" />
    </div>

    <!-- Integrations Tab -->
    <div v-if="activeTab === 'integrations'" class="tab-content">
      <div class="settings-section">
        <h2>{{ t('formvox', 'Microsoft Forms Import') }}</h2>
        <p class="settings-section-desc">
          {{ t('formvox', 'Configure Microsoft Azure AD credentials to enable importing forms from Microsoft Forms.') }}
        </p>

        <NcNoteCard type="info">
          <p>{{ t('formvox', 'To enable Microsoft Forms import, you need to register an application in Azure AD.') }}</p>
          <ol class="azure-steps">
            <li>{{ t('formvox', 'Go to Azure Portal > Microsoft Entra ID > App registrations') }}</li>
            <li>{{ t('formvox', 'Create a new registration with a name like "FormVox Import"') }}</li>
            <li>{{ t('formvox', 'Set the Redirect URI (Web) to:') }} <code>{{ msFormsSettings.redirectUri }}</code></li>
            <li>{{ t('formvox', 'Go to "API permissions" and add the following:') }}
              <ul class="api-permissions-list">
                <li><strong>Microsoft Forms</strong> (forms.office.com) → <code>Forms.Read</code> (Delegated)</li>
              </ul>
              <em>{{ t('formvox', 'Note: Search for "Microsoft Forms" under "APIs my organization uses"') }}</em>
            </li>
            <li>{{ t('formvox', 'Click "Grant admin consent" for your organization') }}</li>
            <li>{{ t('formvox', 'Go to "Certificates & secrets" and create a new client secret') }}</li>
            <li>{{ t('formvox', 'Copy the Application (client) ID and the client secret value below') }}</li>
          </ol>
        </NcNoteCard>

        <div class="setting-row">
          <label class="setting-label">{{ t('formvox', 'Tenant ID') }}</label>
          <p class="setting-help">
            {{ t('formvox', 'Use "common" for multi-tenant, or your specific tenant ID for single-tenant.') }}
          </p>
          <input
            type="text"
            v-model="msFormsSettings.tenantId"
            class="setting-input"
            placeholder="common"
          />
        </div>

        <div class="setting-row">
          <label class="setting-label">{{ t('formvox', 'Client ID (Application ID)') }}</label>
          <input
            type="text"
            v-model="msFormsSettings.clientId"
            class="setting-input"
            :placeholder="t('formvox', 'e.g., 12345678-1234-1234-1234-123456789012')"
          />
        </div>

        <div class="setting-row">
          <label class="setting-label">{{ t('formvox', 'Client Secret') }}</label>
          <p class="setting-help">
            {{ t('formvox', 'Leave empty to keep existing secret. Enter a new value to update.') }}
          </p>
          <input
            type="password"
            v-model="msFormsSettings.clientSecret"
            class="setting-input"
            :placeholder="msFormsSettings.isConfigured ? '••••••••' : t('formvox', 'Enter client secret')"
          />
        </div>

        <div class="setting-actions">
          <NcButton type="primary" @click="saveMsFormsSettings" :disabled="savingMsFormsSettings">
            {{ savingMsFormsSettings ? t('formvox', 'Saving...') : t('formvox', 'Save') }}
          </NcButton>
          <span v-if="msFormsSettings.isConfigured" class="configured-badge">
            {{ t('formvox', 'Configured') }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, onMounted } from 'vue';
import { NcCheckboxRadioSwitch, NcNoteCard, NcButton, NcLoadingIcon } from '@nextcloud/vue';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showSuccess, showError } from '@nextcloud/dialogs';
import PageBuilder from '../components/pagebuilder/PageBuilder.vue';
import SupportSettings from '../components/SupportSettings.vue';
import Palette from 'vue-material-design-icons/Palette.vue';
import ChartBox from 'vue-material-design-icons/ChartBox.vue';
import Cog from 'vue-material-design-icons/Cog.vue';
import MicrosoftIcon from 'vue-material-design-icons/Microsoft.vue';
import HeartIcon from 'vue-material-design-icons/Heart.vue';

export default {
  name: 'AdminSettings',
  components: {
    NcCheckboxRadioSwitch,
    NcNoteCard,
    NcButton,
    NcLoadingIcon,
    PageBuilder,
    SupportSettings,
    Palette,
    ChartBox,
    Cog,
    MicrosoftIcon,
    HeartIcon,
  },
  props: {
    initialBranding: {
      type: Object,
      required: true,
    },
    initialTelemetry: {
      type: Object,
      default: () => ({ enabled: true, lastReport: null }),
    },
    initialEmbedSettings: {
      type: Object,
      default: () => ({ allowedDomains: '*' }),
    },
    initialMsFormsSettings: {
      type: Object,
      default: () => ({ clientId: '', tenantId: 'common', isConfigured: false, redirectUri: '' }),
    },
  },
  setup(props) {
    const activeTab = ref('branding');
    const branding = props.initialBranding;

    const loadingStatistics = ref(true);
    const stats = reactive({
      totalForms: 0,
      totalResponses: 0,
      activeUsers30d: 0,
    });

    const telemetryEnabled = ref(props.initialTelemetry.enabled !== false);
    const telemetryStatus = reactive({
      lastReport: props.initialTelemetry.lastReport || null,
    });

    // Embed settings
    const embedSettings = reactive({
      allowedDomains: props.initialEmbedSettings?.allowedDomains || '*',
    });
    const savingEmbedSettings = ref(false);

    // MS Forms settings
    const msFormsSettings = reactive({
      clientId: props.initialMsFormsSettings?.clientId || '',
      tenantId: props.initialMsFormsSettings?.tenantId || 'common',
      clientSecret: '',
      isConfigured: props.initialMsFormsSettings?.isConfigured || false,
      redirectUri: props.initialMsFormsSettings?.redirectUri || '',
    });
    const savingMsFormsSettings = ref(false);

    const toggleTelemetry = async (enabled) => {
      try {
        const response = await axios.post(
          generateUrl('/apps/formvox/api/statistics/telemetry'),
          { enabled }
        );
        telemetryEnabled.value = response.data.enabled;
      } catch (error) {
        console.error('Failed to toggle telemetry:', error);
        // Revert on error
        telemetryEnabled.value = !enabled;
      }
    };

    const saveEmbedSettings = async () => {
      savingEmbedSettings.value = true;
      try {
        await axios.post(
          generateUrl('/apps/formvox/api/settings/embed'),
          { allowedDomains: embedSettings.allowedDomains }
        );
        showSuccess(t('formvox', 'Embed settings saved'));
      } catch (error) {
        console.error('Failed to save embed settings:', error);
        showError(t('formvox', 'Failed to save embed settings'));
      } finally {
        savingEmbedSettings.value = false;
      }
    };

    const saveMsFormsSettings = async () => {
      savingMsFormsSettings.value = true;
      try {
        await axios.post(
          generateUrl('/apps/formvox/api/settings/ms-forms'),
          {
            clientId: msFormsSettings.clientId,
            tenantId: msFormsSettings.tenantId,
            clientSecret: msFormsSettings.clientSecret || undefined,
          }
        );
        msFormsSettings.isConfigured = !!(msFormsSettings.clientId && (msFormsSettings.clientSecret || msFormsSettings.isConfigured));
        msFormsSettings.clientSecret = '';
        showSuccess(t('formvox', 'Microsoft Forms settings saved'));
      } catch (error) {
        console.error('Failed to save MS Forms settings:', error);
        showError(t('formvox', 'Failed to save Microsoft Forms settings'));
      } finally {
        savingMsFormsSettings.value = false;
      }
    };

    const formatDate = (timestamp) => {
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    };

    const t = (app, text, vars) => {
      if (window.t) {
        return vars ? window.t(app, text, vars) : window.t(app, text);
      }
      return text;
    };

    onMounted(async () => {
      try {
        const response = await axios.get(generateUrl('/apps/formvox/api/statistics'));
        stats.totalForms = response.data.statistics?.totalForms || response.data.totalForms || 0;
        stats.totalResponses = response.data.statistics?.totalResponses || response.data.totalResponses || 0;
        stats.activeUsers30d = response.data.statistics?.activeUsers30d || response.data.activeUsers30d || 0;
      } catch (error) {
        console.error('Failed to load statistics:', error);
      } finally {
        loadingStatistics.value = false;
      }

      // Load license stats for banner
      try {
        const res = await axios.get(generateUrl('/apps/formvox/api/license/stats'));
        if (res.data.success) licenseStats.value = res.data;
      } catch (e) { /* ignore */ }
    });

    const licenseStats = ref(null);
    const licenseBanner = computed(() => {
      if (!licenseStats.value) return null;
      const s = licenseStats.value;
      if (s.hasLicense && s.licenseValid) return null;
      if (!s.needsLicense) return null;
      if (s.hasLicense && !s.licenseValid) {
        return {
          type: 'warning',
          message: t('formvox', 'Your FormVox subscription key is invalid or expired. Please renew to continue receiving support.'),
        };
      }
      return {
        type: 'info',
        message: t('formvox', 'Your FormVox installation has exceeded the free tier limits (25 forms or 50 users). Consider subscribing to support ongoing development.'),
      };
    });

    const onLicenseChanged = async () => {
      try {
        const res = await axios.get(generateUrl('/apps/formvox/api/license/stats'));
        if (res.data.success) licenseStats.value = res.data;
      } catch (e) { /* ignore */ }
    };

    return {
      activeTab,
      branding,
      loadingStatistics,
      stats,
      telemetryEnabled,
      telemetryStatus,
      embedSettings,
      savingEmbedSettings,
      msFormsSettings,
      savingMsFormsSettings,
      toggleTelemetry,
      saveEmbedSettings,
      saveMsFormsSettings,
      licenseStats,
      licenseBanner,
      onLicenseChanged,
      formatDate,
      t,
    };
  },
};
</script>

<style scoped>
.formvox-admin-settings {
  max-width: 900px;
  padding: 20px;
}

/* License Banner */
.license-banner {
  margin-bottom: 16px;
}

/* Tab Navigation - IntraVox style */
.tab-navigation {
  border-bottom: 1px solid var(--color-border);
  margin-bottom: 20px;
  display: flex;
  gap: 10px;
}

.tab-button {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  border: none;
  background: none;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  color: var(--color-text-lighter);
  font-size: 14px;
  transition: all 0.2s ease;
}

.tab-button:hover:not(.active) {
  background: var(--color-background-hover);
}

.tab-button.active {
  border-bottom-color: var(--color-primary);
  color: var(--color-primary);
  background: var(--color-primary-element-light);
}

.tab-content {
  animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Settings sections */
.settings-section {
  margin-bottom: 32px;
}

.settings-section h2 {
  font-size: 20px;
  font-weight: bold;
  margin-bottom: 8px;
}

.settings-section-desc {
  color: var(--color-text-maxcontrast);
  margin-bottom: 20px;
}

/* Stats loading */
.stats-loading {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 24px;
  color: var(--color-text-maxcontrast);
}

/* Stats overview */
.stats-overview {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 24px;
}

.stat-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
}

.stat-info {
  display: flex;
  align-items: center;
  gap: 12px;
}

.stat-icon {
  font-size: 1.5em;
}

.stat-label {
  font-weight: 500;
  color: var(--color-main-text);
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-primary);
}


.telemetry-details ul.not-collected {
  color: var(--color-main-text);
}

.telemetry-details ul.not-collected li {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.telemetry-details ul.not-collected li::before {
  content: '\2713';
  color: var(--color-success-text, #2d7b43);
  font-weight: 600;
  flex-shrink: 0;
}

.telemetry-details ul.not-collected li::marker {
  content: '';
}

/* Settings tab */
.setting-row {
  margin-bottom: 24px;
}

.setting-label {
  display: block;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--color-main-text);
}

.setting-help {
  font-size: 13px;
  color: var(--color-text-maxcontrast);
  margin: 0 0 12px 0;
  line-height: 1.5;
}

.setting-input-row {
  display: flex;
  gap: 12px;
  align-items: center;
}

.setting-input {
  flex: 1;
  padding: 10px 14px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  font-size: 14px;
  background: var(--color-main-background);
  color: var(--color-main-text);
}

.setting-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

/* Integrations tab */
.azure-steps {
  margin: 12px 0 0 0;
  padding-left: 24px;
}

.azure-steps li {
  margin-bottom: 8px;
  line-height: 1.5;
}

.azure-steps code {
  background: var(--color-background-dark);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  word-break: break-all;
}

.api-permissions-list {
  margin: 8px 0;
  padding-left: 20px;
  list-style-type: disc;
}

.api-permissions-list li {
  margin-bottom: 4px;
}

.api-permissions-list code {
  background: var(--color-background-dark);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
}

.azure-steps em {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

.setting-actions {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 20px;
}

.configured-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  background: var(--color-success-hover);
  color: var(--color-success-text, #2d7b43);
  border-radius: 12px;
  font-size: 13px;
  font-weight: 500;
}

.configured-badge::before {
  content: '\2713';
}
</style>
