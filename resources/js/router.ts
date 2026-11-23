import { watch } from 'vue';
import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router';
import { locale, t, type MessageKey } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        auth?: boolean;
        permission?: string;
        title?: MessageKey;
    }
}

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/search' },
    { path: '/login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true, title: 'titles.login' } },
    { path: '/register', component: () => import('@/pages/RegisterPage.vue'), meta: { guest: true, title: 'titles.register' } },
    { path: '/forgot-password', component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { guest: true, title: 'titles.forgotPassword' } },
    { path: '/reset-password', component: () => import('@/pages/ResetPasswordPage.vue'), meta: { guest: true, title: 'titles.resetPassword' } },
    { path: '/search', component: () => import('@/pages/SearchPage.vue'), meta: { auth: true, permission: 'view_clinical_trials', title: 'titles.search' } },
    { path: '/studies/:nctId', component: () => import('@/pages/StudyPage.vue'), meta: { auth: true, permission: 'view_clinical_trials' }, props: true },
    { path: '/alerts', component: () => import('@/pages/AlertsPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'titles.alerts' } },
    { path: '/alerts/new', component: () => import('@/pages/AlertEditorPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'titles.newAlert' } },
    { path: '/alerts/:id', component: () => import('@/pages/AlertEditorPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'titles.alert' }, props: true },
    { path: '/digests/samples', component: () => import('@/pages/DigestSamplesPage.vue'), meta: { title: 'titles.digestSamples' } },
    { path: '/digests', component: () => import('@/pages/DigestsPage.vue'), meta: { auth: true, title: 'titles.digests' } },
    { path: '/digests/:id', component: () => import('@/pages/DigestPage.vue'), meta: { auth: true, title: 'titles.digest' }, props: true },
    { path: '/teams', component: () => import('@/pages/TeamsPage.vue'), meta: { auth: true, title: 'titles.teams' } },
    { path: '/teams/:id', component: () => import('@/pages/TeamPage.vue'), meta: { auth: true, title: 'titles.team' }, props: true },
    { path: '/account', component: () => import('@/pages/AccountPage.vue'), meta: { auth: true, title: 'titles.account' } },
    {
        path: '/admin',
        component: () => import('@/pages/admin/AdminLayout.vue'),
        meta: { auth: true },
        children: [
            { path: '', redirect: '/admin/users' },
            { path: 'users', component: () => import('@/pages/admin/AdminUsersPage.vue'), meta: { permission: 'manage_users', title: 'titles.adminUsers' } },
            { path: 'plans', component: () => import('@/pages/admin/AdminPlansPage.vue'), meta: { permission: 'manage_plans', title: 'titles.adminPlans' } },
            { path: 'roles', component: () => import('@/pages/admin/AdminRolesPage.vue'), meta: { permission: 'manage_roles', title: 'titles.adminRoles' } },
            { path: 'imports', component: () => import('@/pages/admin/AdminImportsPage.vue'), meta: { permission: 'manage_imports', title: 'titles.adminImports' } },
        ],
    },
    { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'titles.notFound' } },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) await auth.load();

    if (to.meta.guest && auth.isLoggedIn) return '/search';
    if (to.matched.some((record) => record.meta.auth) && !auth.isLoggedIn) {
        return { path: '/login', query: { redirect: to.fullPath } };
    }
    if (to.meta.permission && !auth.can(to.meta.permission)) return '/account';

    return true;
});

function setTitle(to: RouteLocationNormalized): void {
    document.title = to.meta.title ? t('titles.suffix', { title: t(to.meta.title) }) : t('app.name');
}

router.afterEach(setTitle);

// Pages that set their own title (a study) do so again when they load; others follow the language.
watch(locale, () => setTitle(router.currentRoute.value));
