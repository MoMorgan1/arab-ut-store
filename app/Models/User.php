<?php

namespace App\Models;

use App\Customers\CustomerNumber;
use App\Enums\UserRole;
use App\Models\Concerns\HasPublicUlid;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $public_id
 * @property string|null $customer_number
 * @property string $first_name
 * @property string $last_name
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property UserRole $role
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Appends(['name'])]
#[Fillable(['first_name', 'last_name', 'email', 'phone', 'password', 'preferred_locale', 'display_currency'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUlid, Notifiable, TwoFactorAuthenticatable;

    /**
     * Customer accounts get a short, quotable number alongside the ULID public
     * id, assigned here because customers arrive through several paths
     * (registration, WhatsApp login, Google sign-in, guest checkout claim).
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->customer_number === null && $user->role === UserRole::Customer) {
                $user->customer_number = CustomerNumber::generate();
            }
        });
    }

    /**
     * Verification mail follows the account holder's preferred locale, and
     * WhatsApp-first customers without an email address have nothing to
     * verify, so the send is a silent no-op for them.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email === null) {
            return;
        }

        $this->notify(new VerifyEmailNotification(
            $this->preferred_locale === 'en' ? 'en' : 'ar',
        ));
    }

    /**
     * Password reset mail follows the account holder's preferred locale,
     * falling back to the Arabic default.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification(
            $token,
            $this->preferred_locale === 'en' ? 'en' : 'ar',
        ));
    }

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::get(
            fn (): string => trim($this->first_name.' '.$this->last_name),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<PhoneVerification, $this> */
    public function phoneVerifications(): HasMany
    {
        return $this->hasMany(PhoneVerification::class);
    }

    /** @return HasMany<TwoFactorTrustedDevice, $this> */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TwoFactorTrustedDevice::class);
    }

    /** @return HasMany<UserIdentityChange, $this> */
    public function identityChanges(): HasMany
    {
        return $this->hasMany(UserIdentityChange::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasOne<WalletAccount, $this> */
    public function walletAccount(): HasOne
    {
        return $this->hasOne(WalletAccount::class);
    }
}
