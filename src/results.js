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

// Make translation functions globally available. Call sites pass the app id
// first, because that is the only form the Nextcloud
// Transifex extractor can see.
app.config.globalProperties.t = translate;
app.config.globalProperties.n = translatePlural;

app.mount('#formvox-results');
