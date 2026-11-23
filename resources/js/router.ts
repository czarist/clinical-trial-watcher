import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        auth?: boolean;
        permission?: string;
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/search' },
    { path: '/login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true, title: 'Log in' } },
    { path: '/register', component: () => import('@/pages/RegisterPage.vue'), meta: { guest: true, title: 'Create account' } },
    { path: '/forgot-password', component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { guest: true, title: 'Reset password' } },
    { path: '/reset-password', component: () => import('@/pages/ResetPasswordPage.vue'), meta: { guest: true, title: 'Choose a new password' } },
    { path: '/search', component: () => import('@/pages/SearchPage.vue'), meta: { auth: true, permission: 'view_clinical_trials', title: 'Search studies' } },
    { path: '/studies/:nctId', component: () => import('@/pages/StudyPage.vue'), meta: { auth: true, permission: 'view_clinical_trials' }, props: true },
    { path: '/alerts', component: () => import('@/pages/AlertsPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'Alerts' } },
    { path: '/alerts/new', component: () => import('@/pages/AlertEditorPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'New alert' } },
    { path: '/alerts/:id', component: () => import('@/pages/AlertEditorPage.vue'), meta: { auth: true, permission: 'create_alerts', title: 'Alert' }, props: true },
    { path: '/digests/samples', component: () => import('@/pages/DigestSamplesPage.vue'), meta: { title: 'Sample AI digests' } },
    { path: '/digests', component: () => import('@/pages/DigestsPage.vue'), meta: { auth: true, title: 'AI digests' } },
    { path: '/digests/:id', component: () => import('@/pages/DigestPage.vue'), meta: { auth: true, title: 'AI digest' }, props: true },
    { path: '/teams', component: () => import('@/pages/TeamsPage.vue'), meta: { auth: true, title: 'Teams' } },
    { path: '/teams/:id', component: () => import('@/pages/TeamPage.vue'), meta: { auth: true, title: 'Team' }, props: true },
    { path: '/account', component: () => import('@/pages/AccountPage.vue'), meta: { auth: true, title: 'Account and plan' } },
    {
        path: '/admin',
        component: () => import('@/pages/admin/AdminLayout.vue'),
        meta: { auth: true },
        children: [
            { path: '', redirect: '/admin/users' },
            { path: 'users', component: () => import('@/pages/admin/AdminUsersPage.vue'), meta: { permission: 'manage_users', title: 'Users' } },
            { path: 'plans', component: () => import('@/pages/admin/AdminPlansPage.vue'), meta: { permission: 'manage_plans', title: 'Plans' } },
            { path: 'roles', component: () => import('@/pages/admin/AdminRolesPage.vue'), meta: { permission: 'manage_roles', title: 'Roles' } },
            { path: 'imports', component: () => import('@/pages/admin/AdminImportsPage.vue'), meta: { permission: 'manage_imports', title: 'Imports' } },
        ],
    },
    { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Page not found' } },
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

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} — Clinical Trial Watcher` : 'Clinical Trial Watcher';
});
