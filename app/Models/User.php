<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'school_id', 'phone', 'profile', 'settings',
    'kyc_status', 'kyc_tier', 'kyc_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    /**
     * Roles a user may assign to themselves through public registration.
     *
     * @var list<string>
     */
    public const SELF_REGISTERABLE_ROLES = ['student', 'parent'];

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
            'profile' => 'array',
            'settings' => 'array',
            'kyc_verified_at' => 'datetime',
            'kyc_tier' => 'integer',
        ];
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Self-registered users must confirm their email address; administrators
     * are provisioned internally and are exempt.
     */
    public function requiresEmailVerification(): bool
    {
        return ! $this->isAdmin();
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'student_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'student_id');
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->latestOfMany();
    }

    public function walletTopups(): HasMany
    {
        return $this->hasMany(WalletTopup::class);
    }

    /**
     * Students this user is a guardian for.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardians', 'parent_id', 'student_id')
            ->withPivot(['school_id', 'relationship'])
            ->withTimestamps();
    }

    /**
     * Guardians linked to this student.
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardians', 'student_id', 'parent_id')
            ->withPivot(['school_id', 'relationship'])
            ->withTimestamps();
    }
}
