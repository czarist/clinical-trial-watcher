import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { router } from '@/router';
import { whenUnauthorized } from '@/api/client';
import { detectLocale, setLocale } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(router);

whenUnauthorized(() => {
    useAuthStore().clear();
    void router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } });
});

// Load the chosen language before the first render, so no English text flashes first.
void setLocale(detectLocale()).finally(() => app.mount('#app'));
