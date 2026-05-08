<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name ?? 'Unknown',
            ],
            'total_points' => $this->total_points,
            'max_streak' => $this->max_streak,
            'max_combo' => $this->max_combo,
        ];
    }
}
