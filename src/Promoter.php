<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote;

use Carbon\Carbon;
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

    /**
     * Staff are trusted by virtue of being staff. They are never auto-promoted,
     * and reporting them as untrusted would make the UI offer to "promote" a
     * moderator into a group that grants them nothing they do not already have.
     */
    public function isExemptStaff(User $user): bool
    {
        return $user->isAdmin() || $user->groups->contains('id', Group::MODERATOR_ID);
    }

    public function isRegular(User $user): bool
    {
        if ($this->isExemptStaff($user)) {
            return true;
        }

        $groupId = $this->regularGroupId();

        return $groupId !== null && $user->groups->contains('id', $groupId);
    }

    public function isWatched(User $user): bool
    {
        return $user->watched_at !== null;
    }

    /**
     * Put the user in the trusted group. Returns true only when this call
     * actually added the membership, so callers can report (or react to) real
     * promotions rather than attempts.
     */
    public function promote(User $user): bool
    {
        $groupId = $this->regularGroupId();

        if ($groupId === null || $user->groups->contains('id', $groupId)) {
            return false;
        }

        // The setting can outlive the group it points at (an admin deletes the
        // group but leaves the setting). Attaching a dangling id would trip the
        // pivot's foreign key and turn an ordinary reply into a 500, so check
        // first — one indexed lookup, and only on the rare promotion path.
        if (! Group::whereKey($groupId)->exists()) {
            return false;
        }

        // One INSERT IGNORE ... SELECT rather than check-then-attach, for two
        // races the in-memory model cannot see:
        //
        // - Two requests evaluating the same user at once (a reply landing
        //   while the sweep runs, or two approvals in quick succession) would
        //   both pass the contains() check above and the second attach() would
        //   hit the group_user primary key — a 500 on an ordinary saved reply.
        //   The IGNORE makes the loser a no-op instead.
        // - The sweep loads its candidates up front and can reach a user
        //   minutes later. If a moderator watched them in between, the stale
        //   model still says "not watched". Reading watched_at from the row, in
        //   the same statement that inserts, means a flag that has been written
        //   always wins; a flag written a moment *after* is covered by demote()
        //   detaching unconditionally.
        $inserted = $user->groups()->newPivotStatement()->insertOrIgnoreUsing(
            ['user_id', 'group_id'],
            User::query()
                ->whereKey($user->id)
                ->whereNull('watched_at')
                ->select('id')
                ->selectRaw('? as group_id', [$groupId])
        );

        $user->unsetRelation('groups');

        return $inserted > 0;
    }

    public function demote(User $user): void
    {
        $groupId = $this->regularGroupId();

        if ($groupId === null) {
            return;
        }

        // Detach unconditionally rather than trusting the loaded relation: the
        // model may predate a promotion that landed concurrently (see
        // promote()), and a DELETE that matches nothing costs nothing.
        $user->groups()->detach($groupId);
        $user->unsetRelation('groups');
    }

    /**
     * The single definition of "has earned the trusted group".
     *
     * Every path asks this — the post listener, the approval listener, the
     * scheduled sweep and its --dry-run — so that a preview can never disagree
     * with what the sweep would actually do.
     */
    public function isEligible(User $user): bool
    {
        if ($this->regularGroupId() === null) {
            return false;
        }

        // The whole point of the watchlist: a flagged account never graduates,
        // no matter how many innocuous posts it accumulates.
        if ($this->isWatched($user)) {
            return false;
        }

        // Covers staff (never auto-promoted, never given the group) as well as
        // anyone already holding it.
        if ($this->isRegular($user)) {
            return false;
        }

        // A suspended account is not one to hand more trust to. Once the
        // suspension lapses the sweep picks them up again like anyone else.
        if ($this->isSuspended($user)) {
            return false;
        }

        // Post count first: it is the cheaper check and it short-circuits the
        // large majority of users before the timer query runs at all.
        if ($this->approvedPostCount($user) < $this->requiredPosts()) {
            return false;
        }

        return $this->isOldEnough($user);
    }

    /**
     * Promote the user if they now meet every requirement. Safe to call as
     * often as we like — it is a no-op for anyone already promoted.
     */
    public function maybeAutoPromote(User $user): bool
    {
        return $this->isEligible($user) && $this->promote($user);
    }

    /**
     * Whether flarum/suspend is enabled, i.e. whether suspended_until means
     * anything. The column can outlive a disabled extension, and a leftover
     * value from back then should not keep holding anyone back.
     */
    public function suspensionApplies(): bool
    {
        return $this->extensions->isEnabled('flarum-suspend');
    }

    public function isSuspended(User $user): bool
    {
        if (! $this->suspensionApplies() || $user->suspended_until === null) {
            return false;
        }

        return Carbon::parse($user->suspended_until)->isFuture();
    }

    /**
     * The SQL twin of isSuspended(), for the sweep's candidate query — so that
     * suspended accounts never take up slots in its batch limit.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     */
    public function excludeSuspended($query): void
    {
        if (! $this->suspensionApplies()) {
            return;
        }

        $query->where(function ($query) {
            $query->whereNull('suspended_until')
                ->orWhere('suspended_until', '<=', Carbon::now());
        });
    }

    public function requiredPosts(): int
    {
        return max(1, (int) $this->settings->get('ekumanov-auto-promote.required_posts', 3));
    }

    public function minAccountAgeHours(): int
    {
        return max(0, (int) $this->settings->get('ekumanov-auto-promote.min_account_age_hours', 24));
    }

    /**
     * Whether the waiting period runs from the qualifying post rather than from
     * registration.
     *
     * Counting from registration is trivially defeated by the pattern this
     * extension exists to catch: register, wait a year, then post three times
     * and message everyone the same afternoon. Counting from the post that
     * completed the quota means the waiting period is always actually waited.
     */
    public function ageFromQualifyingPost(): bool
    {
        return (bool) $this->settings->get('ekumanov-auto-promote.age_from_qualifying_post', true);
    }

    /**
     * When the clock starts for this user, or null if it has not started yet
     * (they do not have enough qualifying posts to begin with).
     */
    public function waitingPeriodStartsAt(User $user): ?\DateTimeInterface
    {
        if (! $this->ageFromQualifyingPost()) {
            return $user->joined_at;
        }

        $required = $this->requiredPosts();

        // The created_at of the Nth qualifying post — the one that completed
        // the quota. offset() skips the earlier ones.
        $date = $this->qualifyingPosts($user)
            ->orderBy('created_at')
            ->offset($required - 1)
            ->limit(1)
            ->pluck('created_at')
            ->first();

        return $date === null ? null : Carbon::parse($date);
    }

    protected function isOldEnough(User $user): bool
    {
        $hours = $this->minAccountAgeHours();

        if ($hours === 0) {
            return true;
        }

        $start = $this->waitingPeriodStartsAt($user);

        // No start date: either an imported account with no join date, or (in
        // qualifying-post mode) not enough posts yet. The post-count check is
        // the authority on the latter, so don't block on it here.
        if ($start === null) {
            return $this->ageFromQualifyingPost() ? false : true;
        }

        return Carbon::instance($start)->addHours($hours)->isPast();
    }

    /**
     * Posts that count toward promotion: visible replies the user actually
     * wrote.
     */
    public function approvedPostCount(User $user): int
    {
        return $this->qualifyingPosts($user)->count();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\Flarum\Post\Post>
     */
    public function qualifyingPosts(User $user)
    {
        return $this->constrainToQualifying($user->posts());
    }

    /**
     * The shared definition of "a post that counts", so the count, the timer
     * and the sweep's candidate filter can never drift apart.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param T $query
     *
     * @return T
     */
    public function constrainToQualifying($query)
    {
        // Excludes event posts ("X renamed the discussion"), which carry a
        // user_id and would otherwise inflate the count for free.
        $query->where('type', 'comment')
            ->whereNull('hidden_at');

        // A reply stays visible when the discussion around it is hidden, so it
        // has to be excluded explicitly or a moderator hiding a spam thread
        // would leave its author credited for every post in it. A correlated
        // EXISTS on the discussion's primary key rather than a join: it works
        // unchanged inside the sweep's whereHas('posts') subquery, and it
        // leaves the unqualified column names above unambiguous.
        $query->whereHas('discussion', function ($query) {
            $query->whereNull('discussions.hidden_at');
        });

        // is_approved only exists while flarum/approval is installed.
        if ($this->extensions->isEnabled('flarum-approval')) {
            $query->where('is_approved', true);
        }

        return $query;
    }
}
