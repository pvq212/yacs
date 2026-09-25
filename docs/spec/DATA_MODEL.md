# 資料模型、索引與一致性規格

版本1.0.0；與 `SPEC.md` 共同閱讀。以下是要實作的 schema contract，不是可直接套到既有正式資料庫的 migration。

## 1. 共通約定

所有外部ID使用UUID字串；新ID建議由應用產生UUIDv7，但**對話內排序只依序號**。除 `users` / `workspaces` 及必要global tables外，業務表皆含 `workspace_id`。時間用 `timestamptz`；狀態採varchar + CHECK（便於部署演進）；金額numeric，不用float。`jsonb`只裝可變metadata/config，不用來隱藏主要關聯、權限、分派或唯一鍵。

租戶表以 `PRIMARY KEY(id)` 加 `UNIQUE(workspace_id,id)`，子表使用 composite FK 到父表的 `(workspace_id,id)`；需要brand/inbox一致性時另外建立對應複合唯一鍵及FK。單純為每張表加workspace_id，卻仍只用parent_id外鍵，無法防止跨workspace關聯。

可由API PATCH的設定資源另有version bigint（初始0），更新必須CAS；immutable版本/append-only事件不適用此PATCH規則。Created/updated欄位依表需要；事件、稽核、已發布知識版本與訊息採append-only。刪除是受控purge/redaction程序，非所有表一律soft delete。

## 2. 租戶、品牌、人員與權限

| 表 | 核心欄位（未列出者依共通約定） | 重要約束 |
|---|---|---|
| workspaces | id,name,slug,timezone,status,settings,knowledge_generation | slug唯一；knowledge_generation bigint從0開始 |
| users | id,email_normalized,password_hash,name,mfa_secret_ref,status | email唯一；MFA secret不回API |
| workspace_memberships | workspace_id,user_id,display_name,status | unique(workspace_id,user_id)；對話assignee指向membership，不直接指global user |
| brands | workspace_id,name,slug,status,settings | unique(workspace_id,slug) |
| inboxes | workspace_id,brand_id,name,public_key,channel_type,status,ai_mode,ai_profile_id,business_hours_id,settings | public_key全域唯一且不可枚舉；unique(workspace_id,brand_id,id) |
| inbox_origins | workspace_id,inbox_id,origin | normalized scheme+host+port；不接受任意regex/wildcard |
| teams | workspace_id,name,status | unique(workspace_id,name) |
| team_memberships | workspace_id,team_id,membership_id | pair唯一，父FK皆workspace-aware |
| inbox_teams | workspace_id,inbox_id,team_id | pair唯一 |
| inbox_memberships | workspace_id,inbox_id,membership_id | 明確座席權限範圍；role授權仍須檢查 |
| roles | workspace_id,key,name,is_system_template | unique(workspace_id,key) |
| permissions | code,description | global唯讀權限目錄 |
| role_permissions | workspace_id,role_id,permission_code | pair唯一 |
| role_bindings | workspace_id,membership_id,role_id,scope_type,scope_id | scope=workspace/brand/inbox/team；scope_id依類型驗證；多型FK由service+測試補足 |
| agent_capacity | workspace_id,membership_id,max_active,last_assigned_at | unique(workspace_id,membership_id)；分派交易鎖定此row；活躍數由案件查詢計算或交易內同步 |
| business_hours | workspace_id,timezone,weekly_schedule,holidays | JSON schema驗證；禁止client提供未驗證timezone |
| sessions（Laravel原生） | id,user_id,ip_address,user_agent,payload,last_activity | 保持framework driver格式；啟用session payload加密，嚴格限制DB/log存取 |
| staff_session_security | id,user_id,session_id_digest,expires_at,revoked_at,session_generation | 使用獨立UUID作fanout識別；不把cookie/token原文用作channel名稱 |

Presence（多分頁session、心跳、available/away）可暫存Redis，但staff啟用狀態與授權永遠以DB為準。不得把離線誤當撤銷權限。

## 3. 客戶、session與身分

| 表 | 核心欄位 | 重要約束 |
|---|---|---|
| contacts | workspace_id,brand_id,name,email_encrypted,phone_encrypted,attributes,status | brand必填；敏感欄位分權限序列化 |
| contact_identities | workspace_id,brand_id,contact_id,issuer,subject,verified_at | unique(workspace_id,brand_id,issuer,subject)；不能靠email merge |
| identity_issuers | workspace_id,brand_id,key_id,issuer,inbox_scope,secret_ref,status | issuer+key_id唯一；固定演算法與audience |
| identity_exchanges | workspace_id,issuer_id,jti_hash,request_hash,session_id,expires_at | unique(issuer_id,jti_hash)，原子consume；若提供冪等response含token則短期加密儲存 |
| visitor_sessions | workspace_id,brand_id,inbox_id,contact_id,identity_level,token_hash,refresh_hash,expires_at,refresh_expires_at,revoked_at,session_generation | 只能屬於一個brand/inbox；access/refresh分離，rotation有reuse檢查 |
| visitor_refresh_tokens | workspace_id,session_id,family_id,token_hash,replaced_by,used_at,expires_at,revoked_at | hash唯一；保留旋轉歷史以偵測舊token reuse，不只存目前hash |
| customer_attributes | workspace_id,key,type,visibility,editable_by_visitor,validation_rules | 來源可信等級固定；verified/VIP/餘額不可由visitor修改 |
| contact_merge_events | workspace_id,brand_id,source_contact_id,target_contact_id,conversation_id,actor_id,reason | 首版只有明確授權的匿名目前對話合併；不跨品牌 |

識別內容使用加密或最少化；需要以Email搜尋時，可另外用帶伺服器key的normalized lookup digest，不以可暴力猜測的無salt hash當隱私保護。

## 4. 對話與訊息

| 表 | 核心欄位 | 重要約束 |
|---|---|---|
| conversations | workspace_id,brand_id,inbox_id,contact_id,status,handling_mode,assignee_id,team_id,version,answer_epoch,next_message_seq,public_event_seq,staff_event_seq,latest_customer_message_id,last_customer_message_at,last_message_at,resolved_at,resolution_code,resolution_cycle,wake_at,related_conversation_id | version/answer_epoch從0開始；next_message_seq從1開始，event counters從0開始；CAS和row lock；brand/inbox/contact一致 |
| messages | workspace_id,conversation_id,message_seq,author_type,staff_membership_id,contact_id,ai_run_id,visibility,kind,body_text,client_message_id,source_scope,external_message_id,created_at,redacted_at | unique(conversation_id,message_seq)；unique(workspace_id,source_scope,client_message_id) where非null；unique(ai_run_id) where非null；unique(workspace_id,source_scope,external_message_id) where非null |
| message_attachments | workspace_id,message_id,file_id,position | 同workspace及可見性檢查；clean檔案才能attach |
| message_redactions | workspace_id,message_id,actor_type,actor_id,reason,created_at | 保留事件、不保留已要求刪除的原文複本 |
| conversation_assignments | workspace_id,conversation_id,from_assignee_id,to_assignee_id,team_id,reason,actor_id,forced,created_at | append-only |
| conversation_reads | workspace_id,conversation_id,reader_type,reader_id,last_read_message_seq,read_at | unique(conv,reader_type,reader_id)；只允許單調遞增，不能超出可見最新seq |
| conversation_tags | workspace_id,conversation_id,tag_id | pair唯一 |
| tags | workspace_id,name,color_token | unique(workspace_id,name) |
| macros | workspace_id,brand_id,title,body_template,allowed_variables,status | scope授權；不允許eval |
| csat_responses | workspace_id,conversation_id,resolution_cycle,score,comment,contact_id,submitted_at | unique(conversation_id,resolution_cycle)；分數1–5 |
| audit_logs | workspace_id,actor_type,actor_id,action,resource_type,resource_id,scope,request_id,ip_digest,reason,before_safe,after_safe,created_at | append-only；secret不可入before/after |

`message_seq`、event_seq、version在JSON用十進位字串，DB用bigint。讀取訊息以 `message_seq` keyset pagination，不以OFFSET處理長歷史。

### 狀態CHECK（概念SQL）

```sql
CHECK (status IN ('open','waiting_customer','snoozed','resolved'));
CHECK (handling_mode IN ('ai','human_queue','human'));
CHECK (
  status = 'resolved'
  OR (handling_mode IN ('ai','human_queue') AND status='open' AND assignee_id IS NULL)
  OR (handling_mode='human' AND assignee_id IS NOT NULL)
);
CHECK ((status='snoozed') = (wake_at IS NOT NULL));
```

解析resolved案件時清除wake_at。Resolved可以保留assignee/mode作歷史，但任何新的公開message必須走重開規則。新對話resolution_cycle從1開始，重開需遞增resolution_cycle；已結案超過72小時的原conversation POST訊息回`409 NEW_CONVERSATION_REQUIRED`，SDK以related_conversation_id新建後重送原draft，不能把訊息悄悄放到另一對話而不告知caller。

### 併發新增訊息（流程，不是完整可貼上SQL）

1. Authenticate、Policy、驗證body與檔案權限。
2. transaction內reserve idempotency key並確認request hash。
3. `SELECT ... FROM conversations WHERE workspace_id=? AND id=? FOR UPDATE`。
4. 驗證狀態；取得並增加`next_message_seq`；新增message。
5. Visitor輸入更新latest_customer_message_id、answer_epoch；已resolved依規則重開或回衝突。
6. 建立public/staff projection event；必要時建立async task；更新idempotent result。
7. commit，回傳持久化message DTO。Queue/WS失敗不改寫此結果。

唯一鍵競爭不能一律顯示500：同client_message_id、同body應回原message，不同body回409。DB transaction rollback後才重新讀取衝突row，避免在PostgreSQL已abort的transaction繼續查詢。

## 5. 知識與向量

| 表 | 核心欄位 | 約束 |
|---|---|---|
| knowledge_bases | workspace_id,brand_id,name,default_locale,status,active_embedding_profile_id | inbox可綁多KB；不跨brand默認共享 |
| inbox_knowledge_bases | workspace_id,inbox_id,knowledge_base_id,priority | pair唯一 |
| knowledge_documents | workspace_id,knowledge_base_id,title,source_type,source_ref,external_key,published_version_id,status | external_key同KB唯一（有值時） |
| knowledge_versions | workspace_id,document_id,version_number,state,visibility,locale,body_text,source_file_id,content_hash,effective_from,effective_to,created_by,approved_by,published_at | unique(document_id,version_number)；published指標只能指同文件ready/published版本 |
| knowledge_chunks | workspace_id,version_id,chunk_index,title_path,text,source_locator,content_hash,token_count,search_text | unique(version_id,chunk_index)；chunk不自行帶可被覆寫的授權邏輯 |
| knowledge_keywords | workspace_id,version_id,keyword_normalized,kind | FAQ aliases/精確碼/人工標籤；語意與keyword結果皆先授權 |
| embedding_profiles | workspace_id,connection_id,model_id,dimensions,metric,normalization,task_type,configuration_version,status | immutable設定；unique(workspace_id,id,dimensions) |
| knowledge_embeddings | workspace_id,chunk_id,embedding_profile_id,dimensions,embedding vector,created_at | unique(chunk_id,embedding_profile_id)；dims CHECK + composite FK profile dimensions |
| knowledge_index_builds | workspace_id,knowledge_base_id,profile_id,state,total_chunks,indexed_chunks,failed_chunks,task_id,error_code | 不完整build不得activate |
| knowledge_evaluations | workspace_id,knowledge_base_id,question,expected_answer,expected_source_ids,expected_action,classification,approved_by | 不混入未去識別真實聊天 |
| knowledge_evaluation_runs | workspace_id,dataset_version,profile_snapshot,results,created_by,created_at | 保存原始逐題結果及人工評分版本 |

### 可實作的向量結構策略

首版採**同一邏輯表 + immutable embedding profile + vector不固定維度**，應用查詢永遠帶單一profile。向量維度用 `CHECK(vector_dims(embedding)=dimensions)`，並以 `(workspace_id,embedding_profile_id,dimensions)` 外鍵對應profile。禁止不同模型同維度混用。

資料量少先用精確檢索。需要ANN時，由受控migration/維運command為單一profile建立partial expression index；不能讓一般後台使用者輸入SQL/索引名稱。下面只是示意，UUID/維度由可信profile資料生成與嚴格驗證：

```sql
-- illustration only: replace the UUID with an approved profile, dimensions with its tested value.
CREATE INDEX knowledge_embeddings_profile_hnsw
ON knowledge_embeddings USING hnsw ((embedding::vector(1536)) vector_cosine_ops)
WHERE embedding_profile_id = '11111111-1111-4111-8111-111111111111';
```

查詢expression/predicate必須與索引匹配。超過vector ANN維度上限時，依模型支援採較低維輸出或經評測的halfvec expression index；不能直接截斷任意向量。`CREATE INDEX CONCURRENTLY`不能在一般migration transaction內執行，發布流程需另安排。

FAQ/source發布交易：確認全version chunks對active profile有embedding、無pending scan → 更新published_version_id/state → 增加workspace knowledge_generation → 保存event。對外回覆commit再次檢查generation，以防引用剛撤下資料。

## 6. AI設定、執行與費用

| 表 | 核心欄位 | 約束 |
|---|---|---|
| provider_connections | workspace_id,name,vendor,protocol,base_url,api_version,secret_ref,headers_safe,egress_policy_id,status,configuration_version | protocol enum與vendor分開；headers不能用來覆蓋host/內部安全限制 |
| ai_models | workspace_id,connection_id,external_model_id,capabilities,context_limit,output_limit,verified_at,verification_state | unique(connection_id,external_model_id)；capability是驗證結果不是猜測 |
| ai_profiles | workspace_id,name,chat_model_id,embedding_profile_id,rerank_model_id,prompt_version,temperature,limits,fallback_policy,data_policy,configuration_version | chat/embedding/rerank解耦；不支援的參數不可硬送 |
| ai_runs | workspace_id,conversation_id,kind,answer_epoch,trigger_message_id,knowledge_generation,configuration_version,state,task_id,lease_token,deadline_at,started_at,finished_at,final_message_id,failure_code,handoff_reason | unique(conv,answer_epoch,kind)僅autopilot；assist要有獨立requestid；final message唯一 |
| ai_attempts | workspace_id,run_id,attempt_no,connection_id,model_id,request_id_safe,latency_ms,input_tokens,output_tokens,cached_tokens,usage_state,cost_estimate,currency,error_code | unique(run_id,attempt_no)；usage未知不能計0 |
| ai_run_sources | workspace_id,run_id,chunk_id,source_label,rank,score,profile_snapshot | 保存引用與重播所需版本metadata；原始provider思考文字不保存 |
| tool_executions | workspace_id,run_id,tool_key,actor_scope,input_safe,result_safe,state,external_request_id,started_at,finished_at | sensitive fields遮罩；沒有任意工具名執行 |
| usage_budgets | workspace_id,brand_id,inbox_id,period,currency,limit_amount,reserved_amount,spent_amount | transaction下預留與結算；強制停止策略；未知成本保守估計 |

Autopilot unique應使用partial unique index `WHERE kind='autopilot'`，assist草稿允許多次但每次request id去重。人工接手只取消autopilot，不必刪掉已提供給客服的草稿。

## 7. 任務、事件、渠道與串接

| 表 | 核心欄位 | 約束 |
|---|---|---|
| async_tasks | workspace_id,type,entity_id,dedupe_key,state,queue_name,attempt_count,not_before,lease_token,lease_expires_at,queued_at,started_at,deadline_at,error_code | unique(workspace_id,dedupe_key)；worker需CAS claim；重投不改taskid |
| outbox_events | workspace_id,event_id,event_type,aggregate_type,aggregate_id,payload_safe,occurred_at,state,lease_token,lease_expires_at | event_id全域唯一；payload/敏感projection最小化 |
| realtime_events | workspace_id,conversation_id,audience,event_seq,event_id,type,payload,created_at | unique(conversation_id,audience,event_seq)；audience=public/staff；前台不查staff |
| channel_connectors | workspace_id,brand_id,inbox_id,type,public_key,secret_ref,capabilities,settings,health,status | connector與inbox一對一或明確受控一對多；MVP用一對一 |
| channel_threads | workspace_id,connector_id,external_thread_id,contact_id,conversation_id,metadata_safe | unique(connector_id,external_thread_id,conversation_id)；是否續用依重開規則 |
| inbound_events | workspace_id,connector_id,external_event_id,body_hash,raw_payload_ref,state,received_at,processed_at | unique(connector_id,external_event_id)；原始payload短期加密 |
| channel_deliveries | workspace_id,message_id,connector_id,state,external_message_id,attempt_count,last_error_code,last_attempt_at | unique(message_id,connector_id)；sending/sent/delivered/read/failed/unknown |
| delivery_attempts | workspace_id,delivery_id,attempt_no,request_id_safe,response_code,result_state,created_at | unknown代表可能已送達，無外部冪等不能盲重送 |
| api_clients | workspace_id,name,token_prefix,token_hash,scopes,brand_scope,inbox_scope,allowed_ips,expires_at,status | token只顯示一次；不以public_key當secret |
| webhook_endpoints | workspace_id,url,secret_ref,key_id,event_types,scope,status | 必須通過egress policy；不訂閱未授權事件 |
| webhook_deliveries | workspace_id,endpoint_id,event_id,state,attempt_count,next_attempt_at,last_status,last_error | unique(endpoint_id,event_id) |
| webhook_attempts | workspace_id,delivery_id,attempt_no,signed_at,http_status,duration_ms,response_excerpt_safe | 每次重送新timestamp，不保存secret |
| idempotency_records | workspace_id,principal_scope,method,route_fingerprint,key,request_hash,response_status,response_encrypted_or_safe,expires_at | 複合unique；只在安全重試需要時短期加密token回應 |

Inbound webhook只有在簽章通過且DB持久化完成後才能2xx。回覆payload去重、outbound delivery dedupe與HTTP idempotency是不同層，不可只實作其中一層便宣稱不重複。

## 8. 檔案與資料治理

`files`：workspace_id、brand_id、owner_type/id、purpose(chat/knowledge/export)、object_key、sha256、declared_mime、detected_mime、bytes、scan_state、scan_result_safe、created_at、deleted_at；object key不可由caller任意指定。

`data_erasure_requests`：scope、requested_by、approved_by、state、started_at、completed_at、backup_tombstone_ref。

`export_jobs`：requester、授權scope快照、query、taskid、fileid、expires_at。下載前重查目前權限，不只信任產檔當時的權限。

## 9. 核心索引清單

- conversations(workspace_id,inbox_id,status,last_message_at DESC,id)；(workspace_id,assignee_id,status)；(workspace_id,handling_mode,status,created_at)。
- messages(conversation_id,message_seq) unique；source/client id unique；AI run final unique。
- realtime_events(conversation_id,audience,event_seq) unique。
- contacts(workspace_id,brand_id,id)；contact_identities scoped unique。
- knowledge_versions(document_id,version_number)；knowledge_chunks(version_id,chunk_index)；embeddings(profile_id,chunk_id)。
- inbound_events(connector_id,external_event_id)；outbox_events(state,occurred_at)；async_tasks(state,not_before,lease_expires_at)。
- webhook_deliveries(state,next_attempt_at)；ai_runs(state,deadline_at)。
- 所有高流量FK查詢需評估索引；不把每個jsonb欄位都先建巨大GIN。

## 10. Migration順序與注意

先global/users/workspaces → membership/brand/inbox → permissions/teams → contacts/identities/sessions → conversations（nullable pointers） → tasks/AI設定/knowledge → messages/ai_runs → 補雙向nullable FK → events/channel/integration → files/治理/報表。

knowledge_documents.published_version_id、conversations.latest_customer_message_id、ai_runs.final_message_id等循環引用先建欄位，再建FK。用transaction內更新確保指向正確aggregate；需要的composite FK不能因為migration困難而省略。

持久化測試必須在PostgreSQL真實執行：獨立連線並行接單、人工接手/AI commit、兩個workspace混插FK、duplicate event與重新投遞。不得以SQLite通過作為驗收。
