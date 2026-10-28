<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create (or update) the developer login: an Admin account flagged as developer, member of every company with access
 * to every active branch. Developers manage admin logins and switch sections off per company (/developer).
 */
class MakeDeveloper extends Command
{
    protected $signature = 'erp:developer {email} {--name=Developer} {--password= : Leave out to generate one (shown once)}';

    protected $description = 'Create or update the developer login that manages admins and company sections';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Give a valid email address.'); return self::FAILURE; }
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
        if (strlen($password) < 8) { $this->error('The password needs at least 8 characters.'); return self::FAILURE; }

        DB::transaction(function () use ($email, $password) {
            $user = User::where('email', $email)->where('is_deleted', false)->first()
                ?? new User(['email' => $email, 'name' => $this->option('name'), 'phone' => '']);
            if (!$user->exists && User::where('name', $this->option('name'))->where('is_deleted', false)->exists()) {
                $user->name = $this->option('name').' '.Str::upper(Str::random(4));
            }
            $user->forceFill(['password' => bcrypt($password), 'role_id' => 1, 'is_developer' => true, 'is_active' => true, 'is_deleted' => false])->save();
            foreach (DB::table('companies')->pluck('id') as $companyId) {
                if (!DB::table('company_user')->where(['company_id' => $companyId, 'user_id' => $user->id])->exists()) {
                    DB::table('company_user')->insert(['company_id' => $companyId, 'user_id' => $user->id,
                        'is_default' => !DB::table('company_user')->where('user_id', $user->id)->exists(), 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach (DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $branchId) {
                    DB::table('company_user_branches')->updateOrInsert(['company_id' => $companyId, 'user_id' => $user->id, 'branch_id' => $branchId],
                        ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        $this->info('Developer login ready: '.$email);
        if (!$this->option('password')) $this->line('Password (shown once, change it after signing in): '.$password);
        $this->line('Open /developer after signing in.');
        return self::SUCCESS;
    }
}
