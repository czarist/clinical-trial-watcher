<?php

declare(strict_types=1);

// Korean emails sent to account holders.
return [
    'notice' => '이 정보는 공개 레지스트리인 ClinicalTrials.gov에서 가져온 것입니다. 의학적 조언이나 참여 자격에 대한 판단이 아닙니다. 의료 전문가 및 연구팀과 상담하세요.',

    'alert_matches' => [
        'subject' => '알림 ":name"에 대한 연구 업데이트 :count건',
        'greeting' => '알림: :name',
        'intro' => '이 알림과 일치하는 연구 업데이트가 :count건 있습니다.',
        'more' => '외 :count건.',
        'action' => '내 알림 열기',
        'study_removed' => '삭제된 연구',
        'unknown' => '알 수 없음',
        'reason' => [
            'new_study' => '새 연구',
            'updated_study' => '업데이트된 연구',
            'status_change' => '상태 변경',
        ],
    ],

    'team_invitation' => [
        'subject' => ':team 팀 초대',
        'intro' => ':inviter 님이 Clinical Trial Watcher의 ":team" 팀에 초대했습니다.',
        'body' => '구성원은 팀의 저장 검색과 AI 다이제스트 보고서를 공유합니다. 수락하기 전까지는 아무것도 변경되지 않습니다.',
        'action' => '초대 확인',
    ],
];
