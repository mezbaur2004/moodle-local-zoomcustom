<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_zoomcustom;

use local_zoomcustom\recurring\attendance;
use local_zoomcustom\recurring\occurrence;
use local_zoomcustom\recurring\users;

/**
 * Occurrence discovery, gradeable user resolution, the lifecycle state machine
 * and the attendance cache.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_zoomcustom\recurring\occurrence
 * @covers     \local_zoomcustom\recurring\attendance
 * @covers     \local_zoomcustom\recurring\users
 */
final class recurring_lifecycle_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \stdClass[] Enrolled students. */
    private $students = [];

    /** @var \stdClass The recurring zoom activity. */
    private $zoom;

    /** @var int Window start of the occurrence under test. */
    private $start;

    /** @var int Configured duration of the activity. */
    private const DURATION = 3600;

    /**
     * A course with three students, a teacher, and a weekly recurring meeting.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $this->course->id, 'student');
            $this->students[$i] = $user;
        }

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $this->start = time() - (7 * DAYSECS);

        $this->zoom = $generator->create_module('zoom', [
            'course' => $this->course->id,
            'recurring' => 1,
            'recurrence_type' => 2,
            'duration' => self::DURATION,
            'start_time' => $this->start,
            'grade' => 100,
        ]);
    }

    /**
     * Add an occurrence of the meeting.
     *
     * @param int|null $start actual start, defaults to the activity's
     * @param bool $ended whether Zoom has reported an end time
     * @return int zoom_meeting_details id
     */
    private function add_occurrence(?int $start = null, bool $ended = true): int {
        global $DB;

        $start = $start ?? $this->start;

        return (int) $DB->insert_record('zoom_meeting_details', (object) [
            'uuid' => uniqid('uuid', true),
            'meeting_id' => $this->zoom->meeting_id,
            'zoomid' => $this->zoom->id,
            'start_time' => $start,
            'end_time' => $ended ? $start + self::DURATION : 0,
            'duration' => self::DURATION,
            'topic' => 'Occurrence',
            'total_minutes' => 60,
            'participants_count' => 0,
        ]);
    }

    /**
     * Record one attendance interval.
     *
     * @param int $detailsid
     * @param int|null $userid null for a participant Zoom could not identify
     * @param int $join
     * @param int $leave
     * @return int
     */
    private function add_participant(int $detailsid, ?int $userid, int $join, int $leave): int {
        global $DB;

        return (int) $DB->insert_record('zoom_meeting_participants', (object) [
            'detailsid' => $detailsid,
            'userid' => $userid,
            'zoomuserid' => 'z' . ($userid ?? 0),
            'uuid' => uniqid('p', true),
            'user_email' => '',
            'name' => $userid ? 'Student ' . $userid : 'Unknown Guest',
            'join_time' => $join,
            'leave_time' => $leave,
            'duration' => $leave - $join,
        ]);
    }

    /**
     * The occurrence row for a details id.
     *
     * @param int $detailsid
     * @return \stdClass
     */
    private function occurrence_row(int $detailsid): \stdClass {
        global $DB;
        return $DB->get_record('local_zoomcustom_occurrence', ['detailsid' => $detailsid], '*', MUST_EXIST);
    }

    /**
     * Only enrolled students are graded, never teachers.
     *
     * @return void
     */
    public function test_gradeable_users_are_students_not_teachers(): void {
        global $DB;

        $zoomrecord = $DB->get_record('zoom', ['id' => $this->zoom->id], '*', MUST_EXIST);
        $gradeable = users::gradeable($zoomrecord);

        $this->assertCount(3, $gradeable);
        foreach ($this->students as $student) {
            $this->assertArrayHasKey((int) $student->id, $gradeable);
        }
    }

    /**
     * A student an availability restriction excludes is not graded.
     *
     * This is the case the group-union design would have got wrong: the
     * restriction lives in the activity's availability, not in a group mode
     * mod_zoom does not support.
     *
     * @return void
     */
    public function test_gradeable_users_respect_availability_restrictions(): void {
        global $DB, $CFG;

        $CFG->enableavailability = 1;

        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $this->students[0]->id,
        ]);

        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'group', 'id' => (int) $group->id]],
            'showc' => [true],
        ]), ['id' => $this->zoom->cmid]);
        rebuild_course_cache($this->course->id, true);

        $zoomrecord = $DB->get_record('zoom', ['id' => $this->zoom->id], '*', MUST_EXIST);
        $gradeable = users::gradeable($zoomrecord);

        $this->assertSame([(int) $this->students[0]->id], array_values($gradeable),
                'only the student the activity is actually available to is graded');
    }

    /**
     * Attendance is clipped to the occurrence window, and absentees count zero.
     *
     * @return void
     */
    public function test_attendance_clips_to_window_and_zeroes_absentees(): void {
        global $DB;

        $detailsid = $this->add_occurrence();

        // Half the session.
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + 1800);
        // Joined early and left late: only the window counts.
        $this->add_participant($detailsid, (int) $this->students[1]->id,
                $this->start - 600, $this->start + self::DURATION + 600);

        $zoomrecord = $DB->get_record('zoom', ['id' => $this->zoom->id], '*', MUST_EXIST);
        $details = $DB->get_record('zoom_meeting_details', ['id' => $detailsid], '*', MUST_EXIST);

        $counted = attendance::calculate($zoomrecord, $details, users::gradeable($zoomrecord));

        $this->assertSame(1800, $counted[(int) $this->students[0]->id]);
        $this->assertSame(self::DURATION, $counted[(int) $this->students[1]->id]);
        $this->assertSame(0, $counted[(int) $this->students[2]->id], 'a student who never joined counts zero');
    }

    /**
     * A participant Zoom could not match to an account is ignored rather than
     * being guessed at.
     *
     * @return void
     */
    public function test_unidentified_participants_are_ignored(): void {
        global $DB;

        $detailsid = $this->add_occurrence();
        $this->add_participant($detailsid, null, $this->start, $this->start + self::DURATION);

        $zoomrecord = $DB->get_record('zoom', ['id' => $this->zoom->id], '*', MUST_EXIST);
        $details = $DB->get_record('zoom_meeting_details', ['id' => $detailsid], '*', MUST_EXIST);

        $counted = attendance::calculate($zoomrecord, $details, users::gradeable($zoomrecord));

        $this->assertCount(3, $counted);
        $this->assertSame([0, 0, 0], array_values($counted));
    }

    /**
     * Discovery records occurrences of scheduled recurring meetings only.
     *
     * @return void
     */
    public function test_discovery_skips_meetings_with_no_fixed_time(): void {
        global $DB;

        $this->add_occurrence();
        $this->assertSame(1, occurrence::discover());

        // A meeting with no fixed time has no duration to grade against.
        $notime = $this->getDataGenerator()->create_module('zoom', [
            'course' => $this->course->id,
            'recurring' => 1,
            'recurrence_type' => occurrence::RECURRINGTYPE_NOTIME,
            'duration' => self::DURATION,
            'start_time' => $this->start,
        ]);
        $DB->insert_record('zoom_meeting_details', (object) [
            'uuid' => uniqid('uuid', true),
            'meeting_id' => $notime->meeting_id,
            'zoomid' => $notime->id,
            'start_time' => $this->start,
            'end_time' => $this->start + self::DURATION,
            'duration' => self::DURATION,
            'topic' => 'No fixed time',
            'total_minutes' => 60,
            'participants_count' => 0,
        ]);

        $this->assertSame(0, occurrence::discover(), 'a no-fixed-time meeting is never discovered');
    }

    /**
     * Nothing is calculated until the settle delay has elapsed.
     *
     * @return void
     */
    public function test_pending_waits_for_the_settle_delay(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + self::DURATION);

        // One second before the delay expires.
        $now = $this->start + self::DURATION + occurrence::settle_delay() - 1;
        $counts = occurrence::advance($now);

        $this->assertSame(0, $counts['settled']);
        $this->assertSame(occurrence::PENDING, $this->occurrence_row($detailsid)->status);
    }

    /**
     * Once settled, attendance is cached for every gradeable user.
     *
     * @return void
     */
    public function test_pending_settles_and_caches_attendance(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + 900);

        $now = $this->start + self::DURATION + occurrence::settle_delay();
        $counts = occurrence::advance($now);

        $this->assertSame(1, $counts['settled']);

        $row = $this->occurrence_row($detailsid);
        $this->assertSame(occurrence::SETTLED, $row->status);
        $this->assertEquals($now, $row->settledtime);
        $this->assertNotEmpty($row->participantsig);

        $cached = attendance::cached((int) $row->id);
        $this->assertCount(3, $cached, 'every gradeable user is cached, present or not');
        $this->assertSame(900, $cached[(int) $this->students[0]->id]);
        $this->assertSame(0, $cached[(int) $this->students[2]->id]);
    }

    /**
     * An occurrence whose data never arrives expires instead of settling.
     *
     * @return void
     */
    public function test_pending_expires_when_data_never_arrives(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();

        // Past the settle delay but still inside the maximum wait: keep waiting.
        $counts = occurrence::advance($this->start + self::DURATION + occurrence::settle_delay());
        $this->assertSame(0, $counts['expired']);
        $this->assertSame(occurrence::PENDING, $this->occurrence_row($detailsid)->status);

        $counts = occurrence::advance($this->start + self::DURATION + occurrence::max_wait());
        $this->assertSame(1, $counts['expired']);
        $this->assertSame(occurrence::EXPIRED, $this->occurrence_row($detailsid)->status);
    }

    /**
     * A settled occurrence becomes final once the reconciliation window closes.
     *
     * @return void
     */
    public function test_settled_finalises_after_the_reconciliation_window(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + 900);

        $settledat = $this->start + self::DURATION + occurrence::settle_delay();
        occurrence::advance($settledat);

        // Still inside the window.
        $counts = occurrence::advance($settledat + occurrence::reconciliation_window() - 1);
        $this->assertSame(0, $counts['final']);
        $this->assertSame(occurrence::SETTLED, $this->occurrence_row($detailsid)->status);

        $counts = occurrence::advance($settledat + occurrence::reconciliation_window());
        $this->assertSame(1, $counts['final']);

        $row = $this->occurrence_row($detailsid);
        $this->assertSame(occurrence::FINAL, $row->status);
        $this->assertNotEmpty($row->finalizedtime);
    }

    /**
     * Late data arriving is the ordinary case: recalculate, do not flag.
     *
     * @return void
     */
    public function test_late_data_recalculates_without_flagging(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + 900);

        $settledat = $this->start + self::DURATION + occurrence::settle_delay();
        occurrence::advance($settledat);

        // A second student's report turns up afterwards.
        $this->add_participant($detailsid, (int) $this->students[1]->id,
                $this->start, $this->start + 1800);

        $counts = occurrence::advance($settledat + 60);

        $this->assertSame(1, $counts['recalculated']);
        $this->assertSame(0, $counts['flagged']);

        $row = $this->occurrence_row($detailsid);
        $this->assertSame(occurrence::SETTLED, $row->status);
        $this->assertSame(1800, attendance::cached((int) $row->id)[(int) $this->students[1]->id]);
    }

    /**
     * Attendance going down contradicts what was already recorded, so it is
     * flagged for review - while keeping the new values and staying counted.
     *
     * @return void
     */
    public function test_reduced_attendance_is_flagged_for_review(): void {
        global $DB;

        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $participantid = $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + self::DURATION);

        $settledat = $this->start + self::DURATION + occurrence::settle_delay();
        occurrence::advance($settledat);

        $row = $this->occurrence_row($detailsid);
        $this->assertSame(self::DURATION, attendance::cached((int) $row->id)[(int) $this->students[0]->id]);

        // Zoom revises the interval downwards.
        $DB->set_field('zoom_meeting_participants', 'leave_time',
                $this->start + 600, ['id' => $participantid]);

        $counts = occurrence::advance($settledat + 60);

        $this->assertSame(1, $counts['flagged']);

        $row = $this->occurrence_row($detailsid);
        $this->assertSame(occurrence::FLAGGED, $row->status);
        $this->assertSame(600, attendance::cached((int) $row->id)[(int) $this->students[0]->id],
                'the recalculated value is kept so the grade stays deterministic during review');
    }

    /**
     * A flagged occurrence waits for a person, not for the clock.
     *
     * @return void
     */
    public function test_flagged_does_not_finalise_on_its_own(): void {
        global $DB;

        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $participantid = $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + self::DURATION);

        $settledat = $this->start + self::DURATION + occurrence::settle_delay();
        occurrence::advance($settledat);

        $DB->set_field('zoom_meeting_participants', 'leave_time',
                $this->start + 600, ['id' => $participantid]);
        occurrence::advance($settledat + 60);
        $this->assertSame(occurrence::FLAGGED, $this->occurrence_row($detailsid)->status);

        // Long past the point a settled occurrence would have finalised.
        $counts = occurrence::advance($settledat + occurrence::reconciliation_window() + DAYSECS);

        $this->assertSame(0, $counts['final']);
        $this->assertSame(occurrence::FLAGGED, $this->occurrence_row($detailsid)->status);
    }

    /**
     * A user who stops being gradeable does not keep a stale cached row.
     *
     * @return void
     */
    public function test_cache_drops_users_who_are_no_longer_gradeable(): void {
        $detailsid = $this->add_occurrence();
        occurrence::discover();
        $this->add_participant($detailsid, (int) $this->students[0]->id,
                $this->start, $this->start + 900);

        $settledat = $this->start + self::DURATION + occurrence::settle_delay();
        occurrence::advance($settledat);

        $row = $this->occurrence_row($detailsid);
        $this->assertCount(3, attendance::cached((int) $row->id));

        // Unenrol one student, then force a recalculation.
        $this->getDataGenerator()->enrol_user($this->students[2]->id, $this->course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->add_participant($detailsid, (int) $this->students[1]->id, $this->start, $this->start + 300);
        occurrence::advance($settledat + 60);

        $cached = attendance::cached((int) $row->id);
        $this->assertArrayNotHasKey((int) $this->students[2]->id, $cached,
                'a suspended student stops being cached rather than keeping a stale zero');
    }
}
