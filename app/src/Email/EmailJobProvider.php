<?php

declare(strict_types=1);

namespace App\Email;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\Entity\ProcessedEmail;
use App\Monitoring\FetchDiagnostics;
use App\Processor\JobProcessingStatus;
use App\Provider\JobProviderInterface;
use App\Email\Parser\GenericEmailParser;
use App\Email\Parser\EmailParserInterface;
use App\Repository\ProcessedEmailRepository;
use App\Provider\IngestionAwareProviderInterface;

/**
 * Offres issues des emails d'alerte des plateformes (LinkedIn, Indeed, HelloWork, Apec,
 * France Travail, Welcome to the Jungle) reçus dans une boîte dédiée.
 *
 * Idempotence : un email n'est marqué traité (`processed_email`) qu'**après** l'ingestion
 * réussie de ses offres (voir `acknowledge()`), jamais sur le seul statut lu/non lu de la
 * boîte, qui n'est d'ailleurs jamais modifiée. Un email dont l'ingestion a échoué est retenté.
 * Rien n'est écrit en dry-run, puisque `acknowledge()` n'est alors pas appelé.
 *
 * La date de publication des offres reste inconnue : la date de réception d'un email n'est pas
 * la date de publication de l'offre. Les offres sont rattachées à la source `email-{plateforme}`.
 */
final class EmailJobProvider implements JobProviderInterface, IngestionAwareProviderInterface
{
    /** @var list<array{email: EmailMessage, platform: ?string, status: ?ProcessedEmailStatus, error: ?string, indexes: list<int>}> */
    private array $pending = [];

    /**
     * @param iterable<EmailParserInterface> $parsers Parsers autoconfigurés (tag `app.email_parser`)
     * @param int $lookbackDays Fenêtre de recherche IMAP ; les emails déjà traités sont ignorés grâce à `processed_email`
     * @param int $maxMessages Emails téléchargés par exécution (les plus anciens d'abord)
     */
    public function __construct(
        private readonly EmailClientInterface $client,
        private readonly iterable $parsers,
        private readonly ProcessedEmailRepository $processedEmails,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
        private readonly int $lookbackDays = 7,
        private readonly int $maxMessages = 50,
        private readonly ?FetchDiagnostics $diagnostics = null,
    ) {
    }

    public function name(): string
    {
        return 'email';
    }

    public function isHealthy(): bool
    {
        return !$this->enabled || $this->client->isHealthy();
    }

    /** @return JobDto[] */
    public function fetch(): array
    {
        $this->pending = [];

        if (!$this->enabled) {
            $this->diagnostics?->markDisabled($this->name());

            return [];
        }

        $since = new \DateTimeImmutable(sprintf('-%d days', max(1, $this->lookbackDays)));

        try {
            $emails = $this->client->fetchSince(
                $since,
                $this->maxMessages,
                fn (string $key): bool => $this->processedEmails->isProcessed($key),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Relève de la boîte email en échec.', ['error' => $e->getMessage()]);
            $this->diagnostics?->record($this->name(), $e->getMessage());

            return [];
        }

        $jobs = [];
        foreach ($emails as $email) {
            $entry = ['email' => $email, 'platform' => null, 'status' => null, 'error' => null, 'indexes' => []];
            $parser = $this->parserFor($email);

            if ($parser === null) {
                $entry['status'] = ProcessedEmailStatus::Unsupported;
                $this->pending[] = $entry;

                continue;
            }

            $entry['platform'] = $parser->platform();

            try {
                $parsed = $parser->parse($email);
            } catch (\Throwable $e) {
                $this->logger->warning('Email illisible : structure inattendue.', [
                    'platform' => $parser->platform(),
                    'error' => $e->getMessage(),
                ]);
                $this->diagnostics?->record($this->name(), sprintf('Parsing %s : %s', $parser->platform(), $e->getMessage()));
                $entry['status'] = ProcessedEmailStatus::ParseFailed;
                $entry['error'] = $e->getMessage();
                $this->pending[] = $entry;

                continue;
            }

            if ($parsed === []) {
                $entry['status'] = ProcessedEmailStatus::NoOffers;
            }

            foreach ($parsed as $job) {
                $entry['indexes'][] = count($jobs);
                $jobs[] = $job;
            }

            $this->pending[] = $entry;
        }

        $this->logger->info('Emails d\'alerte relevés.', ['emails' => count($emails), 'offers' => count($jobs)]);

        return $jobs;
    }

    /**
     * Marque les emails comme traités : ceux dont toutes les offres sont ingérées (ou aucune à ingérer).
     * Un email dont une offre a échoué n'est pas marqué et sera retenté.
     */
    public function acknowledge(array $jobs, array $results): void
    {
        $now = new \DateTimeImmutable();

        foreach ($this->pending as $entry) {
            $failed = array_filter(
                $entry['indexes'],
                static fn (int $index): bool => ($results[$index] ?? null) === null
                    || $results[$index]->status === JobProcessingStatus::Failed,
            );

            if ($failed !== []) {
                $this->logger->warning('Ingestion incomplète : email conservé pour une nouvelle tentative.', [
                    'platform' => $entry['platform'],
                    'failed_offers' => count($failed),
                ]);

                continue;
            }

            try {
                $this->processedEmails->save(new ProcessedEmail(
                    $entry['email'],
                    $entry['status'] ?? ProcessedEmailStatus::Ingested,
                    $entry['platform'],
                    count($entry['indexes']),
                    $entry['error'],
                    $now,
                ));
            } catch (\Throwable $e) {
                // Pire cas : l'email sera relu ; l'ingestion étant idempotente, aucun doublon n'en résulte.
                $this->logger->error('Suivi de l\'email non enregistré.', ['error' => $e->getMessage()]);
            }
        }

        $this->pending = [];
    }

    private function parserFor(EmailMessage $email): ?EmailParserInterface
    {
        $parsers = [...$this->parsers];
        // Le parser générique, opt-in, passe toujours en dernier.
        usort($parsers, static fn (EmailParserInterface $a, EmailParserInterface $b): int => ($a instanceof GenericEmailParser) <=> ($b instanceof GenericEmailParser));

        foreach ($parsers as $parser) {
            if ($parser->supports($email)) {
                return $parser;
            }
        }

        return null;
    }
}
