<?php
/**
 * Gibbon, Flexible & Open School System
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\System\SettingGateway;

/**
 * Optional adapter around MarkbookView. Markbook remains the source of truth;
 * this class owns availability checks, per-request caching, and room-specific
 * ambiguity rules so BadgeProvider does not know Markbook's implementation.
 */
class MarkbookGradeProvider
{
    private $db;
    private $session;
    private $settingGateway;
    private $views = [];
    private $termID;
    private $scaleIsPercent;

    /**
     * Gibbon\Core is deliberately NOT a constructor dependency.
     *
     * Its own constructor takes an untyped $directory parameter, which the
     * container cannot auto-wire, so type-hinting it here makes this class
     * - and everything that depends on it, which is BadgeProvider and
     * therefore Seating and Register mode - impossible to resolve at all.
     * MarkbookView needs a Core, so it is taken from the global at the one
     * point it is needed instead: the same thing core's own
     * modules/Markbook/moduleFunctions.php does.
     *
     * @param Connection     $db             The database.
     * @param Session        $session        The current session.
     * @param SettingGateway $settingGateway Core's own settings store.
     */
    public function __construct(
        Connection $db,
        Session $session,
        SettingGateway $settingGateway
    ) {
        $this->db = $db;
        $this->session = $session;
        $this->settingGateway = $settingGateway;
    }

    /**
     * @return array keyed by student ID with optional target/current values.
     */
    public function getGrades(array $roster, array $classes): array
    {
        if (empty($roster) || empty($classes) || !$this->markbookAvailable()) {
            return [];
        }

        $grades = [];
        foreach ($classes as $class) {
            $classID = (string) ($class['gibbonCourseClassID'] ?? '');
            if ($classID === '') {
                continue;
            }

            $students = array_filter($roster, function ($student) use ($classID) {
                $classIDs = array_values(array_filter(
                    array_map('trim', explode(',', (string) ($student['courseClassIDs'] ?? ''))),
                    'strlen'
                ));

                return count($classIDs) === 1 && $classIDs[0] === $classID;
            });
            if (empty($students)) {
                continue;
            }

            try {
                $markbook = $this->getView($classID);
                if ($this->scaleIsPercent === false) {
                    return [];
                }

                foreach ($students as $student) {
                    $personID = $student['gibbonPersonID'];
                    $target = $markbook->getTargetForStudent($personID);
                    $current = $this->getCurrentGrade($markbook, $personID);
                    if ($target !== '' || $current !== '') {
                        $grades[$personID] = [
                            'target'  => $target,
                            'current' => $current,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Markbook is an optional integration. A missing or broken
                // sibling module must not prevent Seating Plan from loading.
                continue;
            }
        }

        return $grades;
    }

    private function markbookAvailable(): bool
    {
        $path = __DIR__ . '/../../Markbook/src/MarkbookView.php';
        if (!is_file($path)) {
            return false;
        }

        require_once $path;

        return class_exists('Gibbon\\Module\\Markbook\\MarkbookView');
    }

    private function getView(string $classID)
    {
        if (isset($this->views[$classID])) {
            return $this->views[$classID];
        }

        global $gibbon;

        $class = 'Gibbon\\Module\\Markbook\\MarkbookView';
        $view = new $class(
            $gibbon,
            $this->db,
            $classID,
            $this->settingGateway
        );
        $view->cachePersonalizedTargets();
        $view->cacheWeightings();

        if ($this->scaleIsPercent === null) {
            $scale = $view->getDefaultAssessmentScale();
            $this->scaleIsPercent = !empty($scale['percent']);
        }

        $this->views[$classID] = $view;

        return $view;
    }

    private function getCurrentGrade($markbook, $personID)
    {
        $termID = $this->getCurrentTermID();

        return $termID === '' ? '' : $markbook->getCumulativeAverage($personID, $termID);
    }

    private function getCurrentTermID(): string
    {
        if ($this->termID !== null) {
            return $this->termID;
        }

        $row = $this->db->selectOne(
            'SELECT gibbonSchoolYearTermID
                FROM gibbonSchoolYearTerm
                WHERE gibbonSchoolYearID=:gibbonSchoolYearID
                    AND :today BETWEEN firstDay AND lastDay
                ORDER BY firstDay, gibbonSchoolYearTermID
                LIMIT 1',
            [
                'gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID'),
                'today'              => date('Y-m-d'),
            ]
        );
        $this->termID = (string) ($row ?: '');

        return $this->termID;
    }
}