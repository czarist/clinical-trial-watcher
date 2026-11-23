<?php

declare(strict_types=1);

// Traditional Chinese (Taiwan) emails sent to account holders.
return [
    'notice' => '此資訊來自公開的 ClinicalTrials.gov 登錄資料庫，並非醫療建議，也不代表參與資格的判定；請諮詢醫療專業人員及研究團隊。',

    'alert_matches' => [
        'subject' => '您的提醒「:name」有 :count 項研究更新',
        'greeting' => '提醒：:name',
        'intro' => '有 :count 項研究更新符合此提醒。',
        'more' => '另有 :count 項。',
        'action' => '開啟您的提醒',
        'study_removed' => '研究已移除',
        'unknown' => '不明',
        'reason' => [
            'new_study' => '新研究',
            'updated_study' => '研究已更新',
            'status_change' => '狀態已變更',
        ],
    ],

    'team_invitation' => [
        'subject' => '邀請您加入團隊 :team',
        'intro' => ':inviter 邀請您加入 Clinical Trial Watcher 上的團隊「:team」。',
        'body' => '成員可共用團隊的已儲存搜尋及 AI 摘要報告。在您接受之前，不會有任何變更。',
        'action' => '查看邀請',
    ],
];
