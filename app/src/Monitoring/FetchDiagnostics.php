<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Collecte les erreurs absorbées par les providers pendant un `fetch()`.
 *
 * Les providers ne propagent jamais d'exception (voir `JobProviderInterface`) : sans
 * ce relevé, un échec réseau serait indiscernable d'une collecte vide. La commande
 * de pipeline lit puis vide ces diagnostics après chaque provider.
 */
final class FetchDiagnostics
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $disabled = [];

    public function record(string $provider, string $error): void
    {
        $this->errors[$provider][] = $error;
    }

    public function markDisabled(string $provider): void
    {
        $this->disabled[$provider] = true;
    }

    /**
     * Retourne puis efface les diagnostics du provider.
     *
     * @return array{errors: list<string>, disabled: bool}
     */
    public function drain(string $provider): array
    {
        $report = [
            'errors' => $this->errors[$provider] ?? [],
            'disabled' => isset($this->disabled[$provider]),
        ];
        unset($this->errors[$provider], $this->disabled[$provider]);

        return $report;
    }
}
