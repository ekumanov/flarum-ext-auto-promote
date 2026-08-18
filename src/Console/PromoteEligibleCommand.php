<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Console;

use Ekumanov\AutoPromote\Promoter;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Console\Command;

/**
 * Promotes everyone who has become eligible since the last run.
 *
 * This is not a nicety, it is what makes the waiting period work. Promotion is
 * otherwise only ever re-evaluated when a user posts or when one of their posts
 * is approved — so a member who makes their third post and then goes quiet would
 * sit unpromoted forever, because nothing happens at the moment their 24 hours
 * elapse. The scheduler closes that gap.
 *
 * Wired to run every fifteen minutes from extend.php; also available as
 * `php flarum auto-promote:sweep`.
 */
class PromoteEligibleCommand extends Command
{
    protected $signature = 'auto-promote:sweep
                            {--dry-run : Report who would be promoted without changing anything}
                            {--limit=500 : Maximum users to promote in one run}';

    protected $description = 'Promote members who have become eligible for the trusted group since the last run.';

    public function handle(Promoter $promoter): int
    {
        $groupId = $promoter->regularGroupId();

        if ($groupId === null) {
            $this->info('No trusted group configured — auto-promotion is off. Nothing to do.');

            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $required = $promoter->requiredPosts();

        // One query to narrow the field, rather than walking every user on the
        // forum: not watched, not already trusted, not staff, and holding at
        // least the required number of qualifying posts. isEligible() then
        // decides properly per user, including the waiting period.
        $candidates = User::query()
            ->whereNull('watched_at')
            ->whereDoesntHave('groups', function ($query) use ($groupId) {
                $query->whereIn('groups.id', [
                    $groupId,
                    Group::ADMINISTRATOR_ID,
                    Group::MODERATOR_ID,
                ]);
            })
            ->whereHas('posts', function ($query) use ($promoter) {
                $promoter->constrainToQualifying($query);
            }, '>=', $required)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $promoted = [];

        foreach ($candidates as $user) {
            // The same predicate the real run uses, so --dry-run cannot report
            // anything other than exactly what would happen.
            if (! $promoter->isEligible($user)) {
                continue;
            }

            $promoted[] = $user->username;

            if (! $dryRun) {
                $promoter->promote($user);
            }
        }

        $verb = $dryRun ? 'would be promoted' : 'promoted';

        if ($promoted === []) {
            $this->info(sprintf('%d candidate(s) checked, nobody %s.', $candidates->count(), $verb));

            return 0;
        }

        $this->info(sprintf('%d candidate(s) checked, %d %s:', $candidates->count(), count($promoted), $verb));

        foreach ($promoted as $username) {
            $this->line('  '.$username);
        }

        if ($candidates->count() >= $limit) {
            $this->warn(sprintf('Hit the limit of %d; run again to continue.', $limit));
        }

        return 0;
    }
}
