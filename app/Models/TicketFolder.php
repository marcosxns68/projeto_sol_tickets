<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketFolder extends Model
{
    protected $fillable = ['department_id', 'parent_id', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'folder_id');
    }

    public function belongsToDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->id : (int) $department;

        return (int) $this->department_id === $departmentId;
    }
}
