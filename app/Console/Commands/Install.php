<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Actions\ProvisionWorkspace;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class Install extends Command
{
    protected $signature = 'yacs:install {--email=} {--name=} {--workspace=} {--slug=} {--password-stdin} {--platform-operator}';

    protected $description = '建立第一位管理員與工作空間；密碼由互動或 stdin 輸入，不放在命令參數';

    public function handle(): int
    {
        $input = ['email' => $this->option('email') ?: $this->ask('管理員電子郵件'), 'name' => $this->option('name') ?: $this->ask('管理員名稱'), 'workspace' => $this->option('workspace') ?: $this->ask('工作空間名稱'), 'slug' => $this->option('slug') ?: $this->ask('工作空間識別名稱（英數、連字號）'), 'password' => $this->option('password-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : $this->secret('管理員密碼（至少 12 字元）')];
        $validation = Validator::make($input, ['email' => 'required|email|max:255', 'name' => 'required|string|max:100', 'workspace' => 'required|string|max:100', 'slug' => 'required|regex:/^[a-z0-9][a-z0-9-]{1,62}$/', 'password' => 'required|string|min:12|max:1024']);
        if ($validation->fails()) {
            $this->error('輸入格式錯誤：'.implode('；', $validation->errors()->all()));

            return 1;
        }
        if (! config('app.key') || ! config('yacs.secrets.keys')) {
            $this->error('請先執行 yacs:generate-keys，再啟動新的程序。');

            return 1;
        }
        $exists = app(TenantDatabase::class)->asSystem(fn () => User::query()->where('email_normalized', User::normalizeEmail($input['email']))->exists() || Workspace::query()->where('slug', $input['slug'])->exists());
        if ($exists) {
            $this->error('管理員或工作空間已存在；不會覆寫既有帳號。');

            return 1;
        }
        $workspace = DB::transaction(function () use ($input): Workspace {
            $user = new User;
            $user->forceFill(['email' => $input['email'], 'email_normalized' => User::normalizeEmail($input['email']), 'name' => $input['name'], 'password_hash' => Hash::make($input['password']), 'status' => 'active', 'is_platform_operator' => (bool) $this->option('platform-operator')])->save();

            return app(ProvisionWorkspace::class)->handle($input['workspace'], $input['slug'], $user);
        });
        $this->info('工作空間已建立：'.$workspace->id.'。登入 /ops 後新增品牌與收件匣。');

        return 0;
    }
}
