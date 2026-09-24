<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Listener;

use Carbon\Carbon;
use Ekumanov\AutoPromote\Promoter;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Event\GroupsChanged;

/**
 * Makes a moderator's hand edit of the trusted group stick.
 *
 * Without this, unticking the trusted group in core's Edit User modal lasts at
 * most fifteen minutes: the user still meets the rules, so the next sweep (or
 * their next reply) puts them straight back. The removal is therefore recorded
 * as a watchlist entry — which already means exactly "never auto-promote this
 * account", is shown to staff with who/when/why, and is undone by the same
 * "Remove from watchlist" action as any other entry. A separate opt-out column
 * would have needed its own badge, its own UI and its own clearing path to say
 * the same thing.
 *
 * GroupsChanged is raised only by core's `groups` relationship setter, i.e. by
 * a person saving the Edit User modal. This extension's own promote/demote go
 * straight to the pivot table and never raise it, so there is no loop to guard
 * against. Core dispatches it after the save, once the new membership has been
 * synced, with the acting user filled in.
 */
class RespectManualGroupChange
{
    public function __construct(
        protected Promoter $promoter,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(GroupsChanged $event): void
    {
        $groupId = $this->promoter->regularGroupId();

        if ($groupId === null) {
            return;
        }

        $user = $event->user;

        // Core unsets the relation after syncing, so this is the new membership.
        $had = collect($event->oldGroups)->contains('id', $groupId);
        $has = $user->groups->contains('id', $groupId);

        if ($had && ! $has) {
            $this->recordManualRemoval($event, $groupId);
        } elseif (! $had && $has && $this->promoter->isWatched($user)) {
            // Handing the group back by hand is the same decision as the
            // "Promote" action, which clears the flag too. Leaving the flag on
            // would produce a watched Regular — a flag with no effect that
            // still shows staff an eye badge.
            $user->watched_at = null;
            $user->watched_by_user_id = null;
            $user->watch_reason = null;
            $user->save();
        }
    }

    protected function recordManualRemoval(GroupsChanged $event, int $groupId): void
    {
        $user = $event->user;

        // Moved into a staff group in the same edit (a Regular made moderator,
        // with the now-redundant Regular box unticked): staff are never
        // auto-promoted anyway, and the watchlist refuses staff targets.
        if ($this->promoter->isExemptStaff($user)) {
            return;
        }

        // Already flagged: keep the original entry and its attribution, as
        // re-flagging through the UI does.
        if ($this->promoter->isWatched($user)) {
            return;
        }

        $group = Group::find($groupId);

        $user->watched_at = Carbon::now();
        $user->watched_by_user_id = $event->actor?->id;
        $user->watch_reason = mb_substr($this->translator->trans('ekumanov-auto-promote.lib.manual_removal_reason', [
            'group' => $group?->name_singular ?? (string) $groupId,
        ]), 0, 255);
        $user->save();
    }
}
