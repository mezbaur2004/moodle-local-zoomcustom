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
 * Evidence that the patched code is not just on disk but actually executing.
 *
 * Files can say "applied" while the running PHP process still serves an older
 * cached copy. The heartbeat is how an administrator sees the difference. It is
 * never a patch state.
 *
 * @package    local_zoomcustom
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heartbeat {

    /** @var string Config name holding the encoded heartbeat. */
    protected const CONFIG = 'heartbeat';

    /**
     * Record that a hook executed.
     *
     * @param string $context which hook fired
     * @return void
     */
    public static function record(string $context): void {
        $host = gethostname();
        $payload = json_encode([
            'time' => time(),
            'context' => $context,
            'host' => $host === false ? 'unknown' : $host,
            'revision' => patches::REVISION,
        ]);

        set_config(self::CONFIG, $payload, 'local_zoomcustom');
    }

    /**
     * The last recorded heartbeat.
     *
     * @return \stdClass with time, age, context, host, revision; time is null when never seen
     */
    public static function info(): \stdClass {
        $raw = get_config('local_zoomcustom', self::CONFIG);
        $data = $raw ? json_decode($raw, true) : null;

        if (!is_array($data) || empty($data['time'])) {
            return (object) [
                'time' => null,
                'age' => null,
                'context' => null,
                'host' => null,
                'revision' => null,
            ];
        }

        return (object) [
            'time' => (int) $data['time'],
            'age' => time() - (int) $data['time'],
            'context' => $data['context'] ?? null,
            'host' => $data['host'] ?? null,
            'revision' => $data['revision'] ?? null,
        ];
    }

    /**
     * How the heartbeat relates to the last apply and to the Zoom task.
     *
     * ok           the hook has run since the patch was applied
     * unconfirmed  nothing has exercised the hook yet, which is normal on a quiet site
     * stale        the Zoom report task has run well after the apply without the
     *              hook firing, which suggests the running code is not the code on disk
     *
     * @param int|null $applytime when the patch was last applied
     * @param int|null $tasklastrun when the Zoom report task last ran
     * @return \stdClass heartbeat info with an extra state property
     */
    public static function evaluate(?int $applytime, ?int $tasklastrun): \stdClass {
        $info = self::info();
        $info->state = 'unconfirmed';

        if ($info->time !== null && ($applytime === null || $info->time >= $applytime)) {
            $info->state = 'ok';
            return $info;
        }

        if ($applytime !== null && $tasklastrun !== null && $tasklastrun > $applytime + HOURSECS) {
            $info->state = 'stale';
        }

        return $info;
    }
}
