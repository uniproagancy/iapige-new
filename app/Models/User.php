<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'provider_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const CUSTOMER = 'customer';

    /* Read through getAttributeValue, not $this->role: the method shares its
       name with the column, and Eloquent would take the method for a relation. */
    public function role(): string
    {
        return $this->getAttributeValue('role')
            ?: ($this->getAttributeValue('is_admin') ? self::ADMIN : self::CUSTOMER);
    }

    public function isAdmin(): bool
    {
        return $this->role() === self::ADMIN;
    }

    public function isOperator(): bool
    {
        return $this->role() === self::OPERATOR;
    }

    /* Both roles reach the admin; what they may do there differs. */
    public function isStaff(): bool
    {
        return in_array($this->role(), [self::ADMIN, self::OPERATOR], true);
    }

    /* The same fallback role() applies, in SQL: a row that predates the column
       is whatever its is_admin flag says, so the list and the gate agree. */
    public function scopeWithRole($query, string $role)
    {
        if ($role === self::OPERATOR) {
            return $query->where('role', self::OPERATOR);
        }

        return $query->where(fn ($q) => $q
            ->where('role', $role)
            ->orWhere(fn ($legacy) => $legacy
                ->whereNull('role')
                ->where('is_admin', $role === self::ADMIN)));
    }

    public static function roles(): array
    {
        return [self::ADMIN, self::OPERATOR, self::CUSTOMER];
    }

    public static function normalisePhone(string $phone): string
    {
        return ltrim(preg_replace('/\D+/', '', $phone), '0');
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value ? static::normalisePhone($value) : null;
    }

    public function firstName(): string
    {
        return Str::of($this->name)->trim()->explode(' ')->first() ?: $this->name;
    }

    public function initials(): string
    {
        return Str::of($this->name)->trim()->explode(' ')->take(2)
            ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
    }

    public function wishlist(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }
}
