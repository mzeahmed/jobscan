<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Matching\PublicationAge;
use App\AI\Extraction\TextMatcher;
use App\DTO\PublicationDateSource;
use App\AI\Extraction\LanguageGroups;
use App\DTO\PublicationDateConfidence;
use App\Profile\Model\CandidateProfile;

final class MatchingSupportTest extends MatchingTestCase
{
    public function testContainsWordMatchesWholeWordsRegardlessOfLength(): void
    {
        $matcher = new TextMatcher('Équipe de backstage. Stage de six mois, chef de projet.');

        self::assertTrue($matcher->containsWord('stage'));
        self::assertTrue($matcher->containsWord('Chef de projet'));
        self::assertTrue($matcher->containsWord('EQUIPE'));
        self::assertFalse($matcher->containsWord('stag'));
        self::assertFalse($matcher->containsWord(''));
    }

    public function testContainsWordRejectsASubstringOfAnotherWord(): void
    {
        self::assertFalse(new TextMatcher('Du backstage uniquement')->containsWord('stage'));
        self::assertFalse(new TextMatcher('Stagiaire apprécié')->containsWord('stage'));
    }

    public function testLanguageGroupsShareAKeyAcrossSpellings(): void
    {
        self::assertSame(LanguageGroups::key('Anglais'), LanguageGroups::key('english'));
        self::assertSame(LanguageGroups::key('Allemand'), LanguageGroups::key('Deutsch'));
        self::assertNotSame(LanguageGroups::key('Anglais'), LanguageGroups::key('Allemand'));
        self::assertSame('klingon', LanguageGroups::key('Klingon'), 'Une langue inconnue garde son propre nom.');
        self::assertSame(['anglais', 'english'], LanguageGroups::names('English'));
    }

    public function testPublicationAgeDistinguishesUnknownFromKnown(): void
    {
        $now = new \DateTimeImmutable('2026-11-30 12:00:00');

        self::assertSame('publication inconnue', PublicationAge::describe($this->job(null, PublicationDateConfidence::Unknown), $now));
        self::assertSame('publication inconnue', PublicationAge::describe($this->job($now->modify('-1 hour'), PublicationDateConfidence::Unknown), $now));
        self::assertSame('5 h', PublicationAge::describe($this->job($now->modify('-5 hours'), PublicationDateConfidence::High), $now));
        self::assertSame('0 h', PublicationAge::describe($this->job($now->modify('-10 minutes'), PublicationDateConfidence::High), $now));
        self::assertSame('3 j', PublicationAge::describe($this->job($now->modify('-3 days'), PublicationDateConfidence::Medium), $now));
        self::assertSame('2 j (date peu fiable)', PublicationAge::describe($this->job($now->modify('-50 hours'), PublicationDateConfidence::Low), $now));
        self::assertSame('date future', PublicationAge::describe($this->job($now->modify('+3 days'), PublicationDateConfidence::High), $now));
    }

    public function testExperienceYearsCountOverlappingPeriodsOnce(): void
    {
        $candidate = $this->candidateWith([
            ['2018-01', '2020-12'],   // 36 mois
            ['2019-06', '2021-05'],   // chevauche : ajoute 2021-01 → 2021-05 = 5 mois
            ['2023-01', '2023-12'],   // 12 mois
        ]);

        self::assertSame(4.4, $candidate->experienceYears(new \DateTimeImmutable('2026-11-30')));
    }

    public function testAnOngoingExperienceRunsUntilNow(): void
    {
        $candidate = $this->candidateWith([['2024-01', null]]);

        self::assertSame(2.9, $candidate->experienceYears(new \DateTimeImmutable('2026-11-15')), 'De janvier 2024 à novembre 2026 inclus : 35 mois.');
    }

    public function testExperienceIsUnknownNotZeroWhenNoneIsDeclared(): void
    {
        self::assertNull($this->candidateWith([])->experienceYears(new \DateTimeImmutable()));
    }

    /** @param list<array{string, ?string}> $periods */
    private function candidateWith(array $periods): CandidateProfile
    {
        return self::candidate(experiences: array_map(
            static fn (array $period) => self::experience('X', 'Y', $period[0], $period[1]),
            $periods,
        ));
    }

    private function job(?\DateTimeImmutable $publishedAt, PublicationDateConfidence $confidence): Job
    {
        return Job::fromDTO(new JobDto('T', 'https://e.test/1', 'D', 'test', $publishedAt, publicationDateSource: PublicationDateSource::Api, publicationDateConfidence: $confidence));
    }
}
