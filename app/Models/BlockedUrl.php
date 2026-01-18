<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedUrl extends Model
{
    protected $fillable = ['pattern', 'reason'];
}
