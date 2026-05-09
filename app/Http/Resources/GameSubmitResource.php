<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GameSubmitResource extends JsonResource
{
    private $totalPoints;

    public function __construct($resource, $totalPoints = 0)
    {
        parent::__construct($resource);
        $this->totalPoints = $totalPoints;
    }

    public function toArray($request)
    {
        return [
            'session_id' => (int) $this->id,
            'score' => (int) $this->score,
            'correct_count' => (int) $this->correct_count,
            'total_questions' => (int) $this->question_count,
            'max_streak' => (int) $this->max_streak,
            'max_combo' => (int) $this->max_combo,
            'is_perfect' => (bool) $this->is_perfect,
            'total_points' => (int) $this->totalPoints,
        ];
    }

    public static function withTotalPoints($session, $totalPoints)
    {
        return new static($session, $totalPoints);
    }
}
