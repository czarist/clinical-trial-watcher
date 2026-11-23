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

const segment = computed(() => (state: 'done' | 'current' | 'ahead'): string => {
    const stopped = stage.value.tone === 'stopped';
    if (state === 'ahead' || stage.value.tone === 'unknown') return 'bg-line';
    if (state === 'current') return stopped ? 'bg-amber' : 'bg-mint';
    return stopped ? 'bg-amber/60' : 'bg-mint-text';
});

const pill = computed(() => {
    if (stage.value.tone === 'stopped') return 'bg-amber-wash text-amber';
    if (stage.value.tone === 'unknown') return 'bg-surface-raised text-muted';
    return 'bg-mint-wash text-mint-text';
});
</script>

<template>
    <div class="track min-w-0" :class="[`track--${stage.tone}`, { 'track--compact': compact }]">
        <!-- The stage names are in the label below; the rail is a picture of the same fact. -->
        <ol class="track__steps m-0 grid list-none grid-cols-4 gap-1 p-0" :class="compact ? 'max-w-28' : 'max-w-56'" aria-hidden="true">
            <li v-for="(step, index) in LIFECYCLE" :key="step" class="track__step" :class="`is-${stepState(index)}`" :title="t(step)">
                <span class="block h-1.5 rounded-full" :class="segment(stepState(index))" />
                <span v-if="!compact" class="track__name visually-hidden">{{ t(step) }}</span>
            </li>
        </ol>
        <p class="track__label m-0 mt-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[0.8rem] font-semibold" :class="pill">
            <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
            <span class="visually-hidden">{{ t('registry.recruitmentStatus') }} </span>{{ label }}
        </p>
    </div>
</template>
