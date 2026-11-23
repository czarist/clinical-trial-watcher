<script setup lang="ts">
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const sections = [
    { to: '/admin/users', label: 'Users', permission: 'manage_users' },
    { to: '/admin/plans', label: 'Plans', permission: 'manage_plans' },
    { to: '/admin/roles', label: 'Roles', permission: 'manage_roles' },
    { to: '/admin/imports', label: 'Imports', permission: 'manage_imports' },
];
</script>

<template>
    <div class="page admin">
        <h1>Administration</h1>
        <nav class="admin__nav" aria-label="Administration">
            <template v-for="section in sections" :key="section.to">
                <RouterLink v-if="auth.can(section.permission)" :to="section.to">{{ section.label }}</RouterLink>
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
