<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * Statut d'une candidature, et transitions autorisées entre statuts.
 *
 * Le parcours normal est Draft → Applied → (Interview ↔ TechnicalTest) → Offer → Accepted. Une candidature peut
 * être rejetée ou retirée à tout moment une fois envoyée ; `NoResponse` marque l'absence de réponse et peut
 * être suivie d'une réponse tardive. Accepted, Rejected et Withdrawn sont terminaux.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Applied = 'applied';
    case Interview = 'interview';
    case TechnicalTest = 'technical_test';
    case Offer = 'offer';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case NoResponse = 'no_response';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Applied => 'Postulé',
            self::Interview => 'Entretien',
            self::TechnicalTest => 'Test technique',
            self::Offer => 'Proposition',
            self::Accepted => 'Acceptée',
            self::Rejected => 'Refusée',
            self::Withdrawn => 'Retirée',
            self::NoResponse => 'Sans réponse',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Applied, self::Withdrawn],
            self::Applied => [self::Interview, self::TechnicalTest, self::Offer, self::Rejected, self::Withdrawn, self::NoResponse],
            self::NoResponse => [self::Interview, self::TechnicalTest, self::Offer, self::Rejected, self::Withdrawn],
            self::Interview => [self::TechnicalTest, self::Offer, self::Rejected, self::Withdrawn, self::NoResponse],
            self::TechnicalTest => [self::Interview, self::Offer, self::Rejected, self::Withdrawn, self::NoResponse],
            self::Offer => [self::Accepted, self::Rejected, self::Withdrawn],
            self::Accepted, self::Rejected, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Une réponse de l'entreprise : première prise de contact après l'envoi, ou refus. */
    public function isResponse(): bool
    {
        return in_array($this, [self::Interview, self::TechnicalTest, self::Offer, self::Rejected], true);
    }

    /** Ce statut correspond à un échange avec l'entreprise, qui met à jour la date de dernier contact. */
    public function recordsContact(): bool
    {
        return in_array($this, [self::Applied, self::Interview, self::TechnicalTest, self::Offer, self::Accepted, self::Rejected], true);
    }
}
