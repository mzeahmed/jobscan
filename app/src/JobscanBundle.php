<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Enregistre le namespace de configuration `jobscan:` (voir `DependencyInjection\JobscanExtension`
 * et `DependencyInjection\Configuration`), consommé notamment par `jobscan.llm.*`.
 */
final class JobscanBundle extends Bundle
{
}
