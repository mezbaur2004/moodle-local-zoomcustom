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

/**
 * Ownership of the pause on mod_zoom's report task.
 *
 * The guard may only resume a pause it applied itself, and taskpausedbyus is
 * its only record of that. These tests pin down when the flag is set, kept and
 * cleared.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_zoomcustom\guard
 */
final class guard_test extends \advanced_testcase {

    /**
     * Skip without mod_zoom, and start from an enabled task and no flag.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        if (!class_exists('\mod_zoom\task\get_meeting_reports')) {
            $this->markTestSkipped('mod_zoom is not installed.');
        }

        $this->task()->enable();
        unset_config('taskpausedbyus', 'local_zoomcustom');
        guard::reset_cache();
    }

    /**
     * The upstream task as stored.
     *
     * @return \core\task\scheduled_task
     */
    private function task(): \core\task\scheduled_task {
        return \core\task\manager::get_scheduled_task(guard::ZOOM_TASK);
    }

    /**
     * Call one of the guard's protected enforcement steps.
     *
     * @param string $method pause or resume
     * @return \stdClass the result object the step updated
     */
    private function enforce(string $method): \stdClass {
        $result = (object) ['taskpaused' => null, 'pausedbyus' => null];
        (new \ReflectionMethod(guard::class, $method))->invoke(null, $result);
        return $result;
    }

    /**
     * Pausing an enabled task disables it, records ownership and survives an upgrade.
     *
     * @return void
     */
    public function test_pause_records_ownership(): void {
        $this->enforce('pause');

        $this->assertTrue($this->task()->get_disabled());
        $this->assertTrue((bool) $this->task()->is_customised());
        $this->assertEquals(1, get_config('local_zoomcustom', 'taskpausedbyus'));

        // What a mod_zoom upgrade does to its tasks.
        \core\task\manager::reset_scheduled_tasks_for_component('mod_zoom');

        $this->assertTrue($this->task()->get_disabled());
    }

    /**
     * Once our own pause is no longer needed, resuming enables the task and clears the flag.
     *
     * @return void
     */
    public function test_resume_undoes_our_pause(): void {
        $this->enforce('pause');
        $this->enforce('resume');

        $this->assertFalse($this->task()->get_disabled());
        $this->assertFalse(get_config('local_zoomcustom', 'taskpausedbyus'));
    }

    /**
     * A task an administrator disabled is never claimed and never resumed.
     *
     * @return void
     */
    public function test_administrator_pause_is_left_alone(): void {
        $this->task()->disable();

        $this->enforce('pause');
        $this->assertFalse(get_config('local_zoomcustom', 'taskpausedbyus'));

        $this->enforce('resume');
        $this->assertTrue($this->task()->get_disabled());
    }

    /**
     * Ownership is kept while the task cannot be loaded, so the pause is still ours later.
     *
     * @return void
     */
    public function test_resume_keeps_ownership_until_the_task_is_back(): void {
        global $DB;

        $this->enforce('pause');
        $record = $DB->get_record('task_scheduled', ['classname' => guard::ZOOM_TASK], '*', MUST_EXIST);

        $DB->delete_records('task_scheduled', ['id' => $record->id]);
        $this->enforce('resume');
        $this->assertEquals(1, get_config('local_zoomcustom', 'taskpausedbyus'));

        unset($record->id);
        $DB->insert_record('task_scheduled', $record);
        $this->enforce('resume');

        $this->assertFalse($this->task()->get_disabled());
        $this->assertFalse(get_config('local_zoomcustom', 'taskpausedbyus'));
    }
}
