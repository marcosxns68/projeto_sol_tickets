<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model { protected $fillable=['name','protected']; protected function casts():array{return ['protected'=>'boolean'];} public function permissions(){return $this->belongsToMany(Permission::class);} }
