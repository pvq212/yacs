# 網站與通用串接

在收件匣設定精確 `allowed_origins`（例如 `https://shop.example.com`），不使用萬用字元。管理頁列出收件匣公開 key 與嵌入代碼。

```html
<script src="https://support.example.com/sdk/yacs.js"></script>
<script>
Yacs.init({baseUrl:'https://support.example.com',inboxKey:'<公開收件匣 key>'});
Yacs.on('ready',()=>console.log('客服已就緒'));
</script>
```

`open/close/sendMessage/setContext/setAttributes/identify/logout/destroy` 由 `postMessage` 連到指定 iframe，雙方檢查 origin 與 source；訪客 access/refresh token 僅留在 iframe。SDK 的 `sendMessage`、`identify`、`logout` 等回 Promise。`destroy()` 先撤銷目前訪客 session 再移除介面。

會員身分由網站後端驗證登入後簽發 HS256 JWT，限 60 秒；不能把瀏覽器傳來的 user id 當成可信身分。管理頁「會員身分簽發」的 `secret` 是 **至少 32 bytes 隨機金鑰的 base64**。參考 [簽發範例](../../spec/examples/identity-issuer.php)，將結果交給 `Yacs.identify(assertion)`；金鑰不送往瀏覽器。issuer、key id、workspace、品牌與收件匣要與設定一致，jti 不可重用。匿名歷史不會自動合併到會員。

API client 的 bearer token 只顯示一次；可限制品牌、收件匣、權限、IP 與到期時間。聯絡人以 client 自己的 issuer／subject 去重，無法替其他簽發者冒認會員。契約見 [OpenAPI](../../spec/contracts/openapi.yaml)。

通用渠道建立後使用 `/api/v1/hooks/{public_key}`，以原始 JSON body 計算：

```text
X-Yacs-Event-Id: <body.event_id>
X-Yacs-Key-Id: <設定的 key_id>
X-Yacs-Timestamp: <Unix 秒數，五分鐘內>
X-Yacs-Signature: v1=<HMAC-SHA256(timestamp + "." + raw_body, secret)>
```

入站 `message.created` 含 `external_thread_id`、`external_contact.subject`、`message.external_message_id`、`message.body_text`、`occurred_at`。首次接受回 202，完全相同的重送回 200；相同事件 ID 搭配不同 body 回 409。只有公開文字可送到設定的 `outbound_url`，內部備註不建立投遞。

出站 body 含固定 `event_id`、外部 thread id、message id／body／author type，使用相同 HMAC header。接收端必須以 event_id 去重並回 2xx，可回 `{"external_message_id":"..."}`。逾時／5xx 採至少一次重試，不能假定只收到一次。狀態回報使用 `message.delivery_updated`，帶 external_message_id（或附 `message.delivery_id`＝出站 event_id）、delivery_state。`read` 不會被較晚的 `sent` 降級。

本版通用渠道宣告文字能力，附件會明確回 `CAPABILITY_UNSUPPORTED`；不下載未經批准的遠端 attachment URLs。網站聊天附件仍完整支援。LINE／WhatsApp 特定平台 adapter 未包含。
