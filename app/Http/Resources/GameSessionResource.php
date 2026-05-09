<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GameSessionResource extends JsonResource
{
    private $questions;

    public function __construct($resource, $questions = [])
    {
        parent::__construct($resource);
        $this->questions = $questions;
    }

    public function toArray($request)
    {
        return [
            'session_id' => (int) $this->id,
            'mode' => $this->mode,
            'jumlah_soal' => (int) $this->question_count,
            'questions' => $this->questions,
        ];
    }

    public static function withQuestions($session, $questions)
    {
        return new static($session, $questions);
    }
}
