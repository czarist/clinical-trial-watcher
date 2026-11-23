import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { router } from '@/router';
import { whenUnauthorized } from '@/api/client';
import { useAuthStore } from '@/stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(router);

whenUnauthorized(() => {
    useAuthStore().clear();
    void router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } });
});

app.mount('#app');
