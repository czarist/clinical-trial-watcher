<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function logOut(): Promise<void> {
    await auth.logout();
    await router.push('/login');
}
</script>

<template>
    <header class="header">
        <nav class="header__inner" aria-label="Main">
            <RouterLink to="/search" class="brand">
                <img src="/logo.svg" alt="" width="32" height="32" />
                <span>Clinical Trial Watcher</span>
            </RouterLink>
            <ul class="links">
                <li v-if="auth.can('view_clinical_trials')"><RouterLink to="/search">Search</RouterLink></li>
                <li v-if="auth.can('create_alerts')"><RouterLink to="/alerts">Alerts</RouterLink></li>
                <li><RouterLink to="/digests">AI digests</RouterLink></li>
                <li><RouterLink to="/teams">Teams</RouterLink></li>
                <li><RouterLink to="/account">Account</RouterLink></li>
                <li v-if="auth.isAdmin"><RouterLink to="/admin">Admin</RouterLink></li>
            </ul>
            <button class="logout" type="button" @click="logOut">Log out</button>
        </nav>
        <p v-if="auth.user && !auth.user.email_verified" class="verify">
            Confirm your email address to create alerts and manage your plan.
            <RouterLink to="/account">Send the link again</RouterLink>
        </p>
    </header>
</template>

<style scoped>
.header {
    background: var(--ink);
    color: #fff;
}

.header__inner {
    max-width: 1180px;
    margin: 0 auto;
    padding: var(--space-3) var(--space-4);
    display: flex;
    align-items: center;
    gap: var(--space-6);
    flex-wrap: wrap;
}

.brand {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    color: #fff;
    font-weight: 800;
    text-decoration: none;
}

.brand img {
    border-radius: 8px;
}

.links {
    display: flex;
    gap: var(--space-4);
    list-style: none;
    margin: 0;
    padding: 0;
    flex: 1;
}

.links a {
    color: #c9d4e6;
    text-decoration: none;
    padding: var(--space-1) 0;
    border-bottom: 2px solid transparent;
}

.links a.router-link-active {
    color: #fff;
    border-bottom-color: var(--mint);
}

.logout {
    font: inherit;
    background: none;
    border: 1px solid #33456a;
    color: #c9d4e6;
    border-radius: var(--radius-control);
    padding: var(--space-1) var(--space-3);
    cursor: pointer;
}

.verify {
    margin: 0 auto;
    max-width: none;
    background: var(--mint);
    color: var(--ink);
    text-align: center;
    padding: var(--space-2) var(--space-4);
    font-size: var(--step--1);
}

.verify a {
    color: var(--ink);
    font-weight: 600;
}
</style>
