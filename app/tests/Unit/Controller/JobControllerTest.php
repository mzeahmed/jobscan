<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private AbstractBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        unset($this->client, $this->entityManager);

        parent::tearDown();
    }

    /** @return iterable<string, array{array<string, string>, ?int}> */
    public static function minimumScoreValues(): iterable
    {
        yield 'absent' => [[], null];
        yield 'empty' => [['min_score' => ''], null];
        yield 'valid' => [['min_score' => '75'], 75];
        yield 'below range' => [['min_score' => '-1'], null];
        yield 'above range' => [['min_score' => '101'], null];
        yield 'non numeric' => [['min_score' => 'high'], null];
    }

    #[DataProvider('minimumScoreValues')]
    public function testAcceptsOptionalMinimumScore(array $query, ?int $expectedScore): void
    {
        $this->client->request('GET', '/job', $query);

        self::assertResponseIsSuccessful();

        if ($expectedScore === null) {
            self::assertSelectorExists('input[name="min_score"][value=""]');
        } else {
            self::assertSelectorExists(sprintf('input[name="min_score"][value="%d"]', $expectedScore));
        }
    }
}
