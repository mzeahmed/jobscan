<?php

declare(strict_types=1);

namespace App\Tests\Integration\Email;

use Psr\Log\NullLogger;
use App\Email\EmailMessage;
use App\Email\EmailJobProvider;
use App\Processor\JobProcessor;
use App\Repository\JobRepository;
use Doctrine\ORM\Tools\SchemaTool;
use App\Email\EmailClientInterface;
use App\Email\ProcessedEmailStatus;
use App\Monitoring\FetchDiagnostics;
use App\Email\Parser\ApecEmailParser;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Email\Parser\IndeedEmailParser;
use App\Email\Parser\GenericEmailParser;
use Doctrine\ORM\EntityManagerInterface;
use App\Email\Parser\LinkedInEmailParser;
use App\Email\Parser\HelloWorkEmailParser;
use App\Repository\ProcessedEmailRepository;
use App\Email\Parser\FranceTravailEmailParser;
use App\Email\Parser\WelcomeToTheJungleEmailParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cycle complet email → offres → ingestion → suivi, sur une base SQLite de test et un client IMAP simulé.
 * Fixtures reconstruites : voir docs/EMAIL.md.
 */
final class EmailJobProviderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ProcessedEmailRepository $processed;
    private FetchDiagnostics $diagnostics;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->processed = $container->get(ProcessedEmailRepository::class);
        $this->diagnostics = new FetchDiagnostics();

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testIngestsOffersThenMarksTheEmailProcessedAndStaysIdempotent(): void
    {
        $client = new FakeEmailClient([$this->linkedinEmail(1)]);
        $provider = $this->provider($client);

        $this->runOnce($provider);

        self::assertSame(2, $this->jobs()->countAll());
        $rows = $this->processed->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ProcessedEmailStatus::Ingested, $rows[0]->getStatus());
        self::assertSame('linkedin', $rows[0]->getPlatform());
        self::assertSame(2, $rows[0]->getOffersCount());

        // Deuxième passage : la boîte renvoie le même email, qui est ignoré — aucune offre en double.
        $this->runOnce($provider);
        self::assertSame(2, $this->jobs()->countAll());
        self::assertCount(1, $this->processed->findAll());
        self::assertSame(0, $client->lastDelivered, 'Un email déjà traité n\'est pas retéléchargé.');
    }

    public function testAnEmailWhoseIngestionFailedIsNotMarkedAndIsRetried(): void
    {
        $client = new FakeEmailClient([$this->linkedinEmail(1)]);
        $provider = $this->provider($client);

        $jobs = $provider->fetch();
        self::assertCount(2, $jobs);
        $provider->acknowledge($jobs, [
            new JobProcessingResult(JobProcessingStatus::Saved),
            JobProcessingResult::failed('base indisponible'),
        ]);

        self::assertCount(0, $this->processed->findAll(), 'Pas d\'ingestion complète : pas de suivi.');

        $retry = $provider->fetch();
        self::assertCount(2, $retry, 'L\'email est retenté à l\'exécution suivante.');
    }

    public function testNothingIsRecordedWithoutAcknowledgementAsInDryRun(): void
    {
        $client = new FakeEmailClient([$this->linkedinEmail(1), $this->email('news@unknown-sender.test', 2, '<p>Bonjour</p>')]);
        $provider = $this->provider($client);

        $provider->fetch();

        self::assertCount(0, $this->processed->findAll());
        self::assertSame(0, $this->jobs()->countAll());
    }

    public function testRecordsEmailsThatHoldNoOfferWithoutBlockingTheOthers(): void
    {
        $client = new FakeEmailClient([
            $this->email('news@unknown-sender.test', 1, '<p>Bienvenue</p>'),
            $this->email('jobs-noreply@linkedin.com', 2, '<p>Votre compte a été mis à jour.</p>'),
            $this->email('jobs-noreply@linkedin.com', 3, null),
            $this->linkedinEmail(4),
        ]);
        $provider = $this->provider($client);

        $this->runOnce($provider);

        $byStatus = [];
        foreach ($this->processed->findAll() as $row) {
            $byStatus[$row->getStatus()->value] = ($byStatus[$row->getStatus()->value] ?? 0) + 1;
        }
        self::assertSame(['unsupported' => 1, 'no_offers' => 1, 'parse_failed' => 1, 'ingested' => 1], $byStatus);
        self::assertSame(2, $this->jobs()->countAll());
        self::assertCount(1, $this->diagnostics->drain('email')['errors'], 'L\'échec de parsing est relevé pour le monitoring.');
    }

    public function testDetectsTheSameOfferAlreadyKnownFromAnotherProvider(): void
    {
        $processor = self::getContainer()->get(JobProcessor::class);
        $processor->process(new \App\DTO\JobDto(
            'Développeur PHP Symfony',
            'https://www.linkedin.com/jobs/view/4012345678?utm_source=search',
            'PHP Symfony remote',
            'searxng',
        ));
        self::assertSame(1, $this->jobs()->countAll());

        $provider = $this->provider(new FakeEmailClient([$this->linkedinEmail(1)]));
        $jobs = $provider->fetch();
        $results = $processor->processBatch($jobs);
        $provider->acknowledge($jobs, $results);

        self::assertSame(
            [JobProcessingStatus::Duplicate, JobProcessingStatus::Saved],
            array_map(static fn (JobProcessingResult $result) => $result->status, $results),
            'La première offre du mail est déjà connue (URL canonique identique) ; la seconde est nouvelle.',
        );
        self::assertSame(2, $this->jobs()->countAll());
    }

    public function testAClientFailureIsReportedAndDoesNotRaise(): void
    {
        $provider = $this->provider(new FakeEmailClient([], failWith: new \RuntimeException('Connexion refusée')));

        self::assertSame([], $provider->fetch());
        self::assertSame(['Connexion refusée'], $this->diagnostics->drain('email')['errors']);
    }

    public function testDisabledProviderNeverContactsTheMailbox(): void
    {
        $client = new FakeEmailClient([$this->linkedinEmail(1)]);
        $provider = $this->provider($client, enabled: false);

        self::assertTrue($provider->isHealthy());
        self::assertSame([], $provider->fetch());
        self::assertSame(0, $client->fetchCalls);
        self::assertTrue($this->diagnostics->drain('email')['disabled']);
    }

    public function testHealthFollowsTheMailboxWhenEnabled(): void
    {
        self::assertFalse($this->provider(new FakeEmailClient([], healthy: false))->isHealthy());
        self::assertTrue($this->provider(new FakeEmailClient([]))->isHealthy());
    }

    private function runOnce(EmailJobProvider $provider): void
    {
        $jobs = $provider->fetch();
        $results = self::getContainer()->get(JobProcessor::class)->processBatch($jobs);
        $provider->acknowledge($jobs, $results);
    }

    private function provider(EmailClientInterface $client, bool $enabled = true): EmailJobProvider
    {
        $parsers = [
            new LinkedInEmailParser(),
            new IndeedEmailParser(),
            new HelloWorkEmailParser(),
            new ApecEmailParser(),
            new FranceTravailEmailParser(),
            new WelcomeToTheJungleEmailParser(),
            new GenericEmailParser(),
        ];

        return new EmailJobProvider($client, $parsers, $this->processed, new NullLogger(), $enabled, 7, 50, $this->diagnostics);
    }

    private function jobs(): JobRepository
    {
        return self::getContainer()->get(JobRepository::class);
    }

    private function linkedinEmail(int $uid): EmailMessage
    {
        return $this->email(
            'jobs-noreply@linkedin.com',
            $uid,
            (string) file_get_contents(__DIR__ . '/../../Fixtures/email/linkedin_alert.html'),
        );
    }

    private function email(string $from, int $uid, ?string $html): EmailMessage
    {
        return new EmailMessage('INBOX', '7', $uid, '<msg-' . $uid . '@test>', $from, 'Alerte', new \DateTimeImmutable(), $html, null);
    }
}

final class FakeEmailClient implements EmailClientInterface
{
    public int $fetchCalls = 0;
    public int $lastDelivered = 0;

    /** @param list<EmailMessage> $mailbox */
    public function __construct(
        private readonly array $mailbox,
        private readonly bool $healthy = true,
        private readonly ?\Throwable $failWith = null,
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function fetchSince(\DateTimeImmutable $since, int $limit, \Closure $isProcessed): array
    {
        ++$this->fetchCalls;
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $delivered = array_values(array_filter($this->mailbox, static fn (EmailMessage $m): bool => !$isProcessed($m->dedupKey())));
        $this->lastDelivered = count($delivered);

        return array_slice($delivered, 0, $limit);
    }
}
