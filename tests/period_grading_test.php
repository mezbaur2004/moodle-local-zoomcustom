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

use local_zoomcustom\grading\period;

/**
 * Tests for the period grading policy.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_zoomcustom\grading\period
 */
final class period_grading_test extends \basic_testcase {

    /** @var int Arbitrary activity start, "10:00" in the cases below. */
    private const START = 1000000;

    /** @var int Ten minute activity. */
    private const DURATION = 600;

    /**
     * Counted attendance for a single interval, expressed in minutes from 10:00.
     *
     * @param float $joinminutes minutes relative to the activity start, may be negative
     * @param float $leaveminutes minutes relative to the activity start
     * @return int counted seconds
     */
    private function counted(float $joinminutes, float $leaveminutes): int {
        return period::counted_duration(self::START, self::DURATION, [
            [self::START + (int) round($joinminutes * 60), self::START + (int) round($leaveminutes * 60)],
        ]);
    }

    /**
     * The six documented single interval cases.
     *
     * Activity window is 10:00 to 10:10.
     *
     * @return array
     */
    public static function single_interval_provider(): array {
        return [
            'joins early, leaves early' => [-2.0, 8.0, 8 * 60, 80.0],
            'joins early, leaves on time' => [-2.0, 10.0, 600, 100.0],
            'joins early, leaves late' => [-2.0, 15.0, 600, 100.0],
            'joins late, leaves on time' => [2.0, 10.0, 8 * 60, 80.0],
            'joins late, leaves late' => [2.0, 15.0, 8 * 60, 80.0],
            'joins on time, leaves early' => [0.0, 4.0, 4 * 60, 40.0],
        ];
    }

    /**
     * Only attendance inside the activity window counts, against the configured duration.
     *
     * @dataProvider single_interval_provider
     * @param float $join minutes relative to the activity start
     * @param float $leave minutes relative to the activity start
     * @param int $expectedseconds
     * @param float $expectedgrade out of 100
     * @return void
     */
    public function test_single_interval(float $join, float $leave, int $expectedseconds,
            float $expectedgrade): void {
        $counted = $this->counted($join, $leave);
        $this->assertSame($expectedseconds, $counted);
        $this->assertEqualsWithDelta($expectedgrade, period::grade($counted, self::DURATION, 100.0), 0.0001);
    }

    /**
     * The production case that exposed the bug.
     *
     * Activity 05:40:00 to 05:50:00, duration 600. Student joined at 05:39:04 and
     * left at 05:42:39, which Zoom reported as a duration of 215 seconds. Only
     * 05:40:00 to 05:42:39 may count.
     *
     * @return void
     */
    public function test_production_case_zoom_54(): void {
        $activitystart = 1789623600;
        $duration = 600;
        $join = 1789623544;  // 05:39:04.
        $leave = 1789623759; // 05:42:39.

        // What Zoom itself reported for this participant, which upstream used directly.
        $this->assertSame(215, $leave - $join);

        $counted = period::counted_duration($activitystart, $duration, [[$join, $leave]]);
        $this->assertSame(159, $counted);

        $grade = period::grade($counted, $duration, 100.0);
        $this->assertEqualsWithDelta(26.5, $grade, 0.0001);

        // The inflated result must not come back.
        $this->assertNotEquals(100.0, $grade);

        // Neither the raw participant duration nor the real occurrence overlap
        // may be used as the numerator or the denominator.
        $this->assertNotEquals(215, $counted);
        $this->assertEqualsWithDelta(35.8333, period::grade(215, $duration, 100.0), 0.001,
                'sanity check: the raw duration would have produced this instead');
    }

    /**
     * Overlapping reconnects are merged, not summed.
     *
     * 09:58-10:03 and 10:02-10:05 clip to 10:00-10:03 and 10:02-10:05, which
     * merge into 10:00-10:05, so five minutes count rather than eight.
     *
     * @return void
     */
    public function test_overlapping_intervals_are_merged(): void {
        $counted = period::counted_duration(self::START, self::DURATION, [
            [self::START - 120, self::START + 180],
            [self::START + 120, self::START + 300],
        ]);

        $this->assertSame(5 * 60, $counted);
    }

    /**
     * Separate intervals are added, each clipped to the window first.
     *
     * 09:58-10:02 contributes two minutes, 10:04-10:08 contributes four, so six
     * minutes count. The raw Zoom durations would have summed to eight, which is
     * exactly the inflation this customisation removes.
     *
     * @return void
     */
    public function test_disjoint_intervals_are_clipped_then_summed(): void {
        $counted = period::counted_duration(self::START, self::DURATION, [
            [self::START - 120, self::START + 120],
            [self::START + 240, self::START + 480],
        ]);

        $this->assertSame(6 * 60, $counted);
        $this->assertNotSame(8 * 60, $counted);
    }

    /**
     * Three or more fragments never double count.
     *
     * @return void
     */
    public function test_many_fragments(): void {
        $counted = period::counted_duration(self::START, self::DURATION, [
            [self::START, self::START + 120],
            [self::START + 60, self::START + 180],
            [self::START + 300, self::START + 360],
            [self::START + 310, self::START + 320],
        ]);

        // 10:00-10:03 plus 10:05-10:06.
        $this->assertSame(240, $counted);
    }

    /**
     * Attendance entirely outside the window counts for nothing.
     *
     * @return void
     */
    public function test_outside_the_window(): void {
        $counted = period::counted_duration(self::START, self::DURATION, [
            [self::START - 3600, self::START - 1800],
        ]);

        $this->assertSame(0, $counted);
        $this->assertSame(0.0, period::grade($counted, self::DURATION, 100.0));
    }

    /**
     * Attendance covering the whole window is capped at the activity duration.
     *
     * @return void
     */
    public function test_interval_covering_the_activity(): void {
        $counted = period::counted_duration(self::START, self::DURATION, [
            [self::START - 3600, self::START + 3600],
        ]);

        $this->assertSame(600, $counted);
        $this->assertEqualsWithDelta(100.0, period::grade($counted, self::DURATION, 100.0), 0.0001);
    }

    /**
     * calculate() returns the configured duration as the denominator and keys
     * users the way the upstream grading loop expects.
     *
     * @return void
     */
    public function test_calculate_keys_and_denominator(): void {
        $zoomrecord = (object) [
            'id' => 54,
            'start_time' => self::START,
            'duration' => self::DURATION,
            'recurring' => 0,
        ];

        $records = [
            (object) ['userid' => 5, 'name' => 'Ada', 'join_time' => self::START - 120,
                'leave_time' => self::START + 480, 'duration' => 600],
            (object) ['userid' => 5, 'name' => 'Ada', 'join_time' => self::START + 300,
                'leave_time' => self::START + 900, 'duration' => 600],
            (object) ['userid' => null, 'name' => 'Guest user', 'join_time' => self::START,
                'leave_time' => self::START + 60, 'duration' => 60],
            (object) ['userid' => null, 'name' => '12345', 'join_time' => self::START,
                'leave_time' => self::START + 30, 'duration' => 30],
        ];

        $result = period::calculate($zoomrecord, $records);

        $this->assertSame(self::DURATION, $result->denominator);

        // Ada: 10:00-10:08 and 10:05-10:10 merge to 10:00-10:10.
        $this->assertSame(600, $result->durations[5]);
        $this->assertSame(60, $result->durations['Guest user']);
        $this->assertSame(30, $result->durations['~12345~']);

        // A numeric user id becomes an integer key, which upstream relies on.
        $keys = array_keys($result->durations);
        $this->assertIsInt($keys[0]);
    }

    /**
     * An activity with no usable duration grades nobody rather than guessing.
     *
     * @return void
     */
    public function test_missing_duration(): void {
        $zoomrecord = (object) ['id' => 1, 'start_time' => self::START, 'duration' => 0, 'recurring' => 0];
        $this->assertNull(period::calculate($zoomrecord, []));
        $this->assertSame(0, period::counted_duration(self::START, 0, [[self::START, self::START + 60]]));
    }

    /**
     * The participant key mirrors upstream.
     *
     * @return void
     */
    public function test_participant_key(): void {
        $this->assertSame(7, period::participant_key((object) ['userid' => 7, 'name' => 'x']));
        $this->assertSame('Ada', period::participant_key((object) ['userid' => 0, 'name' => 'Ada']));
        $this->assertSame('~42~', period::participant_key((object) ['userid' => null, 'name' => '42']));
    }
}
