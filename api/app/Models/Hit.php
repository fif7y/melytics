<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['event_props' => 'array', 'created_at' => 'datetime'];
}
