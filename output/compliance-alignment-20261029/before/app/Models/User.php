<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    private ?string $issuedAccessToken = null;

    public function setApiTokenAttribute(?string $value): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'api_token_hash')) {
            $this->issuedAccessToken = $value;
            $this->attributes['api_token'] = null;
            $this->attributes['api_token_hash'] = $value ? hash('sha256', $value) : null;
            $this->attributes['api_expires_at'] = $value ? now()->addMinutes(min(config('security.token_minutes'), config('security.session_absolute_minutes')))->toDateTimeString() : null;
            $this->attributes['api_authenticated_at'] = $value ? now()->toDateTimeString() : null;
        } else {
            abort_unless(app()->environment('testing'), 503, 'Apply the security migration first.');
            $this->attributes['api_token'] = $value;
        }
    }
    public function getApiTokenAttribute(?string $value): ?string
    {
        return $this->issuedAccessToken ?? $value;
    }
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'api_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_device_hash',
        'api_token', 'api_token_hash', 'api_expires_at', 'api_authenticated_at', 'api_mfa_verified_at', 'mfa_secret', 'mfa_pending_secret', 'mfa_pending_at', 'mfa_last_counter', 'mfa_recovery_hashes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'api_expires_at'=>'datetime','api_authenticated_at'=>'datetime','api_mfa_verified_at'=>'datetime','mfa_pending_at'=>'datetime',
        ];
    }
}
