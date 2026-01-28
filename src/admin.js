import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import AdminSettings from './views/AdminSettings.vue';
import { loadState } from '@nextcloud/initial-state';

// Load initial state with fallback defaults
let branding = {};
let statistics = { totalForms: 0, totalResponses: 0, activeUsers30d: 0 };
let telemetry = { enabled: true, lastReport: null };
let embedSettings = { allowedDomains: '*' };

try {
  branding = loadState('formvox', 'branding');
} catch (e) {
  console.warn('FormVox: Could not load branding state', e);
}

try {
  statistics = loadState('formvox', 'statistics');
} catch (e) {
  console.warn('FormVox: Could not load statistics state', e);
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

const app = createApp(AdminSettings, {
  initialBranding: branding,
  initialStatistics: statistics,
  initialTelemetry: telemetry,
  initialEmbedSettings: embedSettings,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-admin');
