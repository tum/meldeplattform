<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uid
 * @property string|null $name
 * @property string|null $email
 * @property bool $is_global_admin
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['uid', 'name', 'email', 'is_global_admin', 'last_login_at'];

    /** @var list<string> */
    protected $hidden = ['remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_global_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * A user is a global admin when either the env-driven allowlist
     * (`MELDE_ADMIN_USERS`) names their UID, or the `is_global_admin`
     * column has been flipped via the /users admin UI. Env wins as a
     * bootstrap path so the platform can never be locked out by a bad
     * DB state.
     */
    public function isGlobalAdmin(): bool
    {
        if ($this->is_global_admin) {
            return true;
        }

        /** @var list<string> $admins */
        $admins = (array) config('meldeplattform.admin_users', []);

        return in_array($this->uid, $admins, true);
    }

    /**
     * Whether this user administers anything at all: a global admin, or a
     * topic admin with at least one topic assigned. Everyone else who can
     * sign in (any TUM member, because topics may require login to report)
     * is a regular user and gets no admin surface — no dashboard, no topic
     * index, no editor helpers. Memoised per instance because the layout
     * asks on every page.
     */
    public function isAdministrator(): bool
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }

        return $this->isTopicAdmin ??= Admin::query()
            ->where('user_id', $this->uid)
            ->whereHas('topics')
            ->exists();
    }

    private ?bool $isTopicAdmin = null;

    /**
     * True when the env config is responsible for this user's global-admin
     * status. Used by the /users UI to render an explanatory chip and to
     * disable the demote toggle (you can't demote what env grants).
     */
    public function isGlobalAdminViaEnv(): bool
    {
        /** @var list<string> $admins */
        $admins = (array) config('meldeplattform.admin_users', []);

        return in_array($this->uid, $admins, true);
    }
}
