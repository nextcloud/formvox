import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import Editor from './views/Editor.vue';
import { loadState } from '@nextcloud/initial-state';

const fileId = loadState('formvox', 'fileId');
const initialForm = loadState('formvox', 'form');
const role = loadState('formvox', 'role');
const permissions = loadState('formvox', 'permissions');

const app = createApp(Editor, {
  fileId,
  initialForm,
  role,
  permissions,
});

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-editor');
