<?php

use Gibbon\Module\SeatingPlan\ClassListNormalizer;
use Gibbon\Module\SeatingPlan\LayoutCopyName;
use Gibbon\Module\SeatingPlan\StudentRosterPresenter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ClassListNormalizer.php';
require_once __DIR__ . '/../../src/LayoutCopyName.php';
require_once __DIR__ . '/../../src/StudentRosterPresenter.php';

class SeatingPlanHelpersTest extends TestCase
{
    public function testClassListIsCanonical(): void
    {
        self::assertSame('2,7,10', ClassListNormalizer::normalize('10, 002,7,2,7'));
    }

    public function testMalformedClassListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClassListNormalizer::normalize('12,nope');
    }

    public function testCopyNameDoesNotAddAnIdentity(): void
    {
        self::assertSame('Science Room (Copy)', LayoutCopyName::for('Science Room'));
    }

    public function testSurnameInitialDisambiguatesFirstNameCollision(): void
    {
        $roster = [
            ['preferredName' => 'Alex', 'surname' => 'Brown'],
            ['preferredName' => 'Alex', 'surname' => 'Jones'],
            ['preferredName' => 'Sam', 'surname' => 'Lee'],
        ];

        self::assertSame(['Alex B.', 'Alex J.', 'Sam'], StudentRosterPresenter::labels($roster));
    }
}