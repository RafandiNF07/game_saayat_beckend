<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameSessionQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_session_id',
        'question_order',
        'verse_id',
        'option_verse_ids',
        'selected_verse_id',
        'attempts',
        'is_correct',
    ];

    protected $casts = [
        'option_verse_ids' => 'array',
        'is_correct' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function verse()
    {
        return $this->belongsTo(Verse::class, 'verse_id');
    }
}
