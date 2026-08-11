<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processor;

use App\DTO\JobDto;
use App\Processor\JobIdentity;
use PHPUnit\Framework\TestCase;

final class JobIdentityTest extends TestCase
{
    public function testCanonicalUrlRemovesTrackingAndSortsParameters(): void
    {
        $identity = new JobIdentity();

        $url = $identity->canonicalUrl('https://Example.com/jobs/42/?utm_source=x&b=2&a=1#apply');

        self::assertSame('https://example.com/jobs/42?a=1&b=2', $url);
    }

    public function testFingerprintUsesTitleCompanyAndLocation(): void
    {
        $identity = new JobIdentity();
        $first = new JobDto('Développeur PHP!', 'https://a.test/1', '', 'test', company: 'ACME', location: 'Paris');
        $same = new JobDto('développeur php', 'https://b.test/2', '', 'test', company: 'Acme', location: 'PARIS');

        self::assertSame($identity->fingerprint($first), $identity->fingerprint($same));
    }

    public function testTitleAloneDoesNotCreateFingerprint(): void
    {
        $job = new JobDto('Développeur', 'https://example.test/1', '', 'test');

        self::assertNull(new JobIdentity()->fingerprint($job));
    }
}
