<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Api\Shared\SafeUrl;
use App\Entity\Application;
use App\Tracking\ApplicationStatus;
use App\Entity\ApplicationStatusChange;

/**
 * Représentation JSON d'une candidature. Les champs saisis par l'utilisateur (notes, message envoyé) lui appartiennent ;
 * ceux qui viennent de l'annonce (titre, entreprise) sont du texte non fiable, affiché comme tel par le front.
 */
final class ApplicationPresenter
{
    /** @return array<string, mixed> */
    public static function summary(Application $application, \DateTimeImmutable $now): array
    {
        $job = $application->getJob();
        $channel = $application->getChannel();
        $cvId = $application->getCvVersionId();

        return [
            'id' => $application->getId(),
            'status' => self::status($application->getStatus()),
            'job' => [
                'id' => $job->getId(),
                'title' => $job->getTitle(),
                'company' => $job->getCompany(),
                'location' => $job->getLocation(),
                'url' => SafeUrl::http($job->getUrl()),
            ],
            'channel' => $channel === null ? null : ['value' => $channel->value, 'label' => $channel->label()],
            'cv_version' => $cvId === null ? null : ['id' => $cvId, 'label' => $application->getCvVersionLabel()],
            'search_profile_id' => $application->getSearchProfileId(),
            'recruiter_name' => $application->getRecruiterName(),
            'applied_at' => $application->getAppliedAt()?->format(DATE_ATOM),
            'last_contact_at' => $application->getLastContactAt()?->format(DATE_ATOM),
            'follow_up_at' => $application->getFollowUpAt()?->format(DATE_ATOM),
            'follow_up_due' => $application->isFollowUpDue($now),
            'status_changed_at' => $application->getStatusChangedAt()->format(DATE_ATOM),
            'created_at' => $application->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<ApplicationStatusChange> $history
     *
     * @return array<string, mixed>
     */
    public static function detail(Application $application, array $history, \DateTimeImmutable $now): array
    {
        return self::summary($application, $now) + [
            'recruiter_contact' => $application->getRecruiterContact(),
            'message_sent' => $application->getMessageSent(),
            'notes' => $application->getNotes(),
            'follow_up_reminded_at' => $application->getFollowUpRemindedAt()?->format(DATE_ATOM),
            'responded_at' => $application->getRespondedAt()?->format(DATE_ATOM),
            'allowed_transitions' => array_map(self::status(...), $application->getStatus()->allowedTransitions()),
            'history' => array_map(static fn (ApplicationStatusChange $change): array => [
                'from' => $change->getFromStatus() === null ? null : self::status($change->getFromStatus()),
                'to' => self::status($change->getToStatus()),
                'note' => $change->getNote(),
                'changed_at' => $change->getChangedAt()->format(DATE_ATOM),
            ], $history),
        ];
    }

    /** @return array{value: string, label: string} */
    public static function status(ApplicationStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label()];
    }
}
