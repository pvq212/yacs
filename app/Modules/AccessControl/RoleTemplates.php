<?php

declare(strict_types=1);

namespace App\Modules\AccessControl;

/**
 * 系統角色模板（docs/spec/SPEC.md §07.1）。
 *
 * 建立 workspace 時複製為該 workspace 的角色（is_system_template = true）。
 * Owner 之後可調整模板角色的權限，但模板角色不可刪除。
 */
final class RoleTemplates
{
    public const OWNER = 'owner';

    public const OPERATIONS_ADMIN = 'operations_admin';

    public const SUPERVISOR = 'supervisor';

    public const AGENT = 'agent';

    public const KNOWLEDGE_EDITOR = 'knowledge_editor';

    public const KNOWLEDGE_PUBLISHER = 'knowledge_publisher';

    public const AUDITOR = 'auditor';

    /**
     * @return array<string, array{name: string, description: string, permissions: list<Permission>}>
     */
    public static function all(): array
    {
        $conversationAgent = [
            Permission::ConversationRead, Permission::ConversationClaim, Permission::ConversationReply,
            Permission::ConversationNote, Permission::ConversationResolve, Permission::ConversationReopen,
        ];
        $conversationSupervisor = [
            ...$conversationAgent, Permission::ConversationAssign, Permission::ConversationAssistOther,
            Permission::ContactReadSensitive,
        ];

        return [
            self::OWNER => [
                'name' => 'Owner',
                'description' => 'workspace 擁有者：全部權限；secret 仍只能寫入、不可讀取原文。',
                'permissions' => Permission::cases(),
            ],
            self::OPERATIONS_ADMIN => [
                'name' => '運營管理員',
                'description' => '收件匣、人員、AI/知識與串接設定；不能賦予自己沒有的權限。',
                'permissions' => [
                    ...$conversationSupervisor,
                    Permission::BrandManage, Permission::InboxManage, Permission::StaffManage, Permission::RolesAssign,
                    Permission::KnowledgeEdit, Permission::KnowledgePublish, Permission::AiManage,
                    Permission::IntegrationManage, Permission::AutomationManage, Permission::ReportRead,
                    Permission::ExportCreate,
                ],
            ],
            self::SUPERVISOR => [
                'name' => '主管',
                'description' => '指定團隊/收件匣的案件監督、轉派、報表與協助回覆。',
                'permissions' => [...$conversationSupervisor, Permission::ReportRead],
            ],
            self::AGENT => [
                'name' => '客服',
                'description' => '處理自己負責的案件與可接的未分派案件。',
                'permissions' => $conversationAgent,
            ],
            self::KNOWLEDGE_EDITOR => [
                'name' => '知識編輯',
                'description' => '知識草稿、匯入與索引；不含發布權限與對話內容權限。',
                'permissions' => [Permission::KnowledgeEdit],
            ],
            self::KNOWLEDGE_PUBLISHER => [
                'name' => '知識發布者',
                'description' => '審閱並發布核准範圍內的知識。',
                'permissions' => [Permission::KnowledgeEdit, Permission::KnowledgePublish],
            ],
            self::AUDITOR => [
                'name' => '稽核員',
                'description' => '範圍內稽核紀錄與報表；聊天原文與匯出需另外授權。',
                'permissions' => [Permission::AuditRead, Permission::ReportRead],
            ],
        ];
    }
}
