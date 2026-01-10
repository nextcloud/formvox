import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import Respond from './views/Respond.vue';
import { loadState } from '@nextcloud/initial-state';

const fileId = loadState('formvox', 'fileId');
const form = loadState('formvox', 'form');

const app = createApp(Respond, {
  fileId,
  form,
  isPublic: false,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-respond');
