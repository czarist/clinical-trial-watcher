<?php

declare(strict_types=1);

// Japanese emails sent to account holders.
return [
    'notice' => 'この情報は公開レジストリ ClinicalTrials.gov に基づくものです。医学的助言や参加資格の判断ではありません。医療専門家と試験チームにご相談ください。',

    'alert_matches' => [
        'subject' => 'アラート「:name」の試験の更新 :count件',
        'greeting' => 'アラート：:name',
        'intro' => 'このアラートに一致する試験の更新が :count件あります。',
        'more' => 'ほか :count件。',
        'action' => 'アラートを開く',
        'study_removed' => '削除された試験',
        'unknown' => '不明',
        'reason' => [
            'new_study' => '新しい試験',
            'updated_study' => '更新された試験',
            'status_change' => 'ステータス変更',
        ],
    ],

    'team_invitation' => [
        'subject' => 'チーム :team への招待',
        'intro' => ':inviter さんから Clinical Trial Watcher のチーム「:team」に招待されました。',
        'body' => 'メンバーはチームの保存済み検索と AI ダイジェストのレポートを共有します。承諾するまでは何も変わりません。',
        'action' => '招待を確認',
    ],
];
