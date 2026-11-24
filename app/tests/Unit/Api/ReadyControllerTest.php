<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use Doctrine\DBAL\Result;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use App\Api\Probe\ReadyController;
use Symfony\Component\DependencyInjection\Container;

final class ReadyControllerTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $warnings = [];

    private function controller(Connection $connection): ReadyController
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string | \Stringable $message, array $context = []): void {
            $this->warnings[] = [(string) $message, $context];
        });

        $controller = new ReadyController($connection, $logger);
        $controller->setContainer(new Container());

        return $controller;
    }

    /** @return array<mixed> */
    private static function body(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testReadyWhenTheDatabaseAnswers(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        $response = ($this->controller($connection))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ready', 'dependencies' => ['database' => true]], self::body($response));
        self::assertSame([], $this->warnings);
    }

    public function testUnavailableWhenTheDatabaseFailsWithoutLeakingTheCause(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('SQLSTATE: cannot open /srv/secret/jobscan.db as user admin'));

        $response = ($this->controller($connection))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'unavailable', 'dependencies' => ['database' => false]], self::body($response));
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function testOnlyTheExceptionClassIsLogged(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('mot de passe: hunter2'));

        ($this->controller($connection))();

        self::assertCount(1, $this->warnings);
        self::assertSame(['dependency' => 'database', 'exception' => \RuntimeException::class], $this->warnings[0][1]);
        self::assertStringNotContainsString('hunter2', json_encode($this->warnings, JSON_THROW_ON_ERROR));
    }

    public function testAnUnexpectedAnswerIsNotReady(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        self::assertSame(503, ($this->controller($connection))()->getStatusCode());
    }
}
