<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Verse extends Model
{
    use HasFactory;

    public function translations()
    {
        return $this->hasMany(Translations::class, 'id_verse');
    }

    public function audio()
    {
        return $this->hasOne(VerseAudios::class, 'id_verse');
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class, 'id_chapter');
    }
}
