<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Matching\JobMatcher;
use App\Matching\PublicationAge;
use App\Profile\ProfileRegistry;
use App\Matching\CriterionStatus;
use App\Repository\JobRepository;
use App\AI\Extraction\JobRequirements;
use App\Repository\JobMatchRepository;
use App\Profile\InvalidProfileException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Explique pourquoi une offre est recommandée ou écartée : scores, critères, compétences couvertes ou
 * manquantes, exigences éliminatoires, informations inconnues, arguments à mettre en avant et CV recommandé.
 */
#[AsCommand(
    name: 'app:jobs:explain',
    description: "Explique le matching d'une offre : scores, critères, compétences manquantes, arguments, CV recommandé.",
)]
final class ExplainJobCommand extends Command
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly JobMatchRepository $matchRepository,
        private readonly JobMatcher $matcher,
        private readonly ProfileRegistry $profiles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::REQUIRED, "Identifiant de l'offre.")
            ->addOption('recompute', null, InputOption::VALUE_NONE, 'Recalcule le matching de cette offre avant de l\'expliquer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getArgument('job'), FILTER_VALIDATE_INT);
        $job = is_int($id) ? $this->jobRepository->find($id) : null;
        if (!$job instanceof Job) {
            $io->error('Offre introuvable.');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $io->title(sprintf('Offre #%d — %s', (int) $job->getId(), $job->getTitle()));

        try {
            if ((bool) $input->getOption('recompute')) {
                $this->matcher->matchJob($job, $now, true);
                $this->jobRepository->flush();
            }
            $names = $this->profileNames();
        } catch (InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->describeJob($io, $job, $now);

        $matches = $this->matchRepository->findByJob($job);
        if ($matches === []) {
            $io->note($this->noMatchReason($job));

            return Command::SUCCESS;
        }

        foreach ($matches as $match) {
            $this->describeMatch($io, $match, $names[$match->getSearchProfileId()] ?? $match->getSearchProfileId());
        }

        return Command::SUCCESS;
    }

    private function describeJob(SymfonyStyle $io, Job $job, \DateTimeImmutable $now): void
    {
        $io->definitionList(
            ['Entreprise' => $job->getCompany() ?? 'inconnue'],
            ['Lieu' => $job->getLocation() ?? 'inconnu'],
            ['Source' => (string) $job->getSource()],
            ['URL' => (string) $job->getUrl()],
            ['Publication' => PublicationAge::describe($job, $now)],
            ['Analyse' => $job->getAnalysisStatus()->value . ' (' . ($job->getRequirements()?->source->value ?? 'aucune exigence extraite') . ')'],
        );

        $requirements = $job->getRequirements();
        if ($requirements instanceof JobRequirements && $requirements->warnings !== []) {
            foreach ($requirements->warnings as $warning) {
                $io->writeln('  <comment>⚠ ' . $warning . '</comment>');
            }
        }
    }

    private function describeMatch(SymfonyStyle $io, JobMatch $match, string $name): void
    {
        $result = $match->getResult();
        $io->section(sprintf('Profil « %s » — %s', $name, $match->getVerdict()->label()));

        if ($result === null) {
            $io->warning('Résultat illisible : recalculez avec --recompute.');

            return;
        }

        $io->definitionList(
            ['Compatibilité' => $result->matchScore . '/100'],
            ['Fraîcheur' => $match->getFreshnessScore() . '/100'],
            ['Priorité' => $match->getPriorityScore() . '/100'],
            ['Couverture de l\'information' => $result->coverage . ' %'],
            ['CV recommandé' => $result->cvId ?? 'aucun associé à ce profil'],
        );

        $rows = [];
        foreach ($result->criteria as $criterion) {
            $rows[] = [
                $criterion->criterion,
                $criterion->status->value,
                $criterion->score === null || !$criterion->status->isKnown() ? '—' : (int) round($criterion->score * 100) . ' %',
                $criterion->weight,
                $criterion->detail,
            ];
        }
        $io->table(['Critère', 'Statut', 'Score', 'Poids', 'Explication'], $rows);

        $this->list($io, 'Éliminatoire', $result->eliminations, 'error');
        $this->list($io, 'Compétences correspondantes', $result->matchedSkills);
        $this->list($io, 'Compétences exigées manquantes', $result->missingRequiredSkills, 'comment');
        $this->list($io, 'Compétences appréciées manquantes', $result->missingNiceSkills);
        $this->list($io, 'Informations inconnues (non comptées comme un échec)', $result->unknown);
        $this->list($io, 'À mettre en avant', $result->arguments, 'info');

        if ($result->requirementsSource->value === 'heuristic') {
            $io->writeln('  <comment>Exigences extraites par l\'heuristique locale : compétences exigées indéterminées.</comment>');
        }
        $unevaluated = count(array_filter($result->criteria, static fn ($criterion): bool => $criterion->status === CriterionStatus::Unknown));
        if ($unevaluated > 0 && $result->coverage < 100) {
            $io->writeln(sprintf('  <comment>%d critère(s) non évalué(s) faute d\'information : le score repose sur %d %% du poids applicable.</comment>', $unevaluated, $result->coverage));
        }
    }

    /** @param list<string> $items */
    private function list(SymfonyStyle $io, string $label, array $items, string $style = 'fg=default'): void
    {
        if ($items === []) {
            return;
        }

        $io->writeln(sprintf('<%s>%s</>', $style, $label));
        foreach ($items as $item) {
            $io->writeln('  • ' . $item);
        }
        $io->newLine();
    }

    private function noMatchReason(Job $job): string
    {
        if ($job->getRequirements() === null) {
            return "Cette offre n'a pas encore d'exigences extraites : analysez-la (app:jobs:analyze, --outdated pour une ancienne offre) puis relancez le matching.";
        }

        return 'Aucun résultat de matching : aucun profil candidat ou profil de recherche actif, ou matching pas encore lancé (app:jobs:match).';
    }

    /** @return array<string, string> */
    private function profileNames(): array
    {
        $names = [];
        foreach ($this->profiles->profiles()->searchProfiles as $profile) {
            $names[$profile->id] = $profile->name;
        }

        return $names;
    }
}
