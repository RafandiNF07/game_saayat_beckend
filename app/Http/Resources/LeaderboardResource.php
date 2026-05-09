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
            'id' => (int) $this->id,
            'user' => [
                'id' => (int) $this->user?->id,
                'name' => $userName,
            ],
            'total_points' => (int) $this->total_points,
            'max_streak' => (int) $this->max_streak,
            'max_combo' => (int) $this->max_combo,
        ];
    }
}
