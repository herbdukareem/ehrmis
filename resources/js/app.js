import '../css/app.css';
import './bootstrap';

import { createApp } from 'vue';
import App from './spa/App.vue';
import router from './spa/router';
import { loadPublicContext } from './spa/stores/app';

loadPublicContext()
    .catch(() => null)
    .finally(() => createApp(App).use(router).mount('#app'));
