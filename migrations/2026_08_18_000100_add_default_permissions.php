<?php

/*
 * This file is part of ekumanov/flarum-ext-auto-promote.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Administrators bypass permission checks, so they are covered without a row.
return Migration::addPermissions([
    'ekumanov-auto-promote.manage-watchlist' => Group::MODERATOR_ID,
]);
