<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Decides who belongs in the trusted ("Regular") group, and puts them there.
 */
class Promoter
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * The group members are promoted into, or null when the admin has not
     * picked one yet. Null disables auto-promotion entirely — a missing
     * setting must never mean "promote into some guessed group".
     */
    public function regularGroupId(): ?int
    {
        $id = (int) $this->settings->get('ekumanov-auto-promote.regular_group_id');

        return $id > 0 ? $id : null;
    }

    public function isRegular(User $user): bool
    {
        $groupId = $this->regularGroupId();

        return $groupId !== null && $user->groups->contains('id', $groupId);
    }

    public function isWatched(User $user): bool
    {
        return $user->watched_at !== null;
    }

    public function promote(User $user): void
    {
        $groupId = $this->regularGroupId();

        if ($groupId === null || $user->groups->contains('id', $groupId)) {
            return;
        }

        // The setting can outlive the group it points at (an admin deletes the
        // group but leaves the setting). Attaching a dangling id would trip the
        // pivot's foreign key and turn an ordinary reply into a 500, so check
        // first — one indexed lookup, and only on the rare promotion path.
        if (! Group::whereKey($groupId)->exists()) {
            return;
        }

        $user->groups()->attach($groupId);
        $user->unsetRelation('groups');
    }

    public function demote(User $user): void
    {
        $groupId = $this->regularGroupId();

        if ($groupId === null || ! $user->groups->contains('id', $groupId)) {
            return;
        }

        $user->groups()->detach($groupId);
        $user->unsetRelation('groups');
    }

    /**
     * Promote the user if they now meet every requirement. Safe to call as
     * often as we like — it is a no-op for anyone already promoted.
     */
    public function maybeAutoPromote(User $user): void
    {
        if ($this->regularGroupId() === null) {
            return;
        }

        // The whole point of the watchlist: a flagged account never graduates,
        // no matter how many innocuous posts it accumulates.
        if ($this->isWatched($user)) {
            return;
        }

        // Staff have their standing from their own groups and gain nothing from
        // the trusted badge. Mirrors the groups the previous extend.php snippet
        // skipped.
        if ($user->isAdmin() || $user->groups->contains('id', Group::MODERATOR_ID)) {
            return;
        }

        if ($this->isRegular($user)) {
            return;
        }

        if (! $this->isOldEnough($user)) {
            return;
        }

        if ($this->approvedPostCount($user) < $this->requiredPosts()) {
            return;
        }

        $this->promote($user);
    }

    public function requiredPosts(): int
    {
        return max(1, (int) $this->settings->get('ekumanov-auto-promote.required_posts', 3));
    }

    public function minAccountAgeHours(): int
    {
        return max(0, (int) $this->settings->get('ekumanov-auto-promote.min_account_age_hours', 0));
    }

    protected function isOldEnough(User $user): bool
    {
        $hours = $this->minAccountAgeHours();

        if ($hours === 0) {
            return true;
        }

        // No join date recorded (possible on very old imported accounts): treat
        // the age requirement as met rather than locking the account out forever.
        if ($user->joined_at === null) {
            return true;
        }

        return $user->joined_at->addHours($hours)->isPast();
    }

    /**
     * Posts that count toward promotion: visible replies the user actually
     * wrote.
     */
    public function approvedPostCount(User $user): int
    {
        $query = $user->posts()
            // Excludes event posts ("X renamed the discussion"), which carry a
            // user_id and would otherwise inflate the count for free.
            ->where('type', 'comment')
            ->whereNull('hidden_at');

        // is_approved only exists while flarum/approval is installed.
        if ($this->extensions->isEnabled('flarum-approval')) {
            $query->where('is_approved', true);
        }

        return $query->count();
    }
}
