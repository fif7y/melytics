<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $fillable = ['name', 'domain', 'timezone', 'retention_days', 'tier2_enabled', 'digest_enabled', 'alerts_enabled'];

    protected $casts = ['tier2_enabled' => 'bool'];

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            $site->key ??= Str::random(12);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hits(): HasMany
    {
        return $this->hasMany(Hit::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function shareLink(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShareLink::class);
    }
}
