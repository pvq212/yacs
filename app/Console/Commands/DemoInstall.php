<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AccessControl\Models\Role;
use App\Modules\AccessControl\Models\RoleBinding;
use App\Modules\AccessControl\RoleTemplates;
use App\Modules\Identity\Models\User;
use App\Modules\Knowledge\KnowledgeIndex;
use App\Modules\Workspaces\Actions\ProvisionWorkspace;
use App\Modules\Workspaces\Models\AgentCapacity;
use App\Modules\Workspaces\Models\Brand;
use App\Modules\Workspaces\Models\Inbox;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Database\Records as R;
use App\Support\Security\Tokens;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

final class DemoInstall extends Command
{
    protected $signature = 'yacs:demo';

    protected $description = '在 local/testing 建立可操作的示範客服系統（重複執行不重設帳號）';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('示範安裝只允許 local/testing。');

            return 1;
        }
        if (Storage::disk('private')->exists('demo-access.json')) {
            $this->line(Storage::disk('private')->get('demo-access.json'));

            return 0;
        }
        $tenant = app(TenantDatabase::class);
        $access = [];
        DB::beginTransaction();
        $owner = new User;
        $password = bin2hex(random_bytes(12));
        $owner->forceFill(['email' => 'owner@yacs.test', 'email_normalized' => 'owner@yacs.test', 'name' => '工作空間管理員', 'password_hash' => Hash::make($password), 'status' => 'active'])->save();
        $workspace = app(ProvisionWorkspace::class)->handle('YACS 示範客服', 'yacs-demo', $owner);
        $access['owner'] = ['email' => $owner->email, 'password' => $password];
        $access['workspace_id'] = $workspace->id;
        $tenant->withinWorkspace($workspace->id, function () use ($workspace, &$access): void {
            $brand = new Brand;
            $brand->forceFill(['workspace_id' => $workspace->id, 'name' => '日常選物', 'slug' => 'demo-store', 'status' => 'active', 'settings' => ['theme' => ['display_name' => '日常選物客服', 'primary_color' => '#425bd4']]])->save();
            $inbox = new Inbox;
            $inbox->forceFill(['workspace_id' => $workspace->id, 'brand_id' => $brand->id, 'name' => '網站客服', 'public_key' => Tokens::publicKey('ibx_'), 'channel_type' => 'web', 'status' => 'active', 'ai_mode' => 'disabled', 'settings' => []])->save();
            foreach (array_unique([rtrim(config('app.url'), '/'), 'http://localhost:8000', 'http://127.0.0.1:8000']) as $origin) {
                DB::table('inbox_origins')->insert(['id' => R::id(), 'workspace_id' => $workspace->id, 'inbox_id' => $inbox->id, 'origin' => $origin, 'created_at' => now()]);
            }
            $user = new User;
            $password = bin2hex(random_bytes(12));
            $user->forceFill(['email' => 'agent@yacs.test', 'email_normalized' => 'agent@yacs.test', 'name' => '客服小安', 'password_hash' => Hash::make($password), 'status' => 'active'])->save();
            $member = new WorkspaceMembership;
            $member->forceFill(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'display_name' => '客服小安', 'status' => 'active'])->save();
            (new AgentCapacity)->forceFill(['workspace_id' => $workspace->id, 'membership_id' => $member->id, 'max_active' => 5, 'presence_status' => 'offline', 'updated_at' => now()])->save();
            (new RoleBinding)->forceFill(['workspace_id' => $workspace->id, 'membership_id' => $member->id, 'role_id' => Role::query()->where('key', RoleTemplates::AGENT)->value('id'), 'scope_type' => 'inbox', 'scope_id' => $inbox->id, 'created_at' => now()])->save();
            DB::table('inbox_memberships')->insert(['id' => R::id(), 'created_at' => now(), 'workspace_id' => $workspace->id, 'membership_id' => $member->id, 'inbox_id' => $inbox->id]);
            $access['agent'] = ['email' => $user->email, 'password' => $password];
            $access['inbox_key'] = $inbox->public_key;
            $access['inbox_id'] = $inbox->id;
            $kb = R::id();
            DB::table('knowledge_bases')->insert(['id' => $kb, 'workspace_id' => $workspace->id, 'brand_id' => $brand->id, 'name' => '購物常見問題', 'default_locale' => 'zh_TW', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inbox_knowledge_bases')->insert(['id' => R::id(), 'workspace_id' => $workspace->id, 'inbox_id' => $inbox->id, 'knowledge_base_id' => $kb, 'priority' => 0]);
            foreach (['如何申請退款？' => '收到商品後七日內，請到會員中心的訂單頁點選「申請退款」。請保留完整包裝與訂單編號，客服會協助確認。', '配送需要幾天？' => '訂單確認後約二至三個工作天出貨。實際配送時間依物流狀況而定，您可以在會員中心查看出貨進度。'] as $title => $text) {
                $document = R::id();
                $version = R::id();
                DB::table('knowledge_documents')->insert(['id' => $document, 'workspace_id' => $workspace->id, 'knowledge_base_id' => $kb, 'title' => $title, 'source_type' => 'faq', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('knowledge_versions')->insert(['id' => $version, 'workspace_id' => $workspace->id, 'document_id' => $document, 'version_number' => 1, 'visibility' => 'external_answerable', 'locale' => 'zh_TW', 'title' => $title, 'body_text' => $text, 'content_hash' => hash('sha256', $text), 'created_at' => now(), 'updated_at' => now()]);
                app(KnowledgeIndex::class)->index($version);
                DB::table('knowledge_versions')->where('id', $version)->update(['state' => 'published', 'published_at' => now()]);
                DB::table('knowledge_documents')->where('id', $document)->update(['published_version_id' => $version, 'status' => 'published']);
            }
            DB::table('workspaces')->where('id', $workspace->id)->increment('knowledge_generation');
        });
        DB::commit();
        Storage::disk('private')->put('demo-access.json', json_encode($access, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('示範環境已建立。此檔僅包含本機示範帳號：storage/app/private/demo-access.json');
        $this->line(json_encode($access, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
