<?php

// API 錯誤訊息（繁體中文）。訊息面向終端使用者，不含內部細節。
return [
    'UNAUTHENTICATED' => '尚未登入或登入已失效，請重新登入。',
    'MFA_REQUIRED' => '需要完成兩步驟驗證。',
    'FORBIDDEN' => '您沒有執行此操作的權限。',
    'NOT_FOUND' => '找不到指定的資料。',
    'VERSION_CONFLICT' => '資料已由其他操作更新，請重新載入。',
    'IDEMPOTENCY_CONFLICT' => '相同的請求識別碼已用於不同內容。',
    'CAPACITY_EXCEEDED' => '已達可處理的案件上限。',
    'INVALID_STATE' => '目前狀態無法執行此操作。',
    'IDENTITY_REPLAYED' => '身分憑證已使用過，請重新取得。',
    'NEW_CONVERSATION_REQUIRED' => '此對話已超過可重新開啟的時間，請建立新對話。',
    'CURSOR_EXPIRED' => '事件紀錄已過期，請重新載入對話。',
    'VALIDATION_FAILED' => '輸入資料格式不正確。',
    'CAPABILITY_UNSUPPORTED' => '所選的服務或模型不支援此功能。',
    'PAYLOAD_TOO_LARGE' => '請求內容過大。',
    'RATE_LIMITED' => '操作過於頻繁，請稍後再試。',
    'TEMPORARILY_UNAVAILABLE' => '服務暫時無法使用，請稍後再試。',
    'INTERNAL_ERROR' => '系統發生錯誤，請稍後再試。',
    'CSRF_MISMATCH' => '安全驗證已失效，請重新整理頁面後再試。',
];
