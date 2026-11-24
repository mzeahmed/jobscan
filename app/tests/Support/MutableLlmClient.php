<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Provider\LLMClientInterface;

/**
 * Faux client LLM scriptable : réponses successives, `null` (indisponible) ou exception.
 */
final class MutableLlmClient implements LLMClientInterface
{
    public const string SUCCESS_RESPONSE = '{"required_skills":["php"],"nice_skills":[],"mentioned_skills":[],"seniority":"unknown","min_years_experience":null,"languages":[],"remote_mode":null,"contract_types":[],"salary":null,"locations":[]}';

    /** @var list<string|null|\Throwable> */
    public array $responses = [];
    /** Réponse utilisée quand `$responses` est vide. */
    public string | null | \Throwable $default = self::SUCCESS_RESPONSE;
    public int $calls = 0;

    public function analyze(string $systemPrompt, string $userText): ?string
    {
        ++$this->calls;
        $response = $this->responses === [] ? $this->default : array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
