<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use PHPUnit\Framework\TestCase;
use App\Telegram\UpdateAuthorizer;

final class UpdateAuthorizerTest extends TestCase
{
    public function testAPrivateChatOnlyAcceptsItsOwner(): void
    {
        $authorizer = new UpdateAuthorizer('123456');

        self::assertTrue($authorizer->isEnabled());
        self::assertTrue($authorizer->isAuthorized(123456, 123456));
        self::assertFalse($authorizer->isAuthorized(123456, 999), 'Même chat, autre expéditeur.');
        self::assertFalse($authorizer->isAuthorized(999, 999), 'Autre chat.');
        self::assertFalse($authorizer->isAuthorized(999, 123456), 'Bon utilisateur, autre chat.');
    }

    public function testAGroupIsDisabledUntilUsersAreListed(): void
    {
        $withoutList = new UpdateAuthorizer('-100123');

        self::assertFalse($withoutList->isEnabled());
        self::assertFalse($withoutList->isAuthorized(-100123, 42), 'Tout membre pourrait appuyer sur un bouton.');
    }

    public function testAGroupOnlyAcceptsListedUsers(): void
    {
        $authorizer = new UpdateAuthorizer('-100123', ' 42, 43 ,x, ,-5');

        self::assertTrue($authorizer->isEnabled());
        self::assertTrue($authorizer->isAuthorized(-100123, 42));
        self::assertTrue($authorizer->isAuthorized(-100123, 43));
        self::assertFalse($authorizer->isAuthorized(-100123, 44));
        self::assertFalse($authorizer->isAuthorized(-100999, 42), 'Autre groupe.');
    }

    public function testMalformedAllowedIdsAreIgnoredNotTrusted(): void
    {
        $authorizer = new UpdateAuthorizer('-100123', 'tous,*,0x2a,');

        self::assertFalse($authorizer->isEnabled());
    }
}
