<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
        'is_sensitive',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
    ];

    /**
     * Get the value attribute with automatic decryption for sensitive values.
     */
    public function getValueAttribute($value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Decrypt if sensitive
        if ($this->is_sensitive) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Throwable) {
                // If decryption fails, return null
                return null;
            }
        }

        // Cast to appropriate type
        return $this->castValue($value, $this->type);
    }

    /**
     * Set the value attribute with automatic encryption for sensitive values.
     */
    public function setValueAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['value'] = null;

            return;
        }

        // Convert to string for storage
        $stringValue = $this->stringifyValue($value);

        // Encrypt if sensitive
        if ($this->is_sensitive ?? false) {
            $stringValue = Crypt::encryptString($stringValue);
        }

        $this->attributes['value'] = $stringValue;
    }

    /**
     * Cast a string value to the appropriate type.
     */
    protected function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json', 'array' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Convert a value to a string for storage.
     */
    protected function stringifyValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Scope to filter by group.
     */
    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Get a setting value by key with optional default.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    /**
     * Set a setting value by key.
     */
    public static function setValue(string $key, mixed $value, array $attributes = []): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            array_merge(['value' => $value], $attributes)
        );
    }
}
