<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShareLink extends Model
{
    protected $fillable = ['enabled', 'password_hash'];

    protected $casts = ['enabled' => 'bool'];

    protected $hidden = ['password_hash'];

    protected $appends = ['has_password'];

    protected static function booted(): void
    {
        static::creating(function (ShareLink $link) {
            $link->token ??= Str::random(20);
        });
    }

    public function getHasPasswordAttribute(): bool
    {
        return $this->password_hash !== null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
