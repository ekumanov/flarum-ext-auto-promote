<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Api;

use Carbon\Carbon;
use Ekumanov\AutoPromote\Promoter;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

class UserResourceFields
{
    public function __construct(
        protected Promoter $promoter
    ) {
    }

    public function __invoke(): array
    {
        $canWatchlist = fn (User $user, Context $context) => $context->getActor()->can('watchlist', $user);
        $canPromote = fn (User $user, Context $context) => $context->getActor()->can('promote', $user);

        // Everything the watchlist knows is staff-only. A watched user must not
        // be able to tell — from the payload, the badge, or anything else — that
        // they have been flagged, or they will simply register again and be more
        // careful. `visible` false omits the field from the document entirely.
        $canSeeWatchlist = fn (User $user, Context $context) => $context->getActor()
            ->hasPermission('ekumanov-auto-promote.manage-watchlist');

        return [
            // Capability flags, only sent to staff. Core ships equivalents like
            // canSuspend to everyone, but there is no reason to put them in the
            // payload of users who can never act on them.
            Schema\Boolean::make('canWatchlist')
                ->visible($canSeeWatchlist)
                ->get($canWatchlist),

            Schema\Boolean::make('canPromote')
                ->visible($canSeeWatchlist)
                ->get($canPromote),

            Schema\Boolean::make('isRegular')
                ->visible($canSeeWatchlist)
                ->get(fn (User $user) => $this->promoter->isRegular($user))
                ->writable($canPromote)
                ->set(function (User $user, mixed $value, Context $context) {
                    // Group writes go through the relation, so they must wait
                    // until the user row itself exists and is saved.
                    $user->afterSave(function (User $user) use ($value) {
                        $value
                            ? $this->promoter->promote($user)
                            : $this->promoter->demote($user);
                    });
                }),

            Schema\Boolean::make('isWatched')
                ->visible($canSeeWatchlist)
                ->get(fn (User $user) => $this->promoter->isWatched($user))
                ->writable($canWatchlist)
                ->set(function (User $user, mixed $value, Context $context) {
                    if ($value) {
                        // Re-flagging someone already flagged keeps the original
                        // attribution — the first moderator's call is the record.
                        if ($user->watched_at === null) {
                            $user->watched_at = Carbon::now();
                            $user->watched_by_user_id = $context->getActor()->id;
                        }

                        // Watching an existing Regular demotes them: the flag
                        // would be toothless if they kept the trusted group.
                        $user->afterSave(fn (User $user) => $this->promoter->demote($user));
                    } else {
                        $user->watched_at = null;
                        $user->watched_by_user_id = null;
                        $user->watch_reason = null;

                        // Re-evaluate immediately. A member who was demoted when
                        // they were flagged usually qualifies again the moment
                        // the flag comes off, and without this they would sit
                        // untrusted until the next scheduled sweep — up to
                        // fifteen minutes in which the moderator who just
                        // cleared the flag sees no change and assumes it failed.
                        $user->afterSave(fn (User $user) => $this->promoter->maybeAutoPromote($user));
                    }
                }),

            // Set by the client in the same request as isWatched; the default
            // camelCase -> snake_case setter writes `watch_reason`. Declared
            // after isWatched so clearing the flag above cannot be undone by a
            // stale reason arriving in the same payload.
            Schema\Str::make('watchReason')
                ->visible($canSeeWatchlist)
                ->writable($canWatchlist)
                ->nullable()
                // The column is varchar(255); on a strict-mode MySQL an
                // over-long note would be a 500 rather than a validation error.
                ->set(function (User $user, mixed $value) {
                    $value = is_string($value) ? trim($value) : null;

                    $user->watch_reason = ($value === null || $value === '')
                        ? null
                        : mb_substr($value, 0, 255);
                }),

            Schema\DateTime::make('watchedAt')
                ->visible($canSeeWatchlist)
                ->nullable(),

            Schema\Str::make('watchedByUsername')
                ->visible($canSeeWatchlist)
                ->get(fn (User $user) => $user->watchedBy?->username)
                ->nullable(),
        ];
    }
}
