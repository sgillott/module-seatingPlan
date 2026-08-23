<?php
/**
 * Gibbon, Flexible & Open School System
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

use Gibbon\Contracts\Services\Session;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;
use Gibbon\Module\SeatingPlan\Domain\BadgeGateway;

/** Builds the room payload for the name picker. */
class PickerMode
{
    private $rosterGateway;
    private $planGateway;
    private $badgeGateway;
    private $session;

    public function __construct(
        StudentRosterGateway $rosterGateway,
        SeatingPlanGateway $planGateway,
        BadgeGateway $badgeGateway,
        Session $session
    ) {
        $this->rosterGateway = $rosterGateway;
        $this->planGateway = $planGateway;
        $this->badgeGateway = $badgeGateway;
        $this->session = $session;
    }

    public function buildPayload(RoomContext $context): array
    {
        $classes = $context->getClasses();
        $courseClassIDs = array_column($classes, 'gibbonCourseClassID');
        $roster = $this->rosterGateway->selectRoster(
            $courseClassIDs,
            [],
            $context->getDate(),
            $this->session->get('gibbonSchoolYearID')
        );

        $plan = $context->getLayoutID() !== ''
            ? $this->planGateway->getPlanByLayoutAndClasses(
                $context->getLayoutID(),
                $context->getClassList()
            )
            : [];

        return [
            'roster'    => StudentRosterPresenter::build($roster),
            'seats'     => !empty($plan)
                ? $this->planGateway->selectSeatsByPlan($plan['seatingPlanPlanID'])
                : [],
            'catScores' => $this->badgeGateway->selectCATScores(
                array_column($roster, 'gibbonPersonID')
            ),
        ];
    }
}