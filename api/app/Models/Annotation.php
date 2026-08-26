<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Annotation extends Model
{
    protected $fillable = ['day', 'text'];

    protected $casts = ['day' => 'date:Y-m-d'];
}
