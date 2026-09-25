<?php

// 系統信件文案（繁體中文）。信件只放通知摘要，不放對話全文或敏感資料。
return [
    'greeting' => ':name 您好：',
    'password_reset' => [
        'subject' => ':app 密碼重設',
        'body' => '我們收到重設密碼的要求。請在 :minutes 分鐘內開啟以下連結設定新密碼：',
        'ignore' => '若您沒有提出此要求，請忽略這封信，您的密碼不會變更。',
    ],
    'invitation' => [
        'subject' => '邀請您加入 :workspace',
        'body' => ':inviter 邀請您加入「:workspace」客服團隊。請在 :hours 小時內開啟以下連結接受邀請：',
        'ignore' => '若您不認識邀請者，請忽略這封信。',
    ],
];
