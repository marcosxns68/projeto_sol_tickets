<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConnectedSystem extends Model { protected $table='systems'; protected $fillable=['company_id','name','base_url','webhook_url','active']; protected $hidden=['api_token_hash','webhook_secret']; public function company(){return $this->belongsTo(Company::class);} }
