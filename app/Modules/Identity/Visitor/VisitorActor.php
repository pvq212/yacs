<?php

declare(strict_types=1);

namespace App\Modules\Identity\Visitor;

use App\Support\Http\Principal;

/** 此上下文只能由已驗證的伺服器 session 建立，客戶端不能指定 contact 或租戶。 */
final readonly class VisitorActor implements Principal
{
    public function __construct(public object $session) {}

    public function workspaceId(): string
    {
        return $this->session->workspace_id;
    }

    public function principalScope(): string
    {
        return 'visitor:'.$this->session->id;
    }

    public function contactId(): string
    {
        return $this->session->contact_id;
    }

    public function inboxId(): string
    {
        return $this->session->inbox_id;
    }
}
