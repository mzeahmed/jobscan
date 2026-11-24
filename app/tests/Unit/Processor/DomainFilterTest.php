<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processor;

use App\Processor\DomainFilter;
use PHPUnit\Framework\TestCase;
use App\Provider\CollectionPolicy;

final class DomainFilterTest extends TestCase
{
    public function testMatchesDomainsAndSubdomainsOnly(): void
    {
        $domains = ['linkedin.com', ' .Indeed.com. '];

        self::assertTrue(DomainFilter::matches('https://www.linkedin.com/jobs/1', $domains));
        self::assertTrue(DomainFilter::matches('https://fr.indeed.com/viewjob', $domains));
        self::assertFalse(DomainFilter::matches('https://notlinkedin.com/jobs/1', $domains));
        self::assertFalse(DomainFilter::matches('/relative/path', $domains));
    }

    public function testIgnoresEmptyAndNullEntitiesFromAnEmptyEnvVariable(): void
    {
        self::assertFalse(DomainFilter::matches('https://example.test/1', [null, '', '  ']));
    }

    public function testCollectionPolicyBlocksOnlyConfiguredDomains(): void
    {
        $policy = new CollectionPolicy(['linkedin.com']);

        self::assertFalse($policy->allows('https://www.linkedin.com/jobs/view/1'));
        self::assertTrue($policy->allows('https://welcometothejungle.com/jobs/1'));
        self::assertTrue(new CollectionPolicy()->allows('https://www.linkedin.com/jobs/view/1'));
    }
}
