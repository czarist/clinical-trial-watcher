<?php

declare(strict_types=1);

// Traditional Chinese (Taiwan) error messages returned by the API.
return [
    'unauthenticated' => '請登入以繼續。',
    'forbidden' => '您沒有執行此操作的權限。',
    'not_found' => '找不到要求的項目。',
    'throttled' => '請求次數過多，請稍候再試。',

    'alert_limit' => '您最多可保留 :limit 個已儲存搜尋。請先刪除一個再建立新的。',

    'billing' => [
        'plan_not_purchasable' => '此方案無法線上購買。',
        'no_subscription' => '您沒有有效的訂閱。',
        'already_subscribed' => '您已有訂閱，請改為變更其方案。',
        'price_misconfigured' => '此方案暫時無法購買，請稍後再試。',
        'not_resumable' => '此訂閱不在取消寬限期內。',
    ],

    'paid' => [
        'ai_digests' => 'AI 摘要包含在 Pro AI 及 Team AI 方案中。基本提醒仍維持免費。',
        'team_workspace' => '此團隊的工作區需要團隊擁有者持有有效的 Team AI 訂閱。',
    ],

    'team' => [
        'already_owns_team' => '您已擁有一個團隊。一個 Team AI 訂閱僅涵蓋一個團隊。',
        'already_member' => '此人已是團隊成員。',
        'owner_cannot_leave' => '團隊擁有者無法離開團隊，請改為刪除團隊。',
        'invitation_not_found' => '找不到邀請。',
    ],
];
