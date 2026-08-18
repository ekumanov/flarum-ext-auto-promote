<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class UserPolicy extends AbstractPolicy
{
    /**
     * May the actor add this user to, or remove them from, the watchlist?
     */
    public function watchlist(User $actor, User $user): ?string
    {
        return $this->staffAction($actor, $user);
    }

    /**
     * May the actor hand this user the trusted group by hand?
     */
    public function promote(User $actor, User $user): ?string
    {
        return $this->staffAction($actor, $user);
    }

    protected function staffAction(User $actor, User $user): ?string
    {
        // An explicit deny outranks the administrator bypass in Gate, so these
        // two hold for admins as well: nobody flags themselves, and an
        // administrator account is never a promotion or watchlist target.
        if ($actor->id === $user->id || $user->isAdmin()) {
            return $this->deny();
        }

        if ($actor->hasPermission('ekumanov-auto-promote.manage-watchlist')) {
            return $this->allow();
        }

        // Undecided: Gate falls back to the administrator bypass.
        return null;
    }
}
