import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import Results from './views/Results.vue';
import { loadState } from '@nextcloud/initial-state';

const fileId = loadState('formvox', 'fileId');
const form = loadState('formvox', 'form');
const role = loadState('formvox', 'role');
const permissions = loadState('formvox', 'permissions');

const app = createApp(Results, {
  fileId,
  form,
  role,
  permissions,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-results');
