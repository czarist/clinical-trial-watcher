<?php

declare(strict_types=1);

// Wording of digests in Japanese. Factual registry language only: no eligibility, treatment
// or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => '実施中（募集終了）',
        'COMPLETED' => '完了',
        'ENROLLING_BY_INVITATION' => '招待による登録中',
        'NOT_YET_RECRUITING' => '募集前',
        'RECRUITING' => '募集中',
        'SUSPENDED' => '一時中断',
        'TERMINATED' => '中止',
        'WITHDRAWN' => '取り下げ',
        'AVAILABLE' => '利用可能',
        'NO_LONGER_AVAILABLE' => '利用終了',
        'TEMPORARILY_NOT_AVAILABLE' => '一時的に利用不可',
        'APPROVED_FOR_MARKETING' => '販売承認済み',
        'WITHHELD' => '非公開',
        'UNKNOWN' => 'ステータス不明',
        'none' => 'ステータスなし',
    ],
    'dates' => [
        'start_date' => '開始日',
        'primary_completion_date' => '主要完了日',
        'completion_date' => '試験完了日',
    ],
    'date_type' => [
        'ACTUAL' => '実績',
        'ESTIMATED' => '予定',
    ],
    'months' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
    'date_month' => ':year年:month',
    'date_day' => ':year年:month:day日',
    'not_set' => '未設定',

    'fact' => [
        'created' => '新しい試験が「:status」のステータスで登録されました。',
        'created_no_status' => '新しい試験が登録されました。',
        'status' => 'レジストリの登録内容が :before から :after に変わりました。',
        'site_added' => '実施施設が追加されました：:site（:status）。',
        'site_added_no_status' => '実施施設が追加されました：:site。',
        'site_removed' => '実施施設が削除されました：:site。',
        'site_updated' => '実施施設 :site が :before から :after に変わりました。',
        'date' => ':name が :before から :after に変わりました。',
        'results' => '結果が掲載されました。',
        'updated' => 'レジストリの登録内容が更新されました。',
        'more_sites' => 'ほかに実施施設の変更が :count件あります。',
    ],

    'reason' => [
        'now_recruiting' => '募集が開始されました：ステータスが「募集中」に変わりました。',
        'status_changed' => '全体のステータスが変わりました。',
        'status_in_criteria' => '新しいステータス「:status」は、この保存済み検索がフォローしているステータスです。',
        'site_in_region' => ':place の募集中の実施施設です。この保存済み検索が監視している地域です。',
        'site_added' => '新しい実施施設が掲載されました。',
        'site_removed' => '実施施設が削除されました。',
        'results_posted' => 'レジストリに結果が掲載されました。',
        'date_changed' => '重要な日付が変わりました。',
        'new_study' => 'この保存済み検索に一致する、新たに登録された試験です。',
        'updated' => 'レジストリの登録内容が更新されました。',
        'matches' => 'この保存済み検索に一致：:criteria。',
    ],

    'criteria' => [
        'q' => 'テキスト「:value」',
        'condition' => '疾患・状態「:value」',
        'keyword' => 'キーワード「:value」',
        'mesh_term' => 'MeSH 用語「:value」',
        'sponsor' => 'スポンサー「:value」',
        'country' => '国 :value',
        'city' => '都市 :value',
        'status' => 'ステータス :value',
        'phase' => 'フェーズ :value',
    ],

    'subject' => 'AI ダイジェスト：:name（:period）',
    'subject_fallback' => 'AI ダイジェスト',
    'title' => '「:name」の AI ダイジェスト',
    'period' => ':start〜:end',
    'intro' => ':start〜:end の間に、一致する試験の更新が :count件ありました。',
    'intro_truncated' => ':start〜:end の間に、一致する試験の更新が :count件ありました。関連性の高い :shown件を以下に示します。',
    'source_ai' => '掲載したレジストリの事実をもとに AI が作成した要約です。',
    'source_factual' => '記録されたレジストリの変更から作成した要約です。',
    'updated_on' => 'レジストリの更新日：:date',
    'view_registry' => 'ClinicalTrials.gov で見る',
    'view_report' => 'レポートを開く',
    'why_matches' => '一致した理由',
    'notice' => 'このダイジェストは ClinicalTrials.gov の公開レジストリの登録内容を説明するものです。医学的助言ではなく、いずれの試験への参加資格も評価せず、結果の解釈も行いません。すべての事実をリンク先のレジストリページでご確認ください。',
    'missing_fact' => 'レジストリにこの情報は提供されていません。',
    'manage' => 'この保存済み検索で AI ダイジェストがオンになっているため、このメールをお送りしています。保存済み検索の設定でオフにできます。基本アラートは引き続き動作します。',
];
