<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Leaderboard extends Model
{
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class); // User class needs to exist, normally App\Models\User.
    }
}
