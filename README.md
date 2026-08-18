# Auto Promote

A [Flarum](https://flarum.org) 2.0 extension that promotes members into a trusted
group once they have earned it — and lets staff quietly hold back the accounts
that are only *pretending* to earn it.

## Why

Forums that gate privileges (private messages, links, attachments) behind "post
a few times first" teach spammers exactly what to do: register, leave two or
three innocuous posts, wait, and then start sending private messages. The post
count is doing no work, because the spammer is happy to pay it.

This extension keeps the automatic promotion — it is genuinely useful for real
members — and adds a **watchlist** for the accounts that look like they are
farming the threshold. A watched account is never promoted, however many posts
it makes, until a moderator says otherwise.

## The watchlist is invisible to the person on it

This is the point of the feature, so it is worth being explicit.

The watch flag is serialized **only** to users holding the
`ekumanov-auto-promote.manage-watchlist` permission. For everyone else — other
members, guests, and the watched user themselves, including on their own profile
— the attributes are absent from the API document entirely. There is no badge to
notice, no field to inspect, and nothing to infer from.

A suspected spammer who can tell they have been flagged simply registers again
and behaves better. One who cannot tell keeps waiting for a promotion that is
never coming.

### Why not a hidden group?

The obvious implementation is a group with `is_hidden`, whose badge only staff
can see. It has a quiet failure mode: core writes group membership with a
wholesale `$user->groups()->sync($newGroupIds)`, and hidden groups are stripped
from the payload of anyone without `viewHiddenGroups` (administrators only, by
default). A **moderator** opening the Edit User modal and pressing Save would
therefore silently un-flag the user, with no error and no way to notice.

The flag lives in columns on the `users` table instead, which nothing else
rewrites. The trusted group stays a real group, because that is what actually
carries the permissions.

## What staff see

On any user's profile or post controls, holders of the permission get:

| Item | Shown when | Effect |
|---|---|---|
| **Add to watchlist** | user is not watched | Opens a note prompt. Flags the account and, if they were already trusted, removes the trusted group. |
| **Remove from watchlist** | user is watched | Clears the flag. Auto-promotion resumes next time they qualify. |
| **Promote to \<group\>** | user is not yet trusted | Grants the trusted group immediately, clearing any watch flag. |

Each item carries a one-line explanation underneath, so moderators do not have
to be briefed separately on what the watchlist is for.

Watched users show an eye badge whose tooltip records who flagged them, when,
and the note — because in three months "why is this account flagged?" needs a
better answer than somebody's memory.

Nobody can flag themselves, and administrator accounts are never a valid target
(this holds for administrators too, not just moderators).

## Promotion rules

A member is promoted when **all** of these hold:

- a trusted group is configured (if not, the whole feature is off — installing
  this extension never moves anyone on its own)
- they are not on the watchlist
- they are not staff (see below)
- they have at least *N* qualifying posts (default 3)
- the waiting period has elapsed (default 24 hours)

A qualifying post is a **comment** (event posts such as "renamed the discussion"
do not count), not hidden, and — when [flarum/approval](https://github.com/flarum/approval)
is enabled — approved.

### The waiting period starts at the qualifying post

By default the clock starts when the member makes the post that *completes*
their quota, not when they registered. This matters: counting from registration
is trivially defeated by the exact pattern the watchlist exists to catch —
register, wait a year, then post three times and start messaging people the same
afternoon. That account has satisfied "24 hours since registration" many times
over without ever waiting for anything.

Counting from the qualifying post means the delay is always actually served. Turn
the setting off to get the older registration-based behaviour.

### Staff are trusted by definition

Administrators and moderators count as trusted whether or not they hold the
group, and auto-promotion never touches them — they are not added to the trusted
group, and the UI does not offer to promote them. Nor can they be put on the
watchlist. They already have their standing from their own groups; adding a
"trusted" badge on top would say nothing.

### When promotion is re-evaluated

1. When a user **posts**.
2. When one of their posts is **approved later** — without this, a member whose
   qualifying post is approved after the fact stays unpromoted until they happen
   to post again.
3. Every fifteen minutes, via the scheduler.

The third is not a nicety. Promotion is otherwise only ever reconsidered when
something happens, and nothing happens at the moment a waiting period elapses —
so a member who makes their last qualifying post and then goes quiet would sit
unpromoted forever. The scheduled sweep closes that gap.

It also means **no backfill migration is needed**: members who were already past
the threshold before this extension was installed are picked up by the first
sweep. Preview exactly who that is before it happens:

```bash
php flarum auto-promote:sweep --dry-run
```

This requires Flarum's scheduler to be running (`* * * * * php /path/to/flarum
schedule:run`). Without it, only triggers 1 and 2 apply, and a member who stops
posting the moment they qualify is never promoted.

## Settings

Admin → Auto Promote:

- **Trusted group** — the promotion target, chosen from your existing groups.
  Guests, Members and Administrators are deliberately not offered. Leave unset to
  disable auto-promotion.
- **Approved posts required** — default 3.
- **Waiting period (hours)** — default 24. Set to 0 for none.
- **Start the wait at the qualifying post** — default on. See above.

The extension does not create the trusted group for you: you almost certainly
already have one, it already carries the permissions that make promotion mean
anything, and inventing a second one beside it would only cause confusion.

## Permissions

`Manage the promotion watchlist` (under Moderate) is granted to Moderators by
default, and governs both acting on the watchlist and seeing it at all.
Administrators have it implicitly.

## Installation

```bash
composer require ekumanov/flarum-ext-auto-promote
```

Then enable it in Admin → Extensions and pick your trusted group.

If you were previously doing this with a `Posted` listener in `extend.php`,
remove that snippet — otherwise both will run and race each other.

## License

MIT
