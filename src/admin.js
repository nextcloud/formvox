import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import AdminSettings from './views/AdminSettings.vue';
import { loadState } from '@nextcloud/initial-state';

// Load initial state with fallback defaults
let branding = {};
let telemetry = { enabled: true, lastReport: null };
let embedSettings = { allowedDomains: '*' };
let msFormsSettings = { clientId: '', tenantId: 'common', isConfigured: false, redirectUri: '' };

try {
  branding = loadState('formvox', 'branding');
} catch (e) {
  console.warn('FormVox: Could not load branding state', e);
}

try {
  telemetry = loadState('formvox', 'telemetry');
} catch (e) {
  console.warn('FormVox: Could not load telemetry state', e);
}

try {
  embedSettings = loadState('formvox', 'embedSettings');
} catch (e) {
  console.warn('FormVox: Could not load embed settings state', e);
}

try {
  msFormsSettings = loadState('formvox', 'msFormsSettings');
} catch (e) {
  console.warn('FormVox: Could not load MS Forms settings state', e);
}

const app = createApp(AdminSettings, {
  initialBranding: branding,
  initialTelemetry: telemetry,
  initialEmbedSettings: embedSettings,
  initialMsFormsSettings: msFormsSettings,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-admin');
