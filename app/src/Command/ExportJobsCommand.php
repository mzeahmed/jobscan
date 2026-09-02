<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Repository\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exporte les offres scorées au format CSV ou JSON, vers stdout ou un fichier.
 *
 * Permet d'analyser les offres dans des outils externes (tableurs, BI, scripts)
 * sans passer par SQLite brut. Lecture seule : aucune donnée n'est modifiée.
 */
#[AsCommand(
    name: 'app:jobs:export',
    description: "Exporte les offres d'emploi enregistrées au format CSV ou JSON.",
)]
final class ExportJobsCommand extends Command
{
    private const string FORMAT_CSV = 'csv';
    private const string FORMAT_JSON = 'json';

    /** @var list<string> */
    private const array HEADER = ['id', 'title', 'score', 'source', 'contract', 'remote', 'url', 'created_at'];

    public function __construct(private readonly JobRepository $jobRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format de sortie : csv ou json.', self::FORMAT_CSV)
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Ne garde que les offres dont le score est ≥ à cette valeur.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Chemin du fichier de sortie (stdout par défaut).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, [self::FORMAT_CSV, self::FORMAT_JSON], true)) {
            $io->error(sprintf('Format invalide : "%s". Valeurs acceptées : csv, json.', $format));

            return Command::INVALID;
        }

        $minScoreOption = $input->getOption('min-score');
        $minScore = $minScoreOption === null ? null : (int) $minScoreOption;

        $jobs = $this->jobRepository->findForExport($minScore);

        $payload = $format === self::FORMAT_JSON
            ? $this->toJson($jobs)
            : $this->toCsv($jobs);

        $destination = $input->getOption('output');
        if ($destination === null) {
            $output->write($payload);

            return Command::SUCCESS;
        }

        $path = (string) $destination;
        if (file_put_contents($path, $payload) === false) {
            $io->error(sprintf('Impossible d\'écrire dans le fichier : %s', $path));

            return Command::INVALID;
        }

        $io->success(sprintf('%d offre(s) exportée(s) vers %s.', count($jobs), $path));

        return Command::SUCCESS;
    }

    /**
     * @param Job[] $jobs
     */
    private function toCsv(array $jobs): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::HEADER, escape: '');
        foreach ($jobs as $job) {
            $row = $this->row($job);
            $row['remote'] = $row['remote'] ? '1' : '0';
            fputcsv($handle, array_values($row), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param Job[] $jobs
     */
    private function toJson(array $jobs): string
    {
        $rows = array_map($this->row(...), $jobs);

        return json_encode(
            $rows,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * @return array{id: int|null, title: string|null, score: int|null, source: string|null, contract: string, remote: bool, url: string|null, created_at: string|null}
     */
    private function row(Job $job): array
    {
        return [
            'id' => $job->getId(),
            'title' => $job->getTitle(),
            'score' => $job->getScore(),
            'source' => $job->getSource(),
            'contract' => $job->getContractType()->value,
            'remote' => $job->isRemote(),
            'url' => $job->getUrl(),
            'created_at' => $job->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
