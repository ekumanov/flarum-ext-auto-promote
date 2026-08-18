<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Api;

use Ekumanov\AutoPromote\Promoter;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Group\Group;

class ForumResourceFields
{
    public function __construct(
        protected Promoter $promoter
    ) {
    }

    public function __invoke(): array
    {
        $canManage = fn (Context $context) => $context->getActor()
            ->hasPermission('ekumanov-auto-promote.manage-watchlist');

        return [
            // Lets the forum frontend decide whether to render any of this at
            // all, without having to ask about a particular user first.
            Schema\Boolean::make('canManageWatchlist')
                ->get(fn (object $model, Context $context) => $canManage($context)),

            // Purely so the promote button can name the group ("Promote to
            // Regulars") instead of saying something vague. Staff-only, and
            // resolved once per request.
            Schema\Str::make('autoPromoteGroupName')
                ->nullable()
                ->get(function (object $model, Context $context) use ($canManage) {
                    if (! $canManage($context)) {
                        return null;
                    }

                    $groupId = $this->promoter->regularGroupId();

                    return $groupId === null
                        ? null
                        : Group::find($groupId)?->name_singular;
                }),
        ];
    }
}
