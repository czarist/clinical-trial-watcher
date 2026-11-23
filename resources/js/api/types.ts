export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        disclaimer?: string;
        alerts_limit?: number;
        ai_digests_available?: boolean;
    };
}

/** A plan; prices are USD per month. */
export interface Plan {
    id: number;
    name: string;
    price: string;
    currency: string;
    billing_cycle: string;
    price_label: string;
    trial_days: number;
    alerts_limit: number;
    ai_digests: boolean;
    team_workspace: boolean;
    is_free: boolean;
    is_legacy: boolean;
    is_purchasable: boolean;
    stripe_price_id?: string | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    /** Laravel locale code, e.g. "pt_BR". */
    locale: string;
    trial_ends_at: string | null;
    plan: Plan | null;
    roles: { id: number; name: string }[];
    permissions: string[];
    created_at: string;
}

export interface BillingSummary {
    effective_plan: { id: number; name: string; price: string; currency: string; is_legacy: boolean } | null;
    features: { ai_digests: boolean; team_workspace: boolean };
    saved_search_limit: number;
    alerts_limit: number;
    alerts_count: number;
    on_trial: boolean;
    trial_ends_at: string | null;
    subscription: { status: string; plan_id: number | null; on_grace_period: boolean; ends_at: string | null } | null;
}

export interface TrialSummary {
    nct_id: string | null;
    /** Registry the record comes from, e.g. "clinicaltrials_gov", and its identifier there. */
    source: string;
    source_id: string;
    registry: string;
    brief_title: string;
    acronym: string | null;
    overall_status: string | null;
    phases: string[];
    study_type: string | null;
    sponsor_name: string | null;
    start_date: string | null;
    primary_completion_date: string | null;
    last_update_post_date: string | null;
    has_results: boolean;
    conditions?: string[];
    locations_count?: number;
    registry_url: string | null;
    attribution: string | null;
}

/** Another record of the same study, in this or another registry. */
export interface Registration {
    source: string;
    source_id: string;
    registry: string;
    registry_url: string | null;
    nct_id: string | null;
}

export interface TrialDetail extends TrialSummary {
    also_registered_as?: Registration[];
    duplicate_of?: Registration | null;
    official_title: string | null;
    brief_summary: string | null;
    detailed_description: string | null;
    completion_date: string | null;
    study_first_post_date: string | null;
    enrollment: { count: number | null; type: string | null };
    eligibility: { criteria: string | null; min_age: string | null; max_age: string | null; sex: string | null };
    keywords: string[];
    mesh_terms: { mesh_id: string | null; term: string; is_primary: boolean }[];
    locations: { facility: string | null; city: string | null; state: string | null; country: string | null }[];
    interventions: { type: string | null; name: string; description: string | null }[];
    arm_groups: { label: string; type: string | null; description: string | null }[];
    outcomes: { type: string; measure: string; description: string | null; time_frame: string | null }[];
    references: { pmid: string | null; type: string | null; citation: string | null; url: string | null; pubmed_url: string | null }[];
    collaborators: { name: string; class: string | null }[];
    secondary_ids: { secondary_id: string; type: string | null }[];
    status_history: { previous_status: string | null; new_status: string | null; detected_at: string }[];
}

/** Search filters with the API's snake_case keys. */
export interface SearchCriteria {
    q?: string;
    condition?: string;
    keyword?: string;
    mesh_term?: string;
    sponsor?: string;
    country?: string;
    city?: string;
    status?: string[];
    phase?: string[];
    study_type?: string[];
    start_date_from?: string;
    start_date_to?: string;
    last_update_from?: string;
    last_update_to?: string;
    latitude?: number;
    longitude?: number;
    radius_km?: number;
}

export type AlertFrequency = 'instant' | 'daily' | 'weekly';
export type DigestFrequency = 'daily' | 'weekly';
export type DigestLanguage = 'en' | 'pt_BR' | 'es' | 'zh_CN' | 'zh_TW' | 'ja' | 'ko';

export interface AiDigestSettings {
    enabled: boolean;
    frequency: DigestFrequency;
    language: DigestLanguage;
}

export interface Alert {
    id: number;
    team_id: number | null;
    name: string;
    criteria: SearchCriteria;
    frequency: AlertFrequency;
    notify_status_changes: boolean;
    is_active: boolean;
    ai_digest: AiDigestSettings;
    pending_matches_count?: number;
    last_notified_at: string | null;
    created_at: string;
}

export interface AlertMatch {
    id: number;
    reason: 'new_study' | 'updated_study' | 'status_change';
    previous_status: string | null;
    new_status: string | null;
    notified_at: string | null;
    matched_at: string;
    trial: TrialSummary | null;
}

export interface Role {
    id: number;
    name: string;
    description: string | null;
    permissions: { id: number; name: string }[];
}

export interface ImportRun {
    id: number;
    status: 'running' | 'succeeded' | 'partial' | 'failed';
    updated_since: string | null;
    pages: number;
    created: number;
    updated: number;
    unchanged: number;
    failed: number;
    error: string | null;
    started_at: string;
    finished_at: string | null;
}

export interface TeamMemberSummary {
    id: number;
    name: string;
    /** Shown to the team owner only. */
    email: string | null;
    role: 'owner' | 'member';
}

export interface TeamInvitation {
    id: number;
    email: string;
    team?: { id: number; name: string; owner_name: string | null };
    created_at: string;
}

export interface Team {
    id: number;
    name: string;
    owner: { id: number; name: string };
    is_owner: boolean;
    is_active: boolean;
    receives_digests: boolean | null;
    members?: TeamMemberSummary[];
    invitations?: TeamInvitation[];
}

export interface DigestItem {
    rank: number;
    nct_id: string | null;
    source_id?: string | null;
    registry?: string | null;
    title: string | null;
    /** Built by the server from a validated NCT number; null in samples. */
    url: string | null;
    update_date_label: string | null;
    status: string | null;
    summary: string;
    summary_source: 'ai' | 'factual';
    facts: string[];
    reasons: string[];
}

/** One digest edition, shared by the email and the report page. */
export interface DigestContent {
    name: string;
    language: DigestLanguage;
    title: string;
    period: { start: string; end: string; label: string };
    total_updates: number;
    shown_updates: number;
    truncated: boolean;
    intro: string;
    items: DigestItem[];
    labels: { source_ai: string; source_factual: string; view_registry: string; updated_on: string; missing_fact: string; why_matches?: string };
    notice: string;
    manage: string;
}

export interface DigestSample extends DigestContent {
    id: string;
    sample: true;
    plan: string;
    sample_label: string;
}

export interface Digest {
    id: number;
    alert_id: number;
    team_id: number | null;
    name: string | null;
    frequency: DigestFrequency;
    language: DigestLanguage;
    period_start: string;
    period_end: string;
    total_updates: number;
    shown_updates: number;
    generation: 'ai' | 'partial' | 'fallback' | null;
    generated_at: string | null;
    content?: DigestContent;
}
