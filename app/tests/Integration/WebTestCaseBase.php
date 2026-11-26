<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\JobDto;
use App\Entity\Job;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    /** Redémarre l'application avec une variable d'environnement de plus (restaurée après le test). */
    protected function restartWithEnvironment(string $name, string $value, ?string $remoteAddress = null): void
    {
        $this->restore[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
        if ($remoteAddress !== null) {
            // Sous php-fpm, `REMOTE_ADDR` est dans $_SERVER dès le démarrage : Symfony s'en sert pour résoudre le proxy « REMOTE_ADDR ».
            $this->restore['REMOTE_ADDR'] = [$_SERVER['REMOTE_ADDR'] ?? null, $_ENV['REMOTE_ADDR'] ?? null];
            $_SERVER['REMOTE_ADDR'] = $remoteAddress;
        }

        static::ensureKernelShutdown();
        $this->boot();
    }

    /** @var array<string, array{mixed, mixed}> */
    private array $restore = [];

    protected function tearDown(): void
    {
        foreach ($this->restore as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }

        \Symfony\Component\HttpFoundation\Request::setTrustedProxies([], 0);

        $_SERVER['JOBSCAN_UI_PASSWORD_HASH'] = $_ENV['JOBSCAN_UI_PASSWORD_HASH'] = $this->originalHash;
        $this->entityManager->close();
        parent::tearDown();
    }

    protected function persistJob(string $title = 'Développeur PHP', string $url = 'https://jobs.example/1'): Job
    {
        $job = Job::fromDTO(new JobDto($title, $url, 'Description', 'test', null, company: 'Exemple SAS'));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }
}
