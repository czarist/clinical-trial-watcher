<?php

declare(strict_types=1);

// Japanese error messages returned by the API.
return [
    'unauthenticated' => '続行するにはログインしてください。',
    'forbidden' => 'この操作を行う権限がありません。',
    'not_found' => '指定された項目は見つかりませんでした。',
    'throttled' => 'リクエストが多すぎます。しばらく待ってから、もう一度お試しください。',

    'alert_limit' => '保存できる検索は最大 :limit 件です。新しく作成するには、いずれかを削除してください。',

    'billing' => [
        'plan_not_purchasable' => 'このプランはオンラインで購入できません。',
        'no_subscription' => '有効なサブスクリプションがありません。',
        'already_subscribed' => 'すでにサブスクリプションをお持ちです。代わりにプランを変更してください。',
        'price_misconfigured' => 'このプランは現在一時的に購入できません。後ほどもう一度お試しください。',
        'not_resumable' => 'このサブスクリプションは解約の猶予期間中ではありません。',
    ],

    'paid' => [
        'ai_digests' => 'AI ダイジェストは Pro AI および Team AI プランに含まれます。基本アラートは引き続き無料です。',
        'team_workspace' => 'このチームのワークスペースには、チームのオーナーによる有効な Team AI サブスクリプションが必要です。',
    ],

    'team' => [
        'already_owns_team' => 'すでにチームのオーナーです。Team AI サブスクリプション1件につき1チームまでです。',
        'already_member' => 'この方はすでにチームのメンバーです。',
        'owner_cannot_leave' => 'チームのオーナーはチームを退出できません。代わりにチームを削除してください。',
        'invitation_not_found' => '招待が見つかりません。',
    ],
];
