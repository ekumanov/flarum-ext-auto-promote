<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Ekumanov\AutoPromote\Listener;

use Ekumanov\AutoPromote\Promoter;
use Flarum\Approval\Event\PostWasApproved;

/**
 * Kept in its own class, registered only when flarum/approval is enabled, so
 * the PostWasApproved type hint is never resolved on installs without it.
 *
 * Without this listener a user whose qualifying post is approved *later* would
 * stay unpromoted until they happened to post again — the old extend.php
 * snippet only ever recounted on Posted.
 */
class PromoteOnApproval
{
    public function __construct(
        protected Promoter $promoter
    ) {
    }

    public function handle(PostWasApproved $event): void
    {
        if ($user = $event->post->user) {
            $this->promoter->maybeAutoPromote($user);
        }
    }
}
