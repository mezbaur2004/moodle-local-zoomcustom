# Zoom grading fixes for Moodle (local_zoomcustom)

A patch pack for [local_patchmanager](https://github.com/mezbaur2004/moodle-local-patchmanager)
that fixes how the [Zoom activity plugin (mod_zoom)](https://github.com/ncstate-delta/moodle-mod_zoom)
grades attendance by duration, and stops mod_zoom from grading at all while the fix is not
in place.

mod_zoom's duration grading measures against the session that actually ran rather than the
scheduled length, and its overlap handling keeps a single outer span per user, so separate
reconnects can be miscounted. On a real activity, a student who attended 159 seconds of a
10-minute class was graded 100. With this pack the same student is graded 26.5.

All Zoom-specific knowledge lives here; the patch manager engine stays generic.

Designed, reviewed and tested by Mezbaur Are Rafi; the implementation was written with AI
assistance (Claude).

## What the pack contains

| Patch | Required | What it does |
|---|---|---|
| `001-period-grading` | yes | Replaces mod_zoom's duration calculation with a clipped interval union measured against the scheduled duration, and guards the teacher "Refresh sessions" path. |
| `002-recurring-grading` | no | Work in progress. Tracks each occurrence of a recurring meeting through a pending → settled → final lifecycle (or expired / flagged), so attendance is only counted after Zoom's participant reports have arrived. The pack's scheduled task does the work; the patch only makes new occurrences visible sooner. |

## Requirements

- Moodle 4.1 or later
- mod_zoom 5.5.1 (`2026082400`), the version the hunks were written and tested against
- local_patchmanager `2026092203` or later

## Installation

Install into `local/zoomcustom` alongside `local/patchmanager`, run the Moodle upgrade, then
apply from the server:

```bash
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading --dry-run
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading
```

Until 001 is applied and verified, the guard keeps mod_zoom's report task paused. The full
procedure, including upgrades, rollback and checking one activity with
`cli/verify_activity.php`, is in the patch manager's
[operations guide](https://github.com/mezbaur2004/moodle-local-patchmanager/blob/main/docs/OPERATIONS.md).

## 001: how the fix works

### The two hunks, and why each is needed

Target: `mod/zoom/classes/task/get_meeting_reports.php`, function `grading_participant_upon_duration()` (line 643 in 5.5.1).

**Hunk 1 — inserted immediately before the grading loop**

Anchor (must occur exactly once):

```php
        // Used to count the number of users being graded.
        $graded = 0;
```

Inserted:

```php
        // BEGIN local_zoomcustom:001-period-grading r1
        if (class_exists('\local_zoomcustom\hook')) {
            \local_zoomcustom\hook::period_durations($zoomrecord, $records, $meetingduration, $durations);
        }
        // END local_zoomcustom:001-period-grading r1
```

Why here: at this point upstream has already built `$meetingduration` (lines 665–672) and `$durations` (lines 675–710), and has not yet written any grade. Replacing those two variables by reference fixes the calculation without copying the 160 lines of grade writing, the "only raise a grade" rule, or the teacher notifications. It is the smallest possible intervention that corrects both the denominator and the numerator.

Target: `mod/zoom/console/get_meeting_report.php` (the teacher "Refresh sessions" link from `mod/zoom/index.php:194`).

**Hunk 2 — inserted immediately after the capability check**

Anchor:

```php
require_capability('mod/zoom:refreshsessions', $context);
```

Inserted:

```php
// BEGIN local_zoomcustom:001-period-grading r1
if (class_exists('\local_zoomcustom\hook')) {
    \local_zoomcustom\hook::require_grading_safe();
}
// END local_zoomcustom:001-period-grading r1
```

Why: that script runs the report task **directly** (`new \mod_zoom\task\get_meeting_reports()` then `execute()`), so disabling the scheduled task does not stop it. This hunk makes the same guard apply, before anything is printed. No grading logic is duplicated.

Both anchors were confirmed to appear exactly once in 5.5.1, and the resulting files were simulated and inspected.

### The grading policy (`local_zoomcustom\grading\period`)

```text
window  = zoom.start_time … zoom.start_time + zoom.duration
per interval:  counted = max(0, min(leave, windowend) − max(join, windowstart))
per user:      clip → drop empty → sort → merge overlapping/adjacent → sum → cap at zoom.duration
grade   = min(counted × grademax ÷ zoom.duration, grademax)
```

- The denominator is **always** `zoom.duration`. It never shrinks because the real occurrence started late or ended early.
- `get_participant_overlap_time()` is **not** reused. It keeps only one outer span per user, which mis-handles three or more separate fragments. The pack does a proper sort-and-merge instead.
- User keys mirror upstream exactly, including the `~name~` guard for numeric names, so a numeric user id stays an integer array key for upstream's `is_integer()` check.
- If `zoom.duration` is 0 or missing, the hook grades **nobody** rather than dividing by a meaningless denominator.
- Recurring meetings are handled separately by 002 (below).

### The guard

Rule: if a **required** customisation is not (`APPLIED` and verified) and not acknowledged, `local_zoomcustom` disables `\mod_zoom\task\get_meeting_reports`.

- Strictness: `verified_only` (default) or `applied_ok`.
- Pausing loses nothing — upstream resumes from its own `last_call_made_at`, within Zoom's 30-day window.
- The task is re-enabled **only** if this plugin was the one that disabled it.
- Every unsafe period is recorded in `local_zoomcustom_guard` (start, end, state, reason, whether the task was paused, hostname) so Phase 2 can identify activities graded while 001 was inactive. No automatic regrading is implemented.
- `guard::require_safe()` also blocks the teacher refresh path via hunk 2.

**Heartbeat**: the hook records when it actually ran. The card distinguishes *patched and executing* from *patched, but the running PHP may still be using an older copy* (the OPcache case). It is never a state.

## Tests

```bash
vendor/bin/phpunit local/zoomcustom/tests/
```

`period_grading_test` covers the documented single-interval cases, the 159 s → 26.5
regression, overlapping reconnects, disjoint intervals, three or more fragments, time
entirely outside the window, and the zero-duration guard. The other tests cover the guard
and the recurring-grading lifecycle.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
