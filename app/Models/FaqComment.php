<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FaqComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_id',
        'faq_article_id',
        'user_id',
        'name',
        'content',
        'ip_address',
        'is_approved',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($comment) {
            if (empty($comment->public_id)) {
                $comment->public_id = (string) Str::uuid();
            }
        });
    }

    public function article()
    {
        return $this->belongsTo(FaqArticle::class, 'faq_article_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }
}
