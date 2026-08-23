<?php
/**
 * Gibbon, Flexible & Open School System
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

use Gibbon\Services\Format;

/**
 * Converts roster rows into the small, privacy-approved payload used by the
 * room modes. Names are resolved from the live roster; seats store IDs only.
 */
class StudentRosterPresenter
{
    public static function labels(array $roster): array
    {
        $counts = [];
        foreach ($roster as $student) {
            $first = (string) ($student['preferredName'] ?? '');
            $counts[$first] = ($counts[$first] ?? 0) + 1;
        }

        $labels = [];
        foreach ($roster as $student) {
            $first = (string) ($student['preferredName'] ?? '');
            $labels[] = ($counts[$first] ?? 0) > 1
                ? $first.' '.mb_strtoupper(mb_substr((string) ($student['surname'] ?? ''), 0, 1)).'.'
                : $first;
        }

        return $labels;
    }

    public static function build(array $roster, bool $includeCourseClassIDs = false): array
    {
        $labels = self::labels($roster);
        $payload = [];
        foreach ($roster as $index => $student) {
            $row = [
                'gibbonPersonID' => $student['gibbonPersonID'],
                'name'           => $labels[$index],
                'photo'          => self::photoURL($student['image_240'] ?? ''),
            ];
            if ($includeCourseClassIDs) {
                $row['courseClassIDs'] = $student['courseClassIDs'] ?? '';
            }
            $payload[] = $row;
        }

        return $payload;
    }
    private static function photoURL($imagePath): string
    {
        $html = Format::userPhoto($imagePath, 75);

        return preg_match('/src="([^"]*)"/', $html, $matches) ? $matches[1] : '';
    }
}