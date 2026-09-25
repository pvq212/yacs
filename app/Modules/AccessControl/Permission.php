<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

/**
 * 權限碼目錄（docs/spec/SPEC.md §07.1）。
 *
 * 新增權限時：加 case、補 category()/description()、更新角色模板（RoleTemplates）
 * 與前端權限顯示；PermissionCatalogSeeder 會同步到 permissions 表。
 */
enum Permission: string
{
    case WorkspaceManage = 'workspace.manage';
    case BrandManage = 'brand.manage';
    case InboxManage = 'inbox.manage';
    case StaffManage = 'staff.manage';
    case RolesAssign = 'roles.assign';
    case ConversationRead = 'conversation.read';
    case ConversationClaim = 'conversation.claim';
    case ConversationReply = 'conversation.reply';
    case ConversationNote = 'conversation.note';
    case ConversationAssign = 'conversation.assign';
    case ConversationResolve = 'conversation.resolve';
    case ConversationReopen = 'conversation.reopen';
    case ConversationAssistOther = 'conversation.assist_other';
    case ContactReadSensitive = 'contact.read_sensitive';
    case KnowledgeEdit = 'knowledge.edit';
    case KnowledgePublish = 'knowledge.publish';
    case AiManage = 'ai.manage';
    case IntegrationManage = 'integration.manage';
    case AutomationManage = 'automation.manage';
    case ReportRead = 'report.read';
    case ExportCreate = 'export.create';
    case AuditRead = 'audit.read';
    case DataErase = 'data.erase';

    public function category(): string
    {
        return explode('.', $this->value)[0];
    }

    public function description(): string
    {
        return match ($this) {
            self::WorkspaceManage => '管理 workspace 基本設定、資安與資料治理',
            self::BrandManage => '管理品牌',
            self::InboxManage => '管理收件匣、嵌入來源、營業時間與分派設定',
            self::StaffManage => '邀請、啟用/停用人員、團隊與座席容量',
            self::RolesAssign => '建立角色與授予角色（不可超出自身權限）',
            self::ConversationRead => '讀取範圍內對話',
            self::ConversationClaim => '接取可接的佇列案件',
            self::ConversationReply => '對會員公開回覆',
            self::ConversationNote => '新增內部備註',
            self::ConversationAssign => '轉派案件給其他客服或團隊',
            self::ConversationResolve => '結案',
            self::ConversationReopen => '重新開啟已結案對話',
            self::ConversationAssistOther => '查看並協助其他客服已接的案件（主管）',
            self::ContactReadSensitive => '查看客戶敏感欄位（email、電話等）',
            self::KnowledgeEdit => '建立與編輯知識草稿、匯入與索引',
            self::KnowledgePublish => '審閱並發布/撤下知識',
            self::AiManage => '管理 AI 供應商、模型與設定',
            self::IntegrationManage => '管理 API token、身分簽發者與 Webhook',
            self::AutomationManage => '管理巨集、標籤、結案原因、客戶欄位與規則',
            self::ReportRead => '查看報表',
            self::ExportCreate => '建立資料匯出',
            self::AuditRead => '查看稽核紀錄',
            self::DataErase => '執行資料刪除要求',
        };
    }

    /**
     * 只能在 workspace 層級授予（不可限縮到 brand/inbox/team）的權限。
     *
     * @return list<self>
     */
    public static function workspaceOnly(): array
    {
        return [
            self::WorkspaceManage, self::BrandManage, self::StaffManage, self::RolesAssign,
            self::AiManage, self::IntegrationManage, self::AutomationManage, self::AuditRead, self::DataErase,
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<self>
     */
    public static function fromCodes(array $codes): array
    {
        return array_values(array_filter(array_map(self::tryFrom(...), $codes)));
    }
}
