<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Ekumanov\AutoPromote\Access\UserPolicy;
use Ekumanov\AutoPromote\Api\ForumResourceFields;
use Ekumanov\AutoPromote\Api\UserResourceFields;
use Ekumanov\AutoPromote\Console\PromoteEligibleCommand;
use Ekumanov\AutoPromote\Listener\PromoteOnApproval;
use Ekumanov\AutoPromote\Listener\PromoteOnPost;
use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\Model(User::class))
        ->cast('watched_at', 'datetime')
        ->cast('watch_reason', 'string')
        ->belongsTo('watchedBy', User::class, 'watched_by_user_id'),

    (new Extend\ApiResource(Resource\UserResource::class))
        ->fields(UserResourceFields::class),

    (new Extend\ApiResource(Resource\ForumResource::class))
        ->fields(ForumResourceFields::class),

    (new Extend\Policy())
        ->modelPolicy(User::class, UserPolicy::class),

    (new Extend\Settings())
        // No group by default: auto-promotion stays off until an admin picks
        // the target group, so installing this can never move anyone unbidden.
        ->default('ekumanov-auto-promote.regular_group_id', '')
        ->default('ekumanov-auto-promote.required_posts', 3)
        // 0 = no age requirement.
        ->default('ekumanov-auto-promote.min_account_age_hours', 24)
        // Count the wait from the post that completed the quota, not from
        // registration — otherwise "register, wait a year, post three times,
        // start messaging people the same afternoon" defeats the delay entirely.
        ->default('ekumanov-auto-promote.age_from_qualifying_post', true),

    (new Extend\Event())
        ->listen(Posted::class, PromoteOnPost::class),

    // Promotion is otherwise only re-evaluated when a user posts or is approved,
    // so a member who makes their last qualifying post and then goes quiet would
    // never be promoted — nothing fires at the moment their waiting period ends.
    (new Extend\Console())
        ->command(PromoteEligibleCommand::class)
        ->schedule(PromoteEligibleCommand::class, function (ScheduleEvent $event) {
            $event->everyFifteenMinutes()->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())
                ->listen(Flarum\Approval\Event\PostWasApproved::class, PromoteOnApproval::class),
        ]),
];
