<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FaqArticle extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, \Laravel\Scout\Searchable, SoftDeletes;

    protected $fillable = [
        'public_id',
        'category_id',
        'title',
        'slug',
        'content',
        'is_published',
        'views',
        'helpful_count',
        'unhelpful_count',
        'author_id',
        'tags',
    ];

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'category_id' => $this->category_id,
            'tags' => $this->tags,
        ];
    }

    protected $casts = [
        'is_published' => 'boolean',
        'views' => 'integer',
        'helpful_count' => 'integer',
        'unhelpful_count' => 'integer',
        'tags' => 'array',
    ];

    public function getRouteKeyName()
    {
        return 'public_id';
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($article) {
            if (empty($article->public_id)) {
                $article->public_id = (string) Str::uuid();
            }
            if (empty($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FaqComment::class, 'faq_article_id');
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FaqArticleVersion::class, 'faq_article_id')->orderBy('created_at', 'desc');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('faq_media')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']);

        $this->addMediaCollection('attachments')
            ->useDisk('private');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('preview')
            ->width(800)
            ->height(600)
            ->performOnCollections('images');
    }
}
