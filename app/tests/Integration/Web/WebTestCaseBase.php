<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\DTO\JobDto;
use App\Entity\Job;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

abstract class WebTestCaseBase extends WebTestCase
{
    protected AbstractBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected const string PASSWORD = 'test-password-123';

    private string $originalHash = '';

    protected function setUp(): void
    {
        $this->originalHash = (string) ($_SERVER['JOBSCAN_UI_PASSWORD_HASH'] ?? '');
        $this->boot();
    }

    private function boot(): void
    {
        $this->client = static::createClient();
        // Base SQLite en mémoire : un redémarrage du noyau entre deux requêtes la viderait.
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /** Redémarre l'application avec un autre mot de passe configuré (`''` : aucun compte). */
    protected function restartWithPasswordHash(string $hash): void
    {
        $_SERVER['JOBSCAN_UI_PASSWORD_HASH'] = $_ENV['JOBSCAN_UI_PASSWORD_HASH'] = $hash;
        static::ensureKernelShutdown();
        $this->boot();
    }

    protected function tearDown(): void
    {
        $_SERVER['JOBSCAN_UI_PASSWORD_HASH'] = $_ENV['JOBSCAN_UI_PASSWORD_HASH'] = $this->originalHash;
        $this->entityManager->close();
        parent::tearDown();
    }

    protected function login(): void
    {
        $this->client->loginUser(new InMemoryUser('admin', (string) $_SERVER['JOBSCAN_UI_PASSWORD_HASH'], ['ROLE_USER']));
    }

    protected function persistJob(string $title = 'Développeur PHP', string $url = 'https://jobs.example/1'): Job
    {
        $job = Job::fromDTO(new JobDto($title, $url, 'Description', 'test', null, company: 'Exemple SAS'));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /** Lit le jeton CSRF d'un formulaire de la page courante. */
    protected function token(Crawler $page, string $actionContains): string
    {
        return (string) $page->filter(sprintf('form[action*="%s"] input[name=_token]', $actionContains))->first()->attr('value');
    }
}
