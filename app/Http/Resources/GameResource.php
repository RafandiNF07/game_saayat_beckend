<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'status' => 'success',
            'message' => 'Data retrieved successfully',
            'data' => parent::toArray($request)
        ];
    }
}
