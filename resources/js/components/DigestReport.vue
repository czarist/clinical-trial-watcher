<script setup lang="ts">
import type { DigestContent } from '@/api/types';
import { matchLocale, t, tagOf } from '@/i18n';

/**
 * Renders a digest edition from the same stored content the email uses, so the report page
 * and the email always say the same thing.
 */
defineProps<{ content: DigestContent; sampleLabel?: string }>();

/** The edition keeps the language it was written in, whatever the page language is. */
const languageTag = (content: DigestContent): string => tagOf(matchLocale(content.language) ?? 'en');
</script>

<template>
    <article class="digest" :lang="languageTag(content)">
        <p v-if="sampleLabel" class="digest__sample">{{ sampleLabel }}</p>
        <header class="digest__head">
            <h2>{{ content.title }}</h2>
            <p class="digest__period">{{ content.period.label }}</p>
            <p>{{ content.intro }}</p>
        </header>

        <ol class="digest__items">
            <li v-for="item in content.items" :key="`${item.rank}-${item.nct_id}`" class="item">
                <h3>
                    <span class="nct">{{ item.nct_id ?? item.source_id ?? content.labels.missing_fact }}</span>
                    {{ item.title ?? content.labels.missing_fact }}
                </h3>
                <p v-if="item.status" class="item__status">{{ item.status }}</p>
                <p class="item__summary">{{ item.summary }}</p>
                <ul class="item__facts">
                    <li v-for="fact in item.facts" :key="fact">{{ fact }}</li>
                </ul>
                <ul class="item__reasons" :aria-label="content.labels.why_matches ?? t('digests.whyMatches')">
                    <li v-for="reason in item.reasons" :key="reason">{{ reason }}</li>
                </ul>
                <p class="item__meta">
                    {{ content.labels.updated_on.replace(':date', item.update_date_label ?? content.labels.missing_fact) }}
                    <template v-if="item.url">
                        ·
                        <a :href="item.url" target="_blank" rel="noopener noreferrer">{{ content.labels.view_registry }}</a>
                    </template>
                </p>
                <p class="item__source">{{ item.summary_source === 'ai' ? content.labels.source_ai : content.labels.source_factual }}</p>
            </li>
        </ol>

        <footer class="digest__notice">
            <p>{{ content.notice }}</p>
        </footer>
    </article>
</template>

<style scoped>
.digest {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-panel);
    padding: var(--space-6);
    max-width: 78ch;
}

.digest__sample {
    margin: 0 0 var(--space-4);
    padding: var(--space-2) var(--space-3);
    background: var(--amber-wash);
    color: var(--amber);
    border-radius: var(--radius-control);
    font-weight: 700;
}

.digest__head h2 {
    margin: 0;
}

.digest__period {
    color: var(--muted);
    margin: var(--space-1) 0 var(--space-3);
}

.digest__items {
    list-style: none;
    padding: 0;
    margin: var(--space-6) 0 0;
    counter-reset: item;
}

.item {
    border-top: 2px solid var(--ink);
    padding: var(--space-4) 0;
}

.item h3 {
    margin: 0;
    font-size: var(--step-0);
    line-height: 1.35;
}

.item h3 .nct {
    margin-right: var(--space-2);
}

.item__status {
    display: inline-block;
    margin: var(--space-2) 0 0;
    padding: 0 var(--space-2);
    border-radius: var(--radius-control);
    background: var(--mint-wash);
    color: var(--mint-text);
    font-size: var(--step--1);
    font-weight: 700;
}

.item__summary {
    font-size: var(--step-0);
    margin: var(--space-3) 0;
}

.item__facts {
    margin: 0 0 var(--space-3);
    padding-left: var(--space-4);
}

.item__reasons {
    list-style: none;
    margin: 0;
    padding: 0 0 0 var(--space-3);
    border-left: 3px solid var(--mint);
    font-size: var(--step--1);
}

.item__meta,
.item__source {
    color: var(--muted);
    font-size: var(--step--1);
    margin: var(--space-2) 0 0;
}

.digest__notice {
    border-top: 1px solid var(--line);
    padding-top: var(--space-3);
    color: var(--muted);
    font-size: var(--step--1);
}
</style>
