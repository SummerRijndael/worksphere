<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'team_id',
        'name',
        'email',
        'contact_person',
        'phone',
        'address',
        'status',
        'slug',
        // public_id is auto-generated
    ];

    protected $hidden = [
        'id',
    ];

    protected $appends = [
        'initials',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Client $client): void {
            if (empty($client->public_id)) {
                $client->public_id = (string) Str::uuid();
            }

            if (empty($client->slug)) {
                $client->slug = self::generateSlug($client->name);
            }
        });

        static::updating(function (Client $client): void {
            if ($client->isDirty('name') && ! $client->isDirty('slug')) {
                $client->slug = self::generateSlug($client->name);
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Generate a unique slug from name.
     */
    public static function generateSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the clients's initials.
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }

        return $initials;
    }

    use \Laravel\Scout\Searchable;

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'status' => $this->status,
        ];
    }

    /**
     * Get the user account linked to this client (for portal access).
     *
     * @return BelongsTo<User, Client>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the team that owns the client.
     *
     * @return BelongsTo<Team, Client>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the invoices for this client.
     *
     * @return HasMany<Invoice>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the projects for this client.
     *
     * @return HasMany<Project>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
