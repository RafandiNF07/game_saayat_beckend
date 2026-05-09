<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mode',
        'scope',
        'question_count',
        'reciter_id',
        'status',
        'started_at',
        'finished_at',
        'correct_count',
        'score',
        'max_streak',
        'max_combo',
        'is_perfect',
    ];

    protected $casts = [
        'scope' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'is_perfect' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function questions()
    {
        return $this->hasMany(GameSessionQuestion::class);
    }
}
