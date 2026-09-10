<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $fillable = ['name', 'system_key', 'category', 'color', 'position', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function system(string $key): ?self
    {
        return static::where('system_key', $key)->first();
    }
}
