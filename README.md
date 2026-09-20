# Phase 1 — `local_patchmanager` + `local_zoomcustom`

Built and verified against **mod_zoom 5.5.1, version `2026082400`** (the ZIP you supplied).
Nothing in `mod/zoom/` is modified until you explicitly apply the patch.

---

## 1. Directory tree

```text
local/patchmanager/                     generic engine, knows nothing about Zoom
├── version.php
├── settings.php                        admin page under Plugins → Local plugins
├── index.php                           status list, review, confirm screens
├── lib.php                             status_checks + pre_uninstall_hook
├── db/
│   ├── access.php                      local/patchmanager:view, :manage
│   ├── install.xml                     4 audit/history tables
│   └── tasks.php                       check_state, every 30 min
├── classes/
│   ├── api.php                         the only entry point (UI, CLI, task, packs)
│   ├── state.php                        6 states + aggregation rules
│   ├── status.php                       state + attributes + allowed actions
│   ├── check/patches.php                Moodle Check API integration
│   ├── output/ui.php                    HTML fragments
│   ├── privacy/provider.php
│   ├── task/check_state.php             detection only, never writes code
│   └── local/
│       ├── applier.php                  apply / restore / reapply pipeline
│       ├── audit.php                    history writer
│       ├── backup.php                   pristine backups in moodledata
│       ├── definition.php               contract v1 + validation
│       ├── definition_exception.php
│       ├── detector.php                 state from disk
│       ├── env.php                      writability, git, opcache, versions, node
│       ├── hunk.php                     exact anchor/payload matching
│       ├── registry.php                 pack discovery
│       └── util.php                     eol, hashing, syntax check
├── cli/status.php | check.php | apply.php | restore.php
├── lang/en/local_patchmanager.php
└── tests/matching_test.php

local/zoomcustom/                       Zoom pack: all Zoom knowledge lives here
├── version.php                         depends on local_patchmanager + mod_zoom
├── settings.php                        guard on/off, strictness
├── lib.php                             patches, state_changed, pre_uninstall_hook
├── db/
│   ├── install.xml                     local_zoomcustom_guard (unsafe windows)
│   └── tasks.php                       guard_check, every 15 min
├── classes/
│   ├── patches.php                     001-period-grading definition (the 2 hunks)
│   ├── hook.php                        what the patched mod_zoom calls
│   ├── guard.php                       pauses the Zoom task when unsafe
│   ├── heartbeat.php                   proof the patched code really executes
│   ├── status.php                      summary() + render_card() for your dashboard
│   ├── grading/period.php              the actual grading policy
│   ├── privacy/provider.php
│   └── task/guard_check.php
├── cli/verify_activity.php             read-only comparison for one activity
├── lang/en/local_zoomcustom.php
└── tests/period_grading_test.php       the documented cases + production regression
```

---

## 2. Installation

1. Copy `local/patchmanager` and `local/zoomcustom` into your Moodle code root.
2. Run the upgrade:

```bash
php admin/cli/upgrade.php --non-interactive
```

3. Optional, only if you want the Apply button in the browser. Add to `config.php`:

```php
$CFG->local_patchmanager_allowwebapply = true;
```

Leave it out on production and use the CLI: the web server then never needs write access to the code directory.

4. Check the status:

```bash
php local/patchmanager/cli/status.php
```

Expected on a fresh install: `local_zoomcustom:001-period-grading  not_applied  unverified  [required]`.
The guard will already have paused `\mod_zoom\task\get_meeting_reports`, which is deliberate.

5. Apply:

```bash
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading --dry-run
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading
```

Because `2026082400` is in the pack's tested versions, the state becomes **Active — verified** immediately and the guard resumes the Zoom task.

---

## 3. The two hunks, and why each is needed

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

---

## 4. The grading policy (`local_zoomcustom\grading\period`)

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
- Recurring meetings are left to upstream in r1 (Phase 2).

---

## 5. State machine

Six states, always recomputed from disk. The database holds history only.

| State | Meaning | Check | Apply | Reapply | Restore | Verify | Review | Acknowledge |
|---|---|---|---|---|---|---|---|---|
| `NOT_APPLIED` | No markers, every anchor unique | ✓ | ✓¹ | – | – | – | ✓ | required only |
| `APPLIED` | All markers at current revision, blocks exact | ✓ | – | – | ✓² | if unverified | ✓ | if required |
| `OUTDATED` | Clean, but an older revision | ✓ | – | ✓¹ ² | ✓² | – | ✓ | required only |
| `PARTIAL` | Our code present but not clean | ✓ | – | – | ✓² | – | ✓ | required only |
| `CONFLICT` | No markers, an anchor missing or ambiguous | ✓ | – | – | ✓² | – | ✓ | required only |
| `UNKNOWN` | File missing or unreadable | ✓ | – | – | – | – | ✓ | required only |

¹ writable and no `blocked_by`  ² a verified pristine backup must exist

Detection order per file: exists/readable → SHA-256 (recorded + shipped baseline, evidence) → exact marker and block matching. The rule that decides everything:

> markers present + not clean = `PARTIAL`. Markers absent + not applicable = `CONFLICT`.

Aggregation precedence: `UNKNOWN > PARTIAL > CONFLICT > OUTDATED > APPLIED/NOT_APPLIED`, and a mixture of applied and unapplied files is `PARTIAL`.

**Attributes** (never states): `verified`, `required`, `acknowledged`, `blocked_by`, `writable`, `heartbeat`, plus `changedsinceapply` and OPcache staleness.

Verification is keyed on target version + revision + file hashes, so a mod_zoom upgrade, a revision bump or an edited file all invalidate it. Acknowledgements are keyed the same way and self-clear.

A whole-file hash is **never** required for `APPLIED`: another customisation may legitimately change other parts of the same file. Block level matching decides; the hash is reported as "a target file changed after the last apply".

---

## 6. Apply, failure handling and restore

```text
lock → check state → build new content in memory → validate hunks → overlap check
     → token_get_all(TOKEN_PARSE) syntax check → back up every file → verify backups
     → write temp files beside the targets → rename over (the only write moment)
     → verify content on disk → invalidate opcache → recheck state → audit → unlock
```

- Any failure **before** the first rename leaves the code directory untouched.
- A failure during or after the renames restores the backups taken in the same operation, then rechecks. If that rollback fails, the result is marked critical, the backup paths are printed and logged, and the guard keeps the Zoom task paused.
- If the post-write state is not `APPLIED`, the change is rolled back automatically.
- File mode is preserved; an owner change is reported.
- `Restore` never reverse-applies a patch. It writes back a hash-verified pristine copy taken at apply time, keeps a copy of the current file first, and re-applies any other customisation that was applied to the same file. With no usable pristine backup for the installed version, Restore is refused with: reinstall the stock package.
- `Reapply` = Restore + Apply under one lock.
- All of this is behind `\core\lock\lock_config` so cron, the CLI and an admin cannot race.

---

## 7. Zoom guard

Rule: if a **required** customisation is not (`APPLIED` and verified) and not acknowledged, `local_zoomcustom` disables `\mod_zoom\task\get_meeting_reports`.

- Strictness: `verified_only` (default) or `applied_ok`.
- Pausing loses nothing — upstream resumes from its own `last_call_made_at`, within Zoom's 30-day window.
- The task is re-enabled **only** if this plugin was the one that disabled it.
- Every unsafe period is recorded in `local_zoomcustom_guard` (start, end, state, reason, whether the task was paused, hostname) so Phase 2 can identify activities graded while 001 was inactive. No automatic regrading is implemented.
- `guard::require_safe()` also blocks the teacher refresh path via hunk 2.

**Heartbeat**: the hook records when it actually ran. The card distinguishes *patched and executing* from *patched, but the running PHP may still be using an older copy* (the OPcache case). It is never a state.

---

## 8. Database tables, capabilities, tasks

| Plugin | Table | Purpose |
|---|---|---|
| `local_patchmanager` | `local_patchmanager_audit` | every operation and state change |
| | `local_patchmanager_backup` | index of pristine / pre-restore backups |
| | `local_patchmanager_verify` | verification records |
| | `local_patchmanager_ack` | acknowledgements |
| `local_zoomcustom` | `local_zoomcustom_guard` | periods when the customisation was inactive |

Nothing is added to mod_zoom's schema, and the engine refuses any patch touching `mod/zoom/db/*` or `version.php`.

Capabilities: `local/patchmanager:view` (manager + admin) and `local/patchmanager:manage` (admin only; every write also calls `is_siteadmin()`, needs a POST with sesskey, and the browser path needs the `config.php` flag).

Scheduled tasks: `local_patchmanager\task\check_state` (*/30, detection only) and `local_zoomcustom\task\guard_check` (*/15). **Neither ever applies a patch.** Code changes are always an explicit action.

Backups live in `$CFG->dataroot/local_patchmanager/backups/<component>/<version>/<timestamp>-<patch>-<reason>/` and are never deleted on uninstall.

---

## 9. CLI usage

```bash
php local/patchmanager/cli/status.php                       # exit 1 if a required patch is not current
php local/patchmanager/cli/status.php --json
php local/patchmanager/cli/check.php                        # recompute + notify packs
php local/patchmanager/cli/apply.php --all --dry-run
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading
php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading --reapply
php local/patchmanager/cli/restore.php --patch=local_zoomcustom:001-period-grading
php local/zoomcustom/cli/verify_activity.php --zoomid=54    # read-only comparison
```

---

## 10. Admin UI workflow

**Site administration → Plugins → Local plugins → Patch manager**

Shows target + installed version (and "upgrade pending"), the customisation and revision, the state badge with reasons, attributes (revision on disk, verified/unverified, acknowledgement, writability, `blocked_by`, changed-since-apply, OPcache, backup availability, node), and only the actions that are valid for the state. Conflict never offers Apply. Review shows each hunk, its anchor, its block and which one failed. There is no code editor.

Status also appears in **Reports → System status** through the Check API, so external monitoring can consume it.

**Dashboard**: call `\local_zoomcustom\status::summary()` for data or `\local_zoomcustom\status::render_card()` for a compact card. Show it to site admins only. It says "All customisations are current" only when the patch is `APPLIED`, verified and the heartbeat is not stale.

---

## 11. Tests

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/zoomcustom/tests/period_grading_test.php
vendor/bin/phpunit local/patchmanager/tests/matching_test.php
```

`period_grading_test` covers the six documented single-interval cases, the production regression (`159 s → 26.5`, and explicitly not `100`), overlapping reconnects, disjoint intervals, three-plus fragments, attendance entirely outside the window, an interval covering the whole activity, the key mapping, and the zero-duration guard.
`matching_test` covers the three hunk types, CRLF files, the syntax gate and every state aggregation rule.

---

## 12. Production deployment procedure

```text
1. Deploy the new mod_zoom code. Do NOT run the upgrade yet — Moodle suspends cron while an upgrade is pending.
2. php local/patchmanager/cli/status.php
3. php local/patchmanager/cli/apply.php --all --dry-run
4. php local/patchmanager/cli/apply.php --all
5. php admin/cli/upgrade.php
6. Patch manager page → Mark verified for the new mod_zoom version (after testing).
   The guard then resumes the Zoom report task.
If step 3 reports Conflict: continue with the upgrade. The guard keeps the task paused,
the page shows "manual review", and you update the hunk in a dev environment (bump REVISION).
```

If `opcache.validate_timestamps=0`, reload PHP-FPM after applying.

## 13. Rollback procedure

```bash
php local/patchmanager/cli/restore.php --patch=local_zoomcustom:001-period-grading
php local/patchmanager/cli/status.php        # expect not_applied
```

If no pristine backup exists for the installed version, reinstall the stock mod_zoom package; the status page will then read `not_applied`. To remove the plugins entirely, uninstall `local_zoomcustom` first — its `pre_uninstall_hook` restores the files, releases the task pause, and aborts the uninstall if it cannot.

---

## 14. Verifying the production case (zoom.id = 54, cmid 645)

```bash
php local/zoomcustom/cli/verify_activity.php --zoomid=54
```

Expected, for the student who joined at 05:39:04 and left at 05:42:39 in the 05:40:00–05:50:00 activity:

```text
denominator: this plugin 600s (configured) vs upstream <n>s (real session, clipped)
userid <n>    counted  159s ->  26.50   (upstream: raw  215s ->  100.00)
```

Then confirm the live path, without waiting for cron:

1. Patch manager page shows **Active — verified**, task running.
2. Delete the stored grade for that student on cmid 645 (gradebook → single view → clear override/grade), because upstream only ever *raises* a grade — see the limitation below.
3. Run the report task once: `php admin/cli/scheduled_task.php --execute='\mod_zoom\task\get_meeting_reports'`
4. The gradebook should show **26.5**, and the patch manager card should show a fresh heartbeat.

`26.5` proves the new path ran: no combination of the upstream numbers produces it.

---

## 15. Known limitations

1. **Existing inflated grades are not corrected.** Upstream only writes a grade when it is higher than the stored one (`get_meeting_reports.php:746`). Phase 1 makes future grading correct; historical correction is a separate, explicit operation. The guard log records exactly when 001 was inactive so Phase 2 can find the affected periods.
2. **If the patch is entirely missing, the guard hunk is missing too.** The scheduled task is still paused, but the teacher "Refresh sessions" link would run upstream grading. This is unavoidable: the guard lives in the code the patch inserts.
3. **`cli/get_meeting_report.php`** (the mod_zoom CLI) is deliberately not guarded — it is an explicit administrator action.
4. **Recurring meetings** keep upstream behaviour in r1.
5. **One node only.** Every check and apply reports its hostname. On a multi-node deployment, apply on each node or through the deployment system.
6. **OPcache with `validate_timestamps=0`** means the files can be correct while the running code is not. The heartbeat surfaces this; a PHP-FPM reload fixes it.
7. **Git-managed code roots** are detected and reported, not blocked; a deployment can still overwrite the patch, which the next check will report.
8. **Anchored hunks, not unified diffs.** The definitions carry exact anchor/payload strings rather than `.diff` files, which removes the need for a diff parser and makes fuzzy matching impossible. The review screen renders the difference for you.
9. The engine's detection only proves what is on **this** node's filesystem at check time.
