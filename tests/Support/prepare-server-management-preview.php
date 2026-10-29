<?php
// Isolated UI fixture only. No application/production database may be used.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = config('database.connections.sqlite.database');
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || ! str_starts_with($path, '/private/tmp/nuers-infra-preview-')) throw new RuntimeException('Use an isolated /private/tmp/nuers-infra-preview-* SQLite database.');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
$token=Illuminate\Support\Str::random(64);
$user=App\Models\User::factory()->create(['name'=>'NUERS Lab Administrator','email'=>'infra-preview@example.test','password'=>'Synthetic-preview-only-123!','api_token'=>$token]);
$user->forceFill(['api_device_hash'=>hash('sha256','isolated-infrastructure-preview'),'mfa_secret'=>Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP'),'api_mfa_verified_at'=>now()])->save();
Illuminate\Support\Facades\DB::table('profiles')->insert(['id'=>$user->id,'email'=>$user->email,'full_name'=>'NUERS Lab Administrator','role'=>'super_admin']);
file_put_contents('/private/tmp/nuers-infra-preview-session.json',json_encode(['token'=>$token,'device'=>'isolated-infrastructure-preview']));
chmod('/private/tmp/nuers-infra-preview-session.json',0600);
echo "Isolated preview initialized; synthetic credentials written privately.\n";
