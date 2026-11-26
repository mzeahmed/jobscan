<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Tracking\InputReader;
use App\Tracking\ApplicationChannel;
use App\Api\Shared\InvalidInputException;

/**
 * Corps de `PATCH /api/v1/applications/{id}` : mise à jour **partielle** (seuls les champs présents changent ;
 * `null` ou chaîne vide efface). Chaque valeur est vérifiée : un texte trop long est refusé, jamais tronqué en silence
 * (ce sont les notes de l'utilisateur).
 */
final readonly class ApplicationDetailsInput
{
    /** Champ → longueur maximale (celle des colonnes). */
    private const array TEXT_LIMITS = [
        'recruiter_name' => 255,
        'recruiter_contact' => 255,
        'message_sent' => 20000,
        'notes' => 20000,
    ];

    /**
     * @param array<string, mixed> $values champs présents, validés ; `channel`, `cv` et `follow_up_at` normalisés
     */
    private function __construct(public array $values)
    {
    }

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->values);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidInputException
     */
    public static function fromBody(array $body): self
    {
        $known = [...array_keys(self::TEXT_LIMITS), 'channel', 'cv', 'follow_up_at'];
        $errors = [];
        $values = [];

        foreach (array_diff(array_keys($body), $known) as $unknown) {
            $errors[(string) $unknown] = 'Champ inconnu.';
        }

        foreach (self::TEXT_LIMITS as $field => $max) {
            if (!\array_key_exists($field, $body)) {
                continue;
            }

            $value = $body[$field];
            if ($value !== null && !\is_string($value)) {
                $errors[$field] = 'Texte attendu.';
            } elseif (\is_string($value) && mb_strlen($value) > $max) {
                $errors[$field] = sprintf('%d caractères au maximum.', $max);
            } else {
                $values[$field] = InputReader::optionalString($value);
            }
        }

        if (\array_key_exists('channel', $body)) {
            $channel = $body['channel'];
            if ($channel === null || $channel === '') {
                $values['channel'] = null;
            } elseif (!\is_string($channel) || ApplicationChannel::tryFrom($channel) === null) {
                $errors['channel'] = 'Valeur attendue : ' . implode(', ', array_column(ApplicationChannel::cases(), 'value')) . '.';
            } else {
                $values['channel'] = ApplicationChannel::from($channel);
            }
        }

        if (\array_key_exists('cv', $body)) {
            $cv = $body['cv'];
            if ($cv !== null && (!\is_string($cv) || mb_strlen($cv) > 100)) {
                $errors['cv'] = 'Identifiant de CV invalide.';
            } else {
                $values['cv'] = InputReader::optionalString($cv);
            }
        }

        if (\array_key_exists('follow_up_at', $body)) {
            $date = $body['follow_up_at'];
            if ($date === null || $date === '') {
                $values['follow_up_at'] = null;
            } elseif (!\is_string($date) || mb_strlen($date) > 40) {
                $errors['follow_up_at'] = 'Date invalide (format attendu : 2026-12-01).';
            } else {
                try {
                    $values['follow_up_at'] = InputReader::anyDate($date);
                } catch (\InvalidArgumentException) {
                    $errors['follow_up_at'] = 'Date invalide (format attendu : 2026-12-01).';
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }

        return new self($values);
    }
}
