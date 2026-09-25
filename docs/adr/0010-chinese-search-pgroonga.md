# ADR-0010：中文關鍵字檢索採 PGroonga + FAQ 關鍵字

- 狀態：採納
- 日期：2026-09-26

## 背景

PostgreSQL 預設全文檢索無中文斷詞。候選：pg_trgm（三字元組，對 1–2 字中文詞效果差）、
zhparser（簡體詞典為主）、pg_jieba（維護狀況不明）、PGroonga（N-gram，無需詞典，支援繁體）。

## 決策

- 自建 PostgreSQL 映像：PostgreSQL 18 + **PGroonga** + pgvector（`infra/docker/postgres`）。
- Lexical 檢索介面 `LexicalSearcher`；預設 `PgroongaLexicalSearcher`，另提供
  `TrigramLexicalSearcher`（僅 pg_trgm，給無法安裝擴充的環境）。
- FAQ 同義問法、錯誤碼、型號等以 `knowledge_keywords` 精確/前綴比對，優先於全文結果。
- 檢索品質以固定評測集比較（向量/lexical/混合），不以展示案例選擇。

## 影響

- 託管 PostgreSQL 多半無法安裝 PGroonga；此時需切換 `TrigramLexicalSearcher` 並接受召回下降。
