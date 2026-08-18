<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;

/*
 * The watchlist lives on the users table rather than in a group, deliberately.
 *
 * A "watched" group would have to be hidden so the suspect never learns they
 * have been flagged — but core writes group membership with a wholesale
 * `$user->groups()->sync($newGroupIds)` (UserResource), and hidden groups are
 * stripped from the payload of anyone without `viewHiddenGroups` (admin-only by
 * default). A moderator opening and saving the Edit User modal would therefore
 * silently drop the flag. Columns cannot be clobbered that way.
 */
return Migration::addColumns('users', [
    'watched_at' => ['dateTime', 'nullable' => true],
    'watched_by_user_id' => ['integer', 'unsigned' => true, 'nullable' => true],
    'watch_reason' => ['string', 'length' => 255, 'nullable' => true],
]);
