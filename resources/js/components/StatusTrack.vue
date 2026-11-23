<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/i18n';
import { LIFECYCLE, lifecycleStage, statusLabel } from '@/registry';

const props = defineProps<{ status: string | null; compact?: boolean }>();

const stage = computed(() => lifecycleStage(props.status));
const label = computed(() => statusLabel(props.status));

function stepState(index: number): 'done' | 'current' | 'ahead' {
    if (index < stage.value.index) return 'done';
    return index === stage.value.index ? 'current' : 'ahead';
}
</script>

<template>
    <div class="track" :class="[`track--${stage.tone}`, { 'track--compact': compact }]">
        <ol class="track__steps" aria-hidden="true">
            <li v-for="(step, index) in LIFECYCLE" :key="step" class="track__step" :class="`is-${stepState(index)}`">
                <span class="track__dot" />
                <span v-if="!compact" class="track__name">{{ t(step) }}</span>
            </li>
        </ol>
        <p class="track__label">
            <span class="visually-hidden">{{ t('registry.recruitmentStatus') }} </span>{{ label }}
        </p>
    </div>
</template>

<style scoped>
.track {
    --done: var(--mint-text);
    --current: var(--mint);
    min-width: 0;
}

.track--stopped {
    --done: var(--amber);
    --current: var(--amber);
}

.track__steps {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    list-style: none;
    margin: 0;
    padding: 0;
}

.track__step {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    font-size: 0.72rem;
    color: var(--muted);
}

/* The rail between dots. */
.track__step + .track__step::before {
    content: '';
    position: absolute;
    top: 5px;
    right: calc(100% - 6px);
    width: calc(100% - 12px);
    height: 2px;
    background: var(--line);
}

.track__step.is-done + .track__step::before,
.track__step.is-done + .track__step.is-current::before {
    background: var(--done);
}

.track__dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid var(--line);
    background: var(--surface);
}

.is-done .track__dot {
    background: var(--done);
    border-color: var(--done);
}

.is-current .track__dot {
    background: var(--current);
    border-color: var(--current);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--current) 25%, transparent);
}

.is-current .track__name {
    color: var(--text);
    font-weight: 600;
}

.track__label {
    margin: var(--space-2) 0 0;
    font-weight: 600;
    font-size: var(--step--1);
}

.track--stopped .track__label {
    color: var(--amber);
}

.track--unknown .track__label {
    color: var(--muted);
}

.track--compact .track__steps {
    max-width: 120px;
}

.track--compact .track__label {
    margin-top: var(--space-1);
}
</style>
