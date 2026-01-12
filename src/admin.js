import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import AdminSettings from './views/AdminSettings.vue';
import { loadState } from '@nextcloud/initial-state';

const branding = loadState('formvox', 'branding');

const app = createApp(AdminSettings, {
  initialBranding: branding,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-admin');
