<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProgress extends Model
{
    protected $table = 'user_progress';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function chapter() {
        return $this->belongsTo(Chapter::class);
    }
}
