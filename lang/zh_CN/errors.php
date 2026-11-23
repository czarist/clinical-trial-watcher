<?php

declare(strict_types=1);

// Error messages returned by the API, in Simplified Chinese.
return [
    'unauthenticated' => '请登录后继续。',
    'forbidden' => '您无权执行此操作。',
    'not_found' => '未找到请求的内容。',
    'throttled' => '请求过多。请稍候再试。',

    'alert_limit' => '您最多可以保存 :limit 个搜索。请删除一个后再创建新的。',

    'billing' => [
        'plan_not_purchasable' => '此套餐无法在线购买。',
        'no_subscription' => '您没有有效的订阅。',
        'already_subscribed' => '您已有订阅，请改为更改其套餐。',
        'price_misconfigured' => '此套餐暂时无法购买。请稍后再试。',
        'not_resumable' => '该订阅不在取消后的宽限期内。',
    ],

    'paid' => [
        'ai_digests' => 'AI 摘要属于 Pro AI 和 Team AI 套餐。基础提醒仍然免费。',
        'team_workspace' => '此团队的工作区需要团队所有者持有有效的 Team AI 订阅。',
    ],

    'team' => [
        'already_owns_team' => '您已拥有一个团队。一个 Team AI 订阅只涵盖一个团队。',
        'already_member' => '此人已是团队成员。',
        'owner_cannot_leave' => '团队所有者不能退出团队。请改为删除团队。',
        'invitation_not_found' => '未找到邀请。',
    ],
];
