<?php

namespace App\Http\Resources;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Admin $resource
 */
class AdminResource extends JsonResource
{
    /**
     * @return array{ID: int, UserID: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'ID' => $this->resource->id,
            'UserID' => $this->resource->user_id,
        ];
    }
}
