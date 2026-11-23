<?php

declare(strict_types=1);

// Korean error messages returned by the API.
return [
    'unauthenticated' => '계속하려면 로그인하세요.',
    'forbidden' => '이 작업을 수행할 권한이 없습니다.',
    'not_found' => '요청한 항목을 찾을 수 없습니다.',
    'throttled' => '요청이 너무 많습니다. 잠시 후 다시 시도하세요.',

    'alert_limit' => '저장된 검색은 최대 :limit개까지 유지할 수 있습니다. 새로 만들려면 하나를 삭제하세요.',

    'billing' => [
        'plan_not_purchasable' => '이 요금제는 온라인으로 구매할 수 없습니다.',
        'no_subscription' => '활성 구독이 없습니다.',
        'already_subscribed' => '이미 구독 중입니다. 대신 구독의 요금제를 변경하세요.',
        'price_misconfigured' => '이 요금제는 일시적으로 구매할 수 없습니다. 나중에 다시 시도하세요.',
        'not_resumable' => '구독이 해지 유예 기간에 있지 않습니다.',
    ],

    'paid' => [
        'ai_digests' => 'AI 다이제스트는 Pro AI 및 Team AI 요금제에 포함됩니다. 기본 알림은 계속 무료입니다.',
        'team_workspace' => '이 팀의 작업 공간을 사용하려면 팀 소유자가 보유한 활성 Team AI 구독이 필요합니다.',
    ],

    'team' => [
        'already_owns_team' => '이미 소유한 팀이 있습니다. Team AI 구독 하나로 팀 하나를 이용할 수 있습니다.',
        'already_member' => '이미 팀의 구성원입니다.',
        'owner_cannot_leave' => '팀 소유자는 팀을 탈퇴할 수 없습니다. 대신 팀을 삭제하세요.',
        'invitation_not_found' => '초대를 찾을 수 없습니다.',
    ],
];
