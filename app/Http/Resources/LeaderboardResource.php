<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardResource extends JsonResource
{
    public function toArray($request)
    {
        $userName = 'unknown';
        if ($this->user) {
            $userName = $this->user->name ?? explode('@', $this->user->email)[0];
        }

        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $userName,
            ],
            'total_points' => (int) $this->total_points,
            'max_streak' => $this->max_streak,
            'max_combo' => $this->max_combo,
        ];
    }
}
