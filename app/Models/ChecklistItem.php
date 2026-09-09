<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChecklistItem extends Model { protected $fillable=['ticket_id','text','completed','completed_by','completed_at','position']; protected function casts():array{return ['completed'=>'boolean','completed_at'=>'datetime'];} }
