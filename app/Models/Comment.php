<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Comment extends Model { protected $fillable=['ticket_id','user_id','visibility','body','source','message_id']; public function user(){return $this->belongsTo(User::class);} }
