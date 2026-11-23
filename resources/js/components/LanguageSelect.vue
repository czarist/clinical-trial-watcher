<script setup lang="ts">
import { ref } from 'vue';
import { LOCALES, locale, t, type LocaleCode } from '@/i18n';
import { useAuthStore } from '@/stores/auth';

defineProps<{ dark?: boolean }>();
const emit = defineEmits<{ changed: [] }>();

const auth = useAuthStore();
const busy = ref(false);
const id = `language-${Math.random().toString(36).slice(2, 8)}`;

async function change(event: Event): Promise<void> {
    busy.value = true;
    try {
        await auth.changeLocale((event.target as HTMLSelectElement).value as LocaleCode);
        emit('changed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="language" :class="{ 'language--dark': dark }">
        <label :for="id" class="visually-hidden">{{ t('language.label') }}</label>
        <select :id="id" :value="locale" :disabled="busy" @change="change">
            <option v-for="item in LOCALES" :key="item.code" :value="item.code" :lang="item.tag">{{ item.name }}</option>
        </select>
    </div>
</template>

<style scoped>
.language select {
    width: auto;
    padding: var(--space-1) var(--space-2);
    font-size: var(--step--1);
}

.language--dark select {
    background: transparent;
    color: #c9d4e6;
    border-color: #33456a;
}

.language--dark option {
    color: var(--ink);
}
</style>
