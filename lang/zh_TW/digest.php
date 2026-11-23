<?php

declare(strict_types=1);

// Traditional Chinese (Taiwan) wording of digests. Factual registry language only: no
// eligibility, treatment or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => '進行中，未招募',
        'COMPLETED' => '已完成',
        'ENROLLING_BY_INVITATION' => '僅限受邀者參加',
        'NOT_YET_RECRUITING' => '尚未招募',
        'RECRUITING' => '招募中',
        'SUSPENDED' => '已暫停',
        'TERMINATED' => '已終止',
        'WITHDRAWN' => '已撤回',
        'AVAILABLE' => '可提供',
        'NO_LONGER_AVAILABLE' => '不再提供',
        'TEMPORARILY_NOT_AVAILABLE' => '暫時無法提供',
        'APPROVED_FOR_MARKETING' => '已核准上市',
        'WITHHELD' => '未公開',
        'UNKNOWN' => '狀態不明',
        'none' => '無狀態',
    ],
    'dates' => [
        'start_date' => '開始日期',
        'primary_completion_date' => '主要完成日期',
        'completion_date' => '研究完成日期',
    ],
    'date_type' => [
        'ACTUAL' => '實際',
        'ESTIMATED' => '預估',
    ],
    'months' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
    'date_month' => ':year年:month',
    'date_day' => ':year年:month:day日',
    'not_set' => '未設定',

    'fact' => [
        'created' => '新登錄的研究，狀態為「:status」。',
        'created_no_status' => '新登錄的研究。',
        'status' => '登錄狀態由「:before」變更為「:after」。',
        'site_added' => '新增試驗地點：:site（:status）。',
        'site_added_no_status' => '新增試驗地點：:site。',
        'site_removed' => '移除試驗地點：:site。',
        'site_updated' => '試驗地點 :site 由「:before」變更為「:after」。',
        'date' => '「:name」由 :before 變更為 :after。',
        'results' => '已發布結果。',
        'updated' => '登錄資料已更新。',
        'more_sites' => '另有 :count 項試驗地點變更。',
    ],

    'reason' => [
        'now_recruiting' => '已開放招募：狀態變更為「招募中」。',
        'status_changed' => '整體狀態已變更。',
        'status_in_criteria' => '新狀態「:status」是此已儲存搜尋追蹤的狀態之一。',
        'site_in_region' => '位於 :place 的招募中試驗地點，屬於此已儲存搜尋監看的地區。',
        'site_added' => '列出新的試驗地點。',
        'site_removed' => '有一個試驗地點被移除。',
        'results_posted' => '登錄資料庫已發布結果。',
        'date_changed' => '重要日期已變更。',
        'new_study' => '符合此已儲存搜尋的新登錄研究。',
        'updated' => '登錄資料已更新。',
        'matches' => '符合此已儲存搜尋：:criteria。',
    ],

    'criteria' => [
        'q' => '文字「:value」',
        'condition' => '疾病或狀況「:value」',
        'keyword' => '關鍵字「:value」',
        'mesh_term' => 'MeSH 詞彙「:value」',
        'sponsor' => '贊助者「:value」',
        'country' => '國家 :value',
        'city' => '城市 :value',
        'status' => '狀態 :value',
        'phase' => '分期 :value',
    ],

    'subject' => 'AI 摘要：:name（:period）',
    'subject_fallback' => 'AI 摘要',
    'title' => '「:name」的 AI 摘要',
    'period' => ':start 至 :end',
    'intro' => ':start 至 :end 期間有 :count 項符合的研究更新。',
    'intro_truncated' => ':start 至 :end 期間有 :count 項符合的研究更新。以下說明最相關的 :shown 項。',
    'source_ai' => '摘要由 AI 根據所列的登錄資料事實撰寫。',
    'source_factual' => '摘要根據記錄的登錄資料變更產生。',
    'updated_on' => '登錄資料更新發布於 :date',
    'view_registry' => '在 ClinicalTrials.gov 上查看',
    'view_report' => '開啟報告',
    'why_matches' => '符合原因',
    'notice' => '本摘要描述 ClinicalTrials.gov 上的公開登錄資料，並非醫療建議，不評估任何研究的參與資格，也不解讀研究結果。請在連結的登錄頁面上查證每項事實。',
    'missing_fact' => '登錄資料庫未提供此資訊。',
    'manage' => '您收到此郵件，是因為此已儲存搜尋已開啟 AI 摘要。您可以在已儲存搜尋的設定中關閉摘要；基本提醒將繼續運作。',
];
