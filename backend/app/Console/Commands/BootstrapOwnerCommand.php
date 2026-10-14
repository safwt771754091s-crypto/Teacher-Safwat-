<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Create (or promote) the platform owner using credentials supplied through the
 * environment, never hardcoded. Run once on a fresh deployment:
 *
 *   OWNER_EMAIL=you@example.com OWNER_PASSWORD='strong-secret' \
 *       php artisan platform:bootstrap-owner
 *
 * Safe to run repeatedly: it will not overwrite an existing owner's password
 * unless OWNER_RESET_PASSWORD=true is also set.
 */
class BootstrapOwnerCommand extends Command
{
    protected $signature = 'platform:bootstrap-owner
        {--email= : Owner email (falls back to OWNER_EMAIL env)}
        {--password= : Owner password (falls back to OWNER_PASSWORD env)}
        {--name= : Display name (falls back to OWNER_NAME env)}';

    protected $description = 'Create or promote the platform owner account from environment secrets.';

    public function handle(): int
    {
        $email = $this->option('email') ?: env('OWNER_EMAIL');
        $password = $this->option('password') ?: env('OWNER_PASSWORD');
        $name = $this->option('name') ?: env('OWNER_NAME', config('platform.owner.name', 'المالك'));

        if (empty($email) || empty($password)) {
            $this->error('المطلوب: OWNER_EMAIL و OWNER_PASSWORD (عبر متغيرات البيئة أو الخيارات --email/--password).');
            $this->line('مثال: OWNER_EMAIL=you@example.com OWNER_PASSWORD=\'…\' php artisan platform:bootstrap-owner');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:10']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        $reset = filter_var(env('OWNER_RESET_PASSWORD', false), FILTER_VALIDATE_BOOLEAN);

        if ($existing) {
            $attributes = ['role' => User::ROLE_OWNER, 'status' => 'active'];
            if ($reset) {
                $attributes['password'] = Hash::make($password);
            }
            $existing->forceFill($attributes)->save();

            $this->info("تم ترقية الحساب {$email} إلى مالك المنصة.");
            if (! $reset) {
                $this->line('لم تُغيّر كلمة المرور (اضبط OWNER_RESET_PASSWORD=true لتغييرها).');
            }

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);

        $this->info("تم إنشاء حساب المالك {$email}.");

        return self::SUCCESS;
    }
}
