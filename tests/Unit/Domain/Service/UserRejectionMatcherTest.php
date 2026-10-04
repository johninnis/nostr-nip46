<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Service;

use Innis\Nostr\Nip46\Domain\Service\UserRejectionMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRejectionMatcherTest extends TestCase
{
    #[DataProvider('declines')]
    public function testRecognisesADeclineByItsWords(string $error): void
    {
        $this->assertTrue(UserRejectionMatcher::matches($error));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declines(): iterable
    {
        yield 'the bunker decline' => ['user rejected'];
        yield 'denied' => ['Permission denied'];
        yield 'cancel' => ['user did cancel'];
        yield 'canceled' => ['Request canceled'];
        yield 'cancelled' => ['request cancelled by the user'];
        yield 'upper case' => ['REJECTED'];
    }

    #[DataProvider('failures')]
    public function testTreatsAnyOtherErrorAsNotADecline(string $error): void
    {
        $this->assertFalse(UserRejectionMatcher::matches($error));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'invalid secret' => ['invalid secret'];
        yield 'decryption failed' => ['decryption failed'];
        yield 'a word containing a decline word' => ['cancellation policy unavailable'];
        yield 'refused' => ['refused'];
        yield 'empty' => [''];
    }
}
