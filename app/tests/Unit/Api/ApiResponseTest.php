<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Shared\ApiErrors;
use App\Api\Shared\ApiResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiResponseTest extends TestCase
{
    /** @return array<mixed> */
    private static function body(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSuccessUsesTheCommonEnvelopeAndAlwaysCarriesAMessage(): void
    {
        $response = ApiResponse::success(['items' => [1, 2]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 'success', 'success' => true, 'data' => ['items' => [1, 2], 'message' => 'OK']], self::body($response));
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testASuccessMessageGivenByTheCallerIsKept(): void
    {
        self::assertSame('Candidature ouverte.', self::body(ApiResponse::success(['message' => 'Candidature ouverte.'], 201))['data']['message']);
        self::assertSame(201, ApiResponse::success([], 201)->getStatusCode());
    }

    public function testAnEmptySuccessStillHasAnObjectAsData(): void
    {
        self::assertSame('{"code":"success","success":true,"data":{"message":"OK"}}', (string) ApiResponse::success()->getContent());
    }

    public function testAnErrorCarriesItsCodeAndAMessage(): void
    {
        $response = ApiResponse::error(['error' => 'invalid_credentials', 'message' => 'Identifiants invalides.', 'field' => 'email'], 401);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['code' => 'invalid_credentials', 'success' => false, 'data' => ['message' => 'Identifiants invalides.', 'field' => 'email']],
            self::body($response),
            'Le code d\'erreur sort de `data` pour devenir `code`, les champs complémentaires restent.',
        );
    }

    public function testAnErrorWithoutCodeOrMessageUsesTheDefaultsOfItsStatus(): void
    {
        $body = self::body(ApiResponse::error([], 404));

        self::assertSame('not_found', $body['code']);
        self::assertFalse($body['success']);
        self::assertSame('Ressource introuvable.', $body['data']['message']);
    }

    public function testBlankCodeAndMessageAreReplacedByTheDefaults(): void
    {
        $body = self::body(ApiResponse::error(['error' => '', 'message' => ''], 403));

        self::assertSame('forbidden', $body['code']);
        self::assertSame('Accès refusé.', $body['data']['message']);
    }

    public function testNoContentIsEmpty(): void
    {
        $response = ApiResponse::noContent(['X-Test' => '1']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('X-Test'));
    }

    public function testHeadersArePassedThrough(): void
    {
        self::assertSame('12', ApiResponse::error([], 429, ['Retry-After' => '12'])->headers->get('Retry-After'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function statuses(): iterable
    {
        yield '400' => [400, 'bad_request'];
        yield '401' => [401, 'unauthorized'];
        yield '403' => [403, 'forbidden'];
        yield '404' => [404, 'not_found'];
        yield '405' => [405, 'method_not_allowed'];
        yield '409' => [409, 'conflict'];
        yield '415' => [415, 'unsupported_media_type'];
        yield '422' => [422, 'unprocessable'];
        yield '429' => [429, 'too_many_requests'];
        yield '500' => [500, 'internal_error'];
        yield '503' => [503, 'service_unavailable'];
        yield 'autre 4xx' => [418, 'client_error'];
        yield 'autre 5xx' => [507, 'server_error'];
    }

    #[DataProvider('statuses')]
    public function testEveryStatusHasACodeAndAFrenchMessage(int $status, string $code): void
    {
        self::assertSame($code, ApiErrors::code($status));
        self::assertNotSame('', ApiErrors::message($status));
        self::assertStringNotContainsString('error', mb_strtolower(ApiErrors::message($status)), 'Pas de message technique en anglais.');
    }
}
