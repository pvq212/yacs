/** SupportDesk SDK v1 contract. Declaration only; no implementation is included. */
export type UUID = string;
export type Sequence = string; // Decimal bigint; never coerce to JavaScript Number.
export type ConnectionState = 'connecting' | 'connected' | 'reconnecting' | 'polling' | 'offline';
export interface WidgetContext {
  path?: string; // Path only: exclude query/hash and credentials.
  locale?: string;
  pageTitle?: string;
}
export interface InitOptions {
  baseUrl: string;
  inboxKey: string; // Public identifier, NOT a secret or authentication credential.
  mode?: 'bubble' | 'embedded';
  container?: HTMLElement | string;
  position?: 'bottom-right' | 'bottom-left';
  locale?: string;
  context?: WidgetContext;
  /** Called in the host page; retrieves a signed assertion from the HOST backend. */
  identityTokenProvider?: () => Promise<string>;
  nonce?: string; // CSP nonce for supported loader-created resources.
}
export interface PublicMessage {
  id: UUID;
  conversation_id: UUID;
  message_seq: Sequence;
  client_message_id: UUID | null;
  author_type: 'visitor' | 'staff' | 'ai' | 'system';
  kind: 'text' | 'attachment' | 'system';
  body_text: string;
  attachments: Array<{ id: UUID; name: string; mime: string; bytes: number }>;
  created_at: string;
  redacted: boolean;
  citations?: Array<{ label: string; title: string; public_url?: string | null }>;
}
export interface PublicConversation {
  id: UUID;
  status: 'open' | 'waiting_customer' | 'snoozed' | 'resolved';
  handling_mode: 'ai' | 'human_queue' | 'human';
  version: Sequence;
  last_event_seq: Sequence;
}
export interface SendMessageInput {
  conversationId?: UUID;
  text?: string;
  attachmentIds?: UUID[];
  /** Reuse on retries; SDK generates one if omitted. */
  clientMessageId?: UUID;
}
export interface SdkError {
  code: string;
  message: string;
  retryable: boolean;
  requestId?: string;
}
export interface WidgetEvents {
  ready: { inboxKey: string };
  opened: undefined;
  closed: undefined;
  'unread:changed': { count: number };
  'message:received': PublicMessage;
  'conversation:updated': PublicConversation;
  'connection:changed': { state: ConnectionState };
  'identity:changed': { level: 'anonymous' | 'verified' };
  error: SdkError;
}
export interface SupportDeskSDK {
  init(options: InitOptions): Promise<void>;
  open(): Promise<void>;
  close(): Promise<void>;
  toggle(): Promise<void>;
  identify(assertion: string): Promise<void>;
  /** Revoke server session before clearing local state; do not merely rename the user. */
  logout(): Promise<void>;
  setContext(context: WidgetContext): Promise<void>;
  /** Only attributes configured as visitor-editable are accepted. */
  setAttributes(attributes: Record<string, string | number | boolean | null>): Promise<void>;
  sendMessage(input: SendMessageInput): Promise<PublicMessage>;
  requestHuman(conversationId?: UUID): Promise<PublicConversation>;
  on<K extends keyof WidgetEvents>(event: K, listener: (payload: WidgetEvents[K]) => void): () => void;
  off<K extends keyof WidgetEvents>(event: K, listener: (payload: WidgetEvents[K]) => void): void;
  /** Unmount UI and close sockets; NOT a substitute for logout/revocation. */
  destroy(): Promise<void>;
}
declare global {
  interface Window { SupportDesk: SupportDeskSDK; }
}
