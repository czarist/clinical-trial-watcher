<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '@/api/client';
import type { RegistryAccess, RegistryAccessGuide, RegistryAccessStatus } from '@/api/types';
import { formatDate, t, type MessageKey } from '@/i18n';

const guide = ref<RegistryAccessGuide | null>(null);
const failed = ref(false);

/** Groups in the order an operator acts on them; registries with nothing to do come last. */
const order: RegistryAccessStatus[] = ['needs_permission', 'needs_key', 'paid_use_unconfirmed', 'confirmation_advised', 'active'];

const steps: Record<RegistryAccessStatus, MessageKey[]> = {
    needs_permission: ['admin.access.steps.askPermission', 'admin.access.steps.keepAnswer', 'admin.access.steps.setSettings', 'admin.access.steps.firstImport'],
    needs_key: ['admin.access.steps.getKey', 'admin.access.steps.setSettings', 'admin.access.steps.firstImport'],
    paid_use_unconfirmed: ['admin.access.steps.askPaidUse', 'admin.access.steps.enablePaidUse'],
    confirmation_advised: ['admin.access.steps.askConfirmation'],
    active: [],
};

const groups = computed(() =>
    order
        .map((status) => ({ status, sources: guide.value?.data.filter((source) => source.status === status) ?? [] }))
        .filter((group) => group.sources.length > 0),
);

function contactLabel(contact: string | null): string {
    return contact === null ? '—' : contact.replace(/^mailto:/, '');
}

function firstImport(source: RegistryAccess): string {
    return `php artisan clinicaltrials:fetch --source=${source.key} --max-pages=1`;
}

onMounted(async () => {
    try {
        guide.value = await api.get<RegistryAccessGuide>('/admin/registries');
    } catch {
        failed.value = true;
    }
});
</script>

<template>
    <section class="access">
        <p v-if="failed" class="notice error" role="alert">{{ t('admin.access.loadFailed') }}</p>
        <template v-if="guide">
            <p class="access__intro">{{ t('admin.access.intro', { file: guide.drafts }) }}</p>

            <section v-for="group in groups" :key="group.status" class="access__group" :aria-labelledby="`group-${group.status}`">
                <h2 :id="`group-${group.status}`">{{ t(`admin.access.groups.${group.status}`) }} <span class="muted">({{ group.sources.length }})</span></h2>
                <ol v-if="steps[group.status].length" class="access__steps">
                    <li v-for="step in steps[group.status]" :key="step">{{ t(step) }}</li>
                </ol>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ t('admin.access.registry') }}</th>
                                <th>{{ t('admin.access.contactColumn') }}</th>
                                <th>{{ t('admin.access.settingsColumn') }}</th>
                                <th>{{ t('admin.access.licence') }}</th>
                                <th>{{ t('admin.access.lastRun') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="source in group.sources" :key="source.key">
                                <td>
                                    <strong>{{ source.name }}</strong>
                                    <p class="muted"><code>{{ source.key }}</code> · {{ t(`admin.access.methods.${source.method}`) }}</p>
                                    <p v-if="!source.commercial_use" class="tag">{{ t('admin.access.notInDigests') }}</p>
                                </td>
                                <td>
                                    <a v-if="source.contact" :href="source.contact" target="_blank" rel="noopener">{{ contactLabel(source.contact) }}</a>
                                    <span v-else>—</span>
                                </td>
                                <td>
                                    <ul v-if="source.settings.length" class="access__settings">
                                        <li v-for="setting in source.settings" :key="setting.name">
                                            <code>{{ setting.name }}</code>
                                            <span :class="setting.is_set ? 'ok' : 'missing'">{{ t(setting.is_set ? 'admin.access.settingSet' : 'admin.access.settingMissing') }}</span>
                                        </li>
                                    </ul>
                                    <span v-else>—</span>
                                    <p v-if="group.status !== 'active'" class="muted access__command"><code>{{ firstImport(source) }}</code></p>
                                </td>
                                <td>
                                    {{ source.licence }}
                                    <p><a :href="source.terms_url" target="_blank" rel="noopener">{{ t('admin.access.terms') }}</a> · {{ t('admin.access.reviewedOn', { date: formatDate(source.reviewed_on) }) }}</p>
                                </td>
                                <td>
                                    <template v-if="source.last_run">
                                        <span :class="`status-${source.last_run.status}`">{{ t(`admin.runStatus.${source.last_run.status}`) }}</span>
                                        <p v-if="source.last_run.finished_at" class="muted">{{ formatDate(source.last_run.finished_at) }}</p>
                                    </template>
                                    <span v-else class="muted">{{ t('admin.access.neverImported') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="access__group" aria-labelledby="group-unavailable">
                <h2 id="group-unavailable">{{ t('admin.access.unavailableTitle') }} <span class="muted">({{ guide.unavailable.length }})</span></h2>
                <p class="muted">{{ t('admin.access.unavailableIntro') }}</p>
                <ul class="access__unavailable">
                    <li v-for="item in guide.unavailable" :key="item.name">
                        <strong>{{ item.name }}</strong>: {{ t(`admin.access.reasons.${item.reason}`) }}
                        <a v-if="item.contact" :href="item.contact" target="_blank" rel="noopener">{{ contactLabel(item.contact) }}</a>
                    </li>
                </ul>
            </section>
        </template>
    </section>
</template>

<style scoped>
.access__intro {
    max-width: 72ch;
}

.access__group {
    margin-top: var(--space-6);
}

.access__steps {
    max-width: 72ch;
    margin-bottom: var(--space-3);
}

.access__steps li + li {
    margin-top: var(--space-1);
}

.access__settings {
    list-style: none;
    padding: 0;
    margin: 0;
}

.access__command {
    margin-top: var(--space-1);
}

.access__unavailable li + li {
    margin-top: var(--space-2);
}

.tag {
    display: inline-block;
    margin-top: var(--space-1);
    padding: 0 var(--space-2);
    border: 1px solid var(--line);
    border-radius: 999px;
    font-size: 0.85em;
}

.ok {
    color: var(--mint-text);
    margin-left: var(--space-2);
}

.missing {
    color: var(--danger);
    margin-left: var(--space-2);
}

.status-failed {
    color: var(--danger);
}

.status-partial {
    color: var(--amber);
}
</style>
