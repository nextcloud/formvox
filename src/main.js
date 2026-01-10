import { createApp } from 'vue';
import { translate, translatePlural } from '@nextcloud/l10n';
import '@nextcloud/dialogs/style.css';
import App from './views/App.vue';

const app = createApp(App);

// Make translation functions globally available
app.config.globalProperties.t = (text, vars = {}) => translate('formvox', text, vars);
app.config.globalProperties.n = (singular, plural, count, vars = {}) => translatePlural('formvox', singular, plural, count, vars);

app.mount('#formvox-app');
