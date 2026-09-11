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
     * `Name` is the display name from the user's last login, null while the
     * assigned UID has never signed in. Read-only: the editor shows it next
     * to the UID field and the update endpoint ignores it.
     *
     * @return array{ID: int, UserID: string, Name: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'ID' => $this->resource->id,
            'UserID' => $this->resource->user_id,
            'Name' => $this->resource->user?->name,
        ];
    }
}
