<script setup lang="ts">
import { t, type MessageKey } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const sections: { to: string; label: MessageKey; permission: string; icon: string }[] = [
    { to: '/admin/users', label: 'admin.users', permission: 'manage_users', icon: 'fa-users-gear' },
    { to: '/admin/plans', label: 'admin.plans', permission: 'manage_plans', icon: 'fa-tags' },
    { to: '/admin/roles', label: 'admin.roles', permission: 'manage_roles', icon: 'fa-user-shield' },
    { to: '/admin/imports', label: 'admin.imports', permission: 'manage_imports', icon: 'fa-cloud-arrow-down' },
    { to: '/admin/registries', label: 'admin.registries', permission: 'manage_imports', icon: 'fa-key' },
];
</script>

<template>
    <div class="page admin">
        <h1>{{ t('admin.title') }}</h1>
        <nav class="admin__nav" :aria-label="t('admin.title')">
            <template v-for="section in sections" :key="section.to">
                <RouterLink v-if="auth.can(section.permission)" :to="section.to"><i class="fas mr-1.5" :class="section.icon" aria-hidden="true" />{{ t(section.label) }}</RouterLink>
            </template>
        </nav>
        <RouterView />
    </div>
</template>

<style scoped>
.admin__nav {
    display: flex;
    gap: var(--space-2);
    margin-bottom: var(--space-6);
    border-bottom: 1px solid var(--line);
}

.admin__nav a {
    padding: var(--space-2) var(--space-3);
    text-decoration: none;
    color: var(--muted);
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
}

.admin__nav a.router-link-active {
    color: var(--text);
    font-weight: 600;
    border-bottom-color: var(--mint-text);
}
</style>
