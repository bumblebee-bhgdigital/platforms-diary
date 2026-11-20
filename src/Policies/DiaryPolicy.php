<?php

namespace Platform\Diary\Policies;

use Platform\Core\Authz\AuthzResolver;
use Platform\Core\Models\User;
use Platform\Core\Policies\RolePolicy;
use Platform\Diary\Models\Diary;

/**
 * Kein eigenes Rollensystem — gleiche Graph-Autorisierung wie PlannerProjectPolicy:
 * Ersteller (owns) ODER strukturell im Org-Graphen erreichbar (may). read < write < manage.
 * Zusätzlich darf der im Diary eingetragene Owner (owner_user_id) lesen/ändern.
 * Teilen = das Diary im Graphen (an Knoten) aufhängen.
 */
class DiaryPolicy extends RolePolicy
{
    public function view(User $user, $diary): bool
    {
        return $this->graphAllows($user, $diary, 'read');
    }

    public function update(User $user, $diary): bool
    {
        return $this->graphAllows($user, $diary, 'write');
    }

    public function delete(User $user, $diary): bool
    {
        return $this->graphAllows($user, $diary, 'manage');
    }

    public function forceDelete(User $user, $diary): bool
    {
        return $this->graphAllows($user, $diary, 'manage');
    }

    public function create(User $user): bool
    {
        // Jedes Team-Mitglied kann Diaries anlegen (wird sein Ersteller).
        return $user->currentTeam !== null;
    }

    protected function graphAllows(User $user, $diary, string $cap): bool
    {
        if (! $diary || ! $diary->id) {
            return false;
        }

        if ($diary->owner_user_id && (int) $diary->owner_user_id === (int) $user->id) {
            return true;
        }

        $resolver = app(AuthzResolver::class);
        $type = Diary::class;

        return $resolver->may($user, $cap, $type, (int) $diary->id)
            || $resolver->owns($user, $type, (int) $diary->id);
    }

    protected function getUserRole(User $user, $model): ?string
    {
        return null;
    }
}
