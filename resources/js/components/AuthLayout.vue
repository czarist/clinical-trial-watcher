<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div class="auth">
        <section class="auth__intro">
            <img src="/logo.svg" alt="" width="56" height="56" />
            <p class="auth__name">Clinical Trial Watcher</p>
            <p class="auth__pitch">Search ClinicalTrials.gov and get an email when studies you follow open, change or start recruiting.</p>
        </section>
        <section class="auth__form panel">
            <h1>{{ title }}</h1>
            <slot />
        </section>
    </div>
</template>

<style scoped>
.auth {
    min-height: 100vh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 440px);
    gap: var(--space-12);
    align-items: center;
    max-width: 1000px;
    margin: 0 auto;
    padding: var(--space-8) var(--space-4);
}

.auth__intro img {
    border-radius: 14px;
}

.auth__name {
    font-weight: 800;
    font-size: var(--step-2);
    margin: var(--space-4) 0 var(--space-2);
}

.auth__pitch {
    font-size: var(--step-1);
    color: var(--muted);
    max-width: 34ch;
}

@media (max-width: 760px) {
    .auth {
        grid-template-columns: 1fr;
        gap: var(--space-6);
        min-height: auto;
    }
}
</style>
