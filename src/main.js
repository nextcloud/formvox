import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import { loadState } from '@nextcloud/initial-state';
import '@nextcloud/dialogs/style.css';
import App from './views/App.vue';

// Load initial state
let msFormsConfigured = false;
try {
  msFormsConfigured = loadState('formvox', 'msFormsConfigured', false);
} catch (e) {
  // State not available
}

const app = createApp(App, {
  msFormsConfigured,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-app');
