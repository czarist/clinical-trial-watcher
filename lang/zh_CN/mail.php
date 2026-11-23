<?php

declare(strict_types=1);

// Emails sent to account holders, in Simplified Chinese.
return [
    'notice' => '此信息来自公开的 ClinicalTrials.gov 注册库。它不是医疗建议，也不是对入选资格的判定；请咨询医疗专业人员和研究团队。',

    'alert_matches' => [
        'subject' => '您的提醒“:name”有 :count 项研究更新',
        'greeting' => '提醒：:name',
        'intro' => '有 :count 项研究更新与此提醒匹配。',
        'more' => '另有 :count 项。',
        'action' => '打开您的提醒',
        'study_removed' => '研究已移除',
        'unknown' => '未知',
        'reason' => [
            'new_study' => '新研究',
            'updated_study' => '研究已更新',
            'status_change' => '状态已变更',
        ],
    ],

    'team_invitation' => [
        'subject' => '邀请您加入团队 :team',
        'intro' => ':inviter 邀请您加入 Clinical Trial Watcher 上的团队“:team”。',
        'body' => '成员共享团队已保存的搜索和 AI 摘要报告。在您接受之前，一切都不会改变。',
        'action' => '查看邀请',
    ],
];
