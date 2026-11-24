<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Matching\MatchVerdict;
use App\Profile\ProfileRegistry;
use App\Matching\ArgumentBuilder;
use App\Repository\JobMatchRepository;
use App\AI\Extraction\ExtractionSource;
use App\Repository\ApplicationRepository;

/**
 * Prépare un brouillon de message de candidature. Ne contacte personne et n'enregistre rien.
 *
 * Sans `--llm`, le brouillon vient d'un modèle de texte déterministe. Avec un LLM, il n'est utilisé que si le moteur est
 * autorisé à recevoir des données du profil et si sa réponse passe `DraftGuard` ; sinon le brouillon déterministe est
 * renvoyé avec la raison.
 */
final readonly class ApplicationAssistant
{
    /** @param list<string> $knownStack */
    public function __construct(
        private JobMatchRepository $matches,
        private ApplicationRepository $applications,
        private ProfileRegistry $profiles,
        private TemplateDraftWriter $template,
        private LlmDraftWriter $llmWriter,
        private DraftGuard $guard,
        private PrivacyGate $privacy,
        private array $knownStack = [],
    ) {
    }

    /**
     * @throws AssistantException aucune base pour rédiger (profil absent, offre non rapprochée)
     */
    public function draft(Job $job, ?string $searchProfileId = null, bool $useLlm = false): Draft
    {
        $candidate = $this->profiles->candidate();
        if ($candidate === null) {
            throw new AssistantException('Aucun profil candidat : le brouillon repose sur ce que vous y déclarez (voir docs/PROFILE.md).');
        }

        $match = $searchProfileId === null ? $this->matches->findBestEligible($job) : $this->matches->findOneByJobAndProfile($job, $searchProfileId);
        $result = $match?->getResult();
        if ($match === null || $result === null) {
            throw new AssistantException($searchProfileId === null
                ? 'Aucun profil de recherche ne juge cette offre compatible : aucun fait du profil à mettre en avant. Lancez app:jobs:match ou précisez --search-profile.'
                : sprintf('Pas de résultat de matching pour le profil « %s » : lancez app:jobs:match.', $searchProfileId));
        }

        // Liste blanche : seuls les faits de parcours sont repris. Les arguments de préférence (salaire, télétravail, contrat) n'en font pas partie.
        $facts = array_values(array_filter($result->arguments, self::isCareerFact(...)));
        $requirements = $job->getRequirements();
        $vocabulary = array_values(array_unique([
            ...$this->knownStack,
            ...array_map(static fn ($skill): string => $skill->name, $candidate->skills),
            ...($requirements?->allSkills() ?? []),
            ...$result->missingRequiredSkills,
            ...$result->missingNiceSkills,
        ]));

        $context = new DraftContext(
            $job->getTitle() ?? 'poste',
            $job->getCompany(),
            $facts,
            [...$result->missingRequiredSkills, ...$result->missingNiceSkills],
            $vocabulary,
            $this->applications->findOneByJob($job)?->getRecruiterName(),
        );

        $draft = $this->template->write($context);
        $checks = $this->checks($match, $result->missingRequiredSkills, $facts === []);

        if ($useLlm) {
            [$draft, $note] = $this->tryLlm($context, $draft);
            $checks[] = $note;
        }

        $signature = $candidate->fullName ?? '[Votre nom]';

        return new Draft($draft->subject, $draft->body . "\n" . $signature, $draft->source, $checks);
    }

    private static function isCareerFact(string $fact): bool
    {
        return array_any(
            [ArgumentBuilder::PREFIX_REQUIRED, ArgumentBuilder::PREFIX_NICE, ArgumentBuilder::PREFIX_EXPERIENCE, ArgumentBuilder::PREFIX_PROJECT, ArgumentBuilder::PREFIX_LANGUAGE],
            static fn (string $prefix): bool => str_starts_with($fact, $prefix),
        );
    }

    /** @return array{0: Draft, 1: string} */
    private function tryLlm(DraftContext $context, Draft $fallback): array
    {
        if (!$this->privacy->allowsProfileData()) {
            return [$fallback, sprintf('LLM non utilisé : %s. Vos données ne sont envoyées à un service distant qu\'avec ASSISTANT_ALLOW_REMOTE_LLM=true. Brouillon sans LLM.', $this->privacy->describe())];
        }

        $written = $this->llmWriter->write($context);
        if ($written === null) {
            return [$fallback, 'Le moteur LLM n\'a pas fourni de réponse exploitable : brouillon sans LLM.'];
        }

        $violations = $this->guard->violations($written['subject'], $written['body'], $context);
        if ($violations !== []) {
            return [$fallback, 'Texte du LLM refusé (' . implode(' ; ', $violations) . ') : brouillon sans LLM.'];
        }

        $greeting = $context->recruiterName === null ? 'Bonjour,' : 'Bonjour ' . $context->recruiterName . ',';

        return [
            new Draft(trim($written['subject']), $greeting . "\n\n" . trim($written['body']) . "\n\nCordialement,", Draft::SOURCE_LLM, []),
            sprintf('Rédigé avec le %s à partir de vos seuls faits déclarés, puis contrôlé (chiffres et compétences). Relisez-le : le contrôle ne prouve pas l\'exactitude de chaque phrase.', $this->privacy->describe()),
        ];
    }

    /**
     * @param list<string> $missingRequired
     *
     * @return list<string>
     */
    private function checks(JobMatch $match, array $missingRequired, bool $noFacts): array
    {
        $checks = ['Relisez et adaptez ce brouillon : il n\'est ni envoyé ni enregistré par Jobscan.'];
        if ($noFacts) {
            $checks[] = 'Aucun fait du profil à mettre en avant : complétez vos compétences et expériences dans candidate.yaml.';
        }
        if ($match->getVerdict() !== MatchVerdict::Eligible) {
            $checks[] = sprintf('Cette offre n\'est pas jugée compatible pour le profil « %s » (%s).', $match->getSearchProfileId(), $match->getVerdict()->label());
        }
        if ($missingRequired !== []) {
            $checks[] = 'Compétences demandées que vous ne déclarez pas (non mentionnées dans le message) : ' . implode(', ', $missingRequired) . '.';
        }
        $result = $match->getResult();
        if ($result !== null && $result->requirementsSource === ExtractionSource::Heuristic) {
            $checks[] = 'Les exigences de l\'offre viennent d\'une analyse heuristique, moins fiable : vérifiez l\'annonce.';
        }
        if ($result !== null && $result->unknown !== []) {
            $checks[] = 'L\'annonce ne précise pas : ' . implode(' ; ', $result->unknown);
        }
        $checks[] = 'Ajoutez vous-même vos coordonnées et, si besoin, le nom du recruteur : rien n\'est deviné.';

        return $checks;
    }
}
