<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uid
 * @property string|null $name
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @var list<string> */
    protected $fillable = ['uid', 'name', 'email'];

    /** @var list<string> */
    protected $hidden = ['remember_token'];

    public function isGlobalAdmin(): bool
    {
        /** @var list<string> $admins */
        $admins = (array) config('meldeplattform.admin_users', []);

        return in_array($this->uid, $admins, true);
    }
}
