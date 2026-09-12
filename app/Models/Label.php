<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Label extends Model
{
    protected $fillable = ['name', 'color', 'system'];

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    public function tickets()
    {
        return $this->belongsToMany(Ticket::class);
    }
}
