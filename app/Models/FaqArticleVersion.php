<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqArticleVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'faq_article_id',
        'user_id',
        'title',
        'content',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(FaqArticle::class, 'faq_article_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
