<?php

declare(strict_types=1);

// Wording of digests in Simplified Chinese. Factual registry language only: no eligibility, treatment
// or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => '进行中，不再招募',
        'COMPLETED' => '已完成',
        'ENROLLING_BY_INVITATION' => '仅限受邀入组',
        'NOT_YET_RECRUITING' => '尚未招募',
        'RECRUITING' => '正在招募',
        'SUSPENDED' => '已暂停',
        'TERMINATED' => '已终止',
        'WITHDRAWN' => '已撤回',
        'AVAILABLE' => '可提供',
        'NO_LONGER_AVAILABLE' => '不再提供',
        'TEMPORARILY_NOT_AVAILABLE' => '暂时无法提供',
        'APPROVED_FOR_MARKETING' => '已批准上市',
        'WITHHELD' => '暂不公开',
        'UNKNOWN' => '状态未知',
        'none' => '无状态',
    ],
    'dates' => [
        'start_date' => '开始日期',
        'primary_completion_date' => '主要完成日期',
        'completion_date' => '研究完成日期',
    ],
    'date_type' => [
        'ACTUAL' => '实际',
        'ESTIMATED' => '预计',
    ],
    'months' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
    'date_month' => ':year年:month',
    'date_day' => ':year年:month:day日',
    'not_set' => '未设定',

    'fact' => [
        'created' => '新注册的研究，状态为 :status。',
        'created_no_status' => '新注册的研究。',
        'status' => '注册条目由 :before 变为 :after。',
        'site_added' => '新增研究机构：:site（:status）。',
        'site_added_no_status' => '新增研究机构：:site。',
        'site_removed' => '移除研究机构：:site。',
        'site_updated' => '研究机构 :site 由 :before 变为 :after。',
        'date' => ':name 由 :before 变为 :after。',
        'results' => '已发布结果。',
        'updated' => '注册条目已更新。',
        'more_sites' => '另有 :count 项研究机构变更。',
    ],

    'reason' => [
        'now_recruiting' => '已开放招募：状态变为“正在招募”。',
        'status_changed' => '总体状态已变更。',
        'status_in_criteria' => '新状态“:status”属于此已保存搜索关注的状态。',
        'site_in_region' => '位于 :place 的招募中研究机构，该地点在此已保存搜索的监测范围内。',
        'site_added' => '新列出研究机构。',
        'site_removed' => '一个研究机构已被移除。',
        'results_posted' => '已在注册库发布结果。',
        'date_changed' => '一个关键日期已变更。',
        'new_study' => '新注册且与此已保存搜索匹配的研究。',
        'updated' => '注册条目已更新。',
        'matches' => '与此已保存搜索匹配：:criteria。',
    ],

    'criteria' => [
        'q' => '文本“:value”',
        'condition' => '疾病/状况“:value”',
        'keyword' => '关键词“:value”',
        'mesh_term' => 'MeSH 主题词“:value”',
        'sponsor' => '申办方“:value”',
        'country' => '国家 :value',
        'city' => '城市 :value',
        'status' => '状态 :value',
        'phase' => '分期 :value',
    ],

    'subject' => 'AI 摘要：:name（:period）',
    'subject_fallback' => 'AI 摘要',
    'title' => '“:name”的 AI 摘要',
    'period' => ':start 至 :end',
    'intro' => ':start 至 :end 期间有 :count 项匹配的研究更新。',
    'intro_truncated' => ':start 至 :end 期间有 :count 项匹配的研究更新。以下介绍其中最相关的 :shown 项。',
    'source_ai' => '摘要由 AI 根据所列注册库事实撰写。',
    'source_factual' => '摘要根据记录的注册库变更生成。',
    'updated_on' => '注册库更新发布于 :date',
    'view_registry' => '在 ClinicalTrials.gov 上查看',
    'view_report' => '打开报告',
    'why_matches' => '匹配原因',
    'notice' => '本摘要描述的是 ClinicalTrials.gov 上的公开注册条目。它不是医疗建议，不评估任何研究的入选资格，也不解读研究结果。请在链接的注册库页面上核实每项事实。',
    'missing_fact' => '注册库未提供此信息。',
    'manage' => '您收到此邮件是因为此已保存搜索开启了 AI 摘要。您可以在已保存搜索的设置中关闭摘要；基础提醒将继续有效。',
];
