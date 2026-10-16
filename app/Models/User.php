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
