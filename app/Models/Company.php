<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Company extends Model { protected $fillable=['name','document','active']; public function systems(){return $this->hasMany(ConnectedSystem::class);} }
