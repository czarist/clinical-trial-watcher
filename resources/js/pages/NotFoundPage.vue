<script setup lang="ts">
import { t } from '@/i18n';
</script>

<template>
    <div class="page narrow">
        <h1>{{ t('notFound.title') }}</h1>
        <p>{{ t('notFound.body') }}</p>
        <RouterLink class="button" to="/search">{{ t('notFound.action') }}</RouterLink>
    </div>
</template>
