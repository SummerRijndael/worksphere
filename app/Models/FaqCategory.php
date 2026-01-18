<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FaqCategory extends Model
{
    use HasFactory, \Laravel\Scout\Searchable, SoftDeletes;

    protected $fillable = ['public_id', 'name', 'slug', 'description', 'order', 'is_public', 'author_id'];

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
        ];
    }

    protected $casts = [
        'is_public' => 'boolean',
        'order' => 'integer',
    ];

    public function getRouteKeyName()
    {
        return 'public_id';
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($category) {
            if (empty($category->public_id)) {
                $category->public_id = (string) Str::uuid();
            }
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function articles(): HasMany
    {
        return $this->hasMany(FaqArticle::class, 'category_id')->orderBy('title');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
