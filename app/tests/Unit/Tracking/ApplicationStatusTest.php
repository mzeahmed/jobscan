<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tracking;

use PHPUnit\Framework\TestCase;
use App\Tracking\ApplicationStatus;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplicationStatusTest extends TestCase
{
    /** @return iterable<string, array{ApplicationStatus, ApplicationStatus, bool}> */
    public static function transitions(): iterable
    {
        yield 'envoi' => [ApplicationStatus::Draft, ApplicationStatus::Applied, true];
        yield 'brouillon abandonné' => [ApplicationStatus::Draft, ApplicationStatus::Withdrawn, true];
        yield 'entretien' => [ApplicationStatus::Applied, ApplicationStatus::Interview, true];
        yield 'refus après envoi' => [ApplicationStatus::Applied, ApplicationStatus::Rejected, true];
        yield 'sans réponse' => [ApplicationStatus::Applied, ApplicationStatus::NoResponse, true];
        yield 'réponse tardive' => [ApplicationStatus::NoResponse, ApplicationStatus::Interview, true];
        yield 'test puis entretien' => [ApplicationStatus::TechnicalTest, ApplicationStatus::Interview, true];
        yield 'proposition acceptée' => [ApplicationStatus::Offer, ApplicationStatus::Accepted, true];
        yield 'brouillon refusé sans envoi' => [ApplicationStatus::Draft, ApplicationStatus::Rejected, false];
        yield 'brouillon directement en entretien' => [ApplicationStatus::Draft, ApplicationStatus::Interview, false];
        yield 'acceptée sans proposition' => [ApplicationStatus::Applied, ApplicationStatus::Accepted, false];
        yield 'retour au brouillon' => [ApplicationStatus::Applied, ApplicationStatus::Draft, false];
        yield 'même statut' => [ApplicationStatus::Applied, ApplicationStatus::Applied, false];
        yield 'refusée est final' => [ApplicationStatus::Rejected, ApplicationStatus::Interview, false];
        yield 'acceptée est final' => [ApplicationStatus::Accepted, ApplicationStatus::Withdrawn, false];
        yield 'retirée est final' => [ApplicationStatus::Withdrawn, ApplicationStatus::Applied, false];
    }

    #[DataProvider('transitions')]
    public function testTransitions(ApplicationStatus $from, ApplicationStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    public function testOnlyFinalStatusesAreTerminal(): void
    {
        $terminal = array_filter(ApplicationStatus::cases(), static fn (ApplicationStatus $s): bool => $s->isTerminal());

        self::assertEqualsCanonicalizing([ApplicationStatus::Accepted, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], array_values($terminal));
    }

    public function testEveryStatusCanBeReachedFromDraft(): void
    {
        $reached = [ApplicationStatus::Draft];
        for ($i = 0; $i < count($reached); ++$i) {
            foreach ($reached[$i]->allowedTransitions() as $next) {
                if (!in_array($next, $reached, true)) {
                    $reached[] = $next;
                }
            }
        }

        self::assertCount(count(ApplicationStatus::cases()), $reached);
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ApplicationStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }
}
