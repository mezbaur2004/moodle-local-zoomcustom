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

use local_zoomcustom\recurring\occurrence;

/**
 * Registration of 002-recurring-grading and the scope rule it grades by.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_zoomcustom\patches
 * @covers     \local_zoomcustom\recurring\occurrence
 */
final class recurring_grading_test extends \basic_testcase {

    /** @var string The file both customisations target. */
    private const SHARED_FILE = 'classes/task/get_meeting_reports.php';

    /**
     * One definition from patches::all(), by id.
     *
     * @param string $id
     * @return array
     */
    private function definition(string $id): array {
        foreach (patches::all() as $definition) {
            if ($definition['id'] === $id) {
                return $definition;
            }
        }
        $this->fail("no definition registered for {$id}");
    }

    /**
     * Both customisations are registered, and 001 is untouched by 002 arriving.
     *
     * @return void
     */
    public function test_both_patches_are_registered(): void {
        $ids = array_column(patches::all(), 'id');

        $this->assertSame([patches::ID, patches::ID_RECURRING], $ids);
        $this->assertSame('001-period-grading', patches::ID);
        $this->assertSame('002-recurring-grading', patches::ID_RECURRING);

        // 001 still owns both of its files and stays required.
        $first = $this->definition(patches::ID);
        $this->assertTrue($first['required']);
        $this->assertSame(
            [self::SHARED_FILE, 'console/get_meeting_report.php'],
            array_keys($first['files'])
        );
    }

    /**
     * 002 is an independent, optional customisation touching one file only.
     *
     * It is not required because grading does not depend on it: the scheduled
     * task produces the same grades with the customisation unapplied.
     *
     * @return void
     */
    public function test_recurring_definition_shape(): void {
        $definition = $this->definition(patches::ID_RECURRING);

        $this->assertSame(1, $definition['contract']);
        $this->assertSame(patches::TARGET, $definition['component']);
        $this->assertSame(patches::REVISION_RECURRING, $definition['revision']);
        $this->assertFalse($definition['required']);
        $this->assertSame([], $definition['dependencies']);
        $this->assertSame([patches::BASELINE], $definition['testedversions']);
        $this->assertSame([self::SHARED_FILE], array_keys($definition['files']));
        $this->assertCount(1, $definition['files'][self::SHARED_FILE]);
    }

    /**
     * Both customisations must agree on the pristine hash of the file they
     * share, or one of them is describing a baseline that no longer exists.
     *
     * @return void
     */
    public function test_shared_baseline_hash_agrees(): void {
        $first = $this->definition(patches::ID);
        $second = $this->definition(patches::ID_RECURRING);

        $this->assertSame(
            $first['baselinehashes'][self::SHARED_FILE],
            $second['baselinehashes'][self::SHARED_FILE],
            'both customisations patch the same file, so its pristine hash must match'
        );
    }

    /**
     * 002 anchors in a different function from 001, so the two hunks cannot
     * overlap and may be applied in either order.
     *
     * @return void
     */
    public function test_hunks_do_not_share_an_anchor(): void {
        $first = $this->definition(patches::ID)['files'][self::SHARED_FILE][0];
        $second = $this->definition(patches::ID_RECURRING)['files'][self::SHARED_FILE][0];

        $this->assertSame('insert_before', $second['type']);
        $this->assertNotSame($first['anchor'], $second['anchor']);
        $this->assertStringNotContainsString($second['anchor'], $first['payload']);
        $this->assertStringNotContainsString($first['anchor'], $second['payload']);
    }

    /**
     * The inserted block is guarded and carries its own markers, so the file
     * still runs unchanged when this plugin is absent.
     *
     * @return void
     */
    public function test_recurring_payload_is_guarded(): void {
        $hunk = $this->definition(patches::ID_RECURRING)['files'][self::SHARED_FILE][0];

        $this->assertStringContainsString("class_exists('\\local_zoomcustom\\hook')", $hunk['payload']);
        $this->assertStringContainsString('hook::recurring_occurrence($zoomrecord, $detailsid)', $hunk['payload']);
        $this->assertStringContainsString('BEGIN local_zoomcustom:002-recurring-grading r1', $hunk['payload']);
        $this->assertStringContainsString('END local_zoomcustom:002-recurring-grading r1', $hunk['payload']);
    }

    /**
     * Only recurring meetings with a real schedule are graded.
     *
     * A meeting with no fixed time has no duration to grade against, which is
     * the same reason 001 refuses it.
     *
     * @return void
     */
    public function test_in_scope_requires_a_scheduled_recurrence(): void {
        // Recurring, weekly, with a duration: the case 002 exists for.
        $this->assertTrue(occurrence::in_scope((object) [
            'recurring' => 1,
            'recurrence_type' => 2,
            'duration' => 3600,
        ]));

        // Not recurring at all: 001's territory.
        $this->assertFalse(occurrence::in_scope((object) [
            'recurring' => 0,
            'recurrence_type' => 2,
            'duration' => 3600,
        ]));

        // Recurring with no fixed time: no schedule, so no denominator.
        $this->assertFalse(occurrence::in_scope((object) [
            'recurring' => 1,
            'recurrence_type' => occurrence::RECURRINGTYPE_NOTIME,
            'duration' => 3600,
        ]));

        // Recurring and scheduled, but with no usable duration.
        $this->assertFalse(occurrence::in_scope((object) [
            'recurring' => 1,
            'recurrence_type' => 3,
            'duration' => 0,
        ]));
    }

    /**
     * A record missing the recurrence fields entirely is refused rather than
     * assumed to be gradable.
     *
     * @return void
     */
    public function test_in_scope_refuses_incomplete_records(): void {
        $this->assertFalse(occurrence::in_scope((object) []));
        $this->assertFalse(occurrence::in_scope((object) ['recurring' => 1]));
        $this->assertFalse(occurrence::in_scope((object) ['recurring' => 1, 'recurrence_type' => 1]));
    }
}
