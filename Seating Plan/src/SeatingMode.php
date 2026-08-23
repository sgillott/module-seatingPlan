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

/** Builds the room payload for seating mode. */
class SeatingMode
{
    private $rosterGateway;
    private $planGateway;
    private $session;
    private $badgeProvider;
    private $badgeGateway;

    public function __construct(
        StudentRosterGateway $rosterGateway,
        SeatingPlanGateway $planGateway,
        Session $session,
        BadgeProvider $badgeProvider,
        BadgeGateway $badgeGateway
    ) {
        $this->rosterGateway = $rosterGateway;
        $this->planGateway = $planGateway;
        $this->session = $session;
        $this->badgeProvider = $badgeProvider;
        $this->badgeGateway = $badgeGateway;
    }

    public function buildPayload(RoomContext $context): array
    {
        $classes = $context->getClasses();
        $courseClassIDs = array_column($classes, 'gibbonCourseClassID');
        $exceptionPairs = array_map(
            function ($class) {
                return [
                    'gibbonCourseClassID'   => $class['gibbonCourseClassID'],
                    'gibbonTTDayRowClassID' => $class['gibbonTTDayRowClassID'],
                ];
            },
            $classes
        );

        $roster = $this->rosterGateway->selectRoster(
            $courseClassIDs,
            $exceptionPairs,
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
            'roster'     => StudentRosterPresenter::build($roster, true),
            'seats'      => !empty($plan)
                ? $this->planGateway->selectSeatsByPlan($plan['seatingPlanPlanID'])
                : [],
            'badges'     => $this->badgeProvider->buildBadgeValues($roster, $classes),
            'badgeSlots' => $this->badgeGateway->selectBadgeSlots(
                $this->session->get('gibbonPersonID')
            ),
            'badgeCatalogue' => $this->badgeProvider->getCatalogue(),
        ];
    }
}