<?php

declare(strict_types=1);

// Wording of digests in Korean. Factual registry language only: no eligibility, treatment
// or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => '진행 중, 모집 종료',
        'COMPLETED' => '완료',
        'ENROLLING_BY_INVITATION' => '초청 대상자 등록 중',
        'NOT_YET_RECRUITING' => '모집 전',
        'RECRUITING' => '모집 중',
        'SUSPENDED' => '일시 중지',
        'TERMINATED' => '조기 종료',
        'WITHDRAWN' => '철회',
        'AVAILABLE' => '이용 가능',
        'NO_LONGER_AVAILABLE' => '더 이상 이용 불가',
        'TEMPORARILY_NOT_AVAILABLE' => '일시적으로 이용 불가',
        'APPROVED_FOR_MARKETING' => '판매 승인',
        'WITHHELD' => '비공개',
        'UNKNOWN' => '상태 알 수 없음',
        'none' => '상태 없음',
    ],
    'dates' => [
        'start_date' => '시작일',
        'primary_completion_date' => '주요 완료일',
        'completion_date' => '연구 완료일',
    ],
    'date_type' => [
        'ACTUAL' => '실제',
        'ESTIMATED' => '예상',
    ],
    'months' => ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
    'date_month' => ':year년 :month',
    'date_day' => ':year년 :month :day일',
    'not_set' => '미정',

    'fact' => [
        'created' => ':status 상태로 새 연구가 등록되었습니다.',
        'created_no_status' => '새 연구가 등록되었습니다.',
        'status' => '등록 정보가 :before에서 :after(으)로 변경되었습니다.',
        'site_added' => '연구 기관 추가: :site(:status).',
        'site_added_no_status' => '연구 기관 추가: :site.',
        'site_removed' => '연구 기관 삭제: :site.',
        'site_updated' => '연구 기관 :site이(가) :before에서 :after(으)로 변경되었습니다.',
        'date' => ':name이(가) :before에서 :after(으)로 변경되었습니다.',
        'results' => '결과가 게시되었습니다.',
        'updated' => '등록 정보가 업데이트되었습니다.',
        'more_sites' => '그 밖에 연구 기관 변경 :count건.',
    ],

    'reason' => [
        'now_recruiting' => '모집 시작: 상태가 모집 중으로 변경되었습니다.',
        'status_changed' => '전체 상태가 변경되었습니다.',
        'status_in_criteria' => '새 상태인 :status은(는) 이 저장 검색이 팔로우하는 상태입니다.',
        'site_in_region' => '이 저장 검색이 모니터링하는 지역인 :place에 모집 중인 연구 기관이 있습니다.',
        'site_added' => '새 연구 기관이 등록되었습니다.',
        'site_removed' => '연구 기관이 삭제되었습니다.',
        'results_posted' => '레지스트리에 결과가 게시되었습니다.',
        'date_changed' => '주요 날짜가 변경되었습니다.',
        'new_study' => '이 저장 검색과 일치하는 새로 등록된 연구입니다.',
        'updated' => '등록 정보가 업데이트되었습니다.',
        'matches' => '이 저장 검색과 일치: :criteria.',
    ],

    'criteria' => [
        'q' => '텍스트 ":value"',
        'condition' => '질환 ":value"',
        'keyword' => '키워드 ":value"',
        'mesh_term' => 'MeSH 용어 ":value"',
        'sponsor' => '후원 기관 ":value"',
        'country' => '국가 :value',
        'city' => '도시 :value',
        'status' => '상태 :value',
        'phase' => '임상 단계 :value',
    ],

    'subject' => 'AI 다이제스트: :name (:period)',
    'subject_fallback' => 'AI 다이제스트',
    'title' => '":name" AI 다이제스트',
    'period' => ':start~:end',
    'intro' => ':start~:end 기간에 일치하는 연구 업데이트 :count건.',
    'intro_truncated' => ':start~:end 기간에 일치하는 연구 업데이트 :count건. 가장 관련성이 높은 :shown건을 아래에 설명합니다.',
    'source_ai' => '아래 나열된 등록 정보 사실을 바탕으로 AI가 작성한 요약입니다.',
    'source_factual' => '기록된 등록 정보 변경 사항을 바탕으로 작성한 요약입니다.',
    'updated_on' => '레지스트리 업데이트 게시일: :date',
    'view_registry' => 'ClinicalTrials.gov에서 보기',
    'view_report' => '보고서 열기',
    'why_matches' => '일치 이유',
    'notice' => '이 다이제스트는 ClinicalTrials.gov의 공개 등록 정보를 설명합니다. 의학적 조언이 아니며, 어떤 연구에 대한 참여 자격도 평가하지 않고 결과를 해석하지 않습니다. 모든 사실은 링크된 레지스트리 페이지에서 확인하세요.',
    'missing_fact' => '레지스트리에서 이 정보를 제공하지 않았습니다.',
    'manage' => '이 저장 검색에 AI 다이제스트가 켜져 있어 이 이메일을 받고 있습니다. 저장 검색 설정에서 끌 수 있으며, 기본 알림은 계속 작동합니다.',
];
