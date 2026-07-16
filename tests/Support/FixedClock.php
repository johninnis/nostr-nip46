<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Override;

final class FixedClock implements ClockInterface
{
    public function __construct(private int $seconds)
    {
    }

    #[Override]
    public function now(): Timestamp
    {
        return Timestamp::fromInt($this->seconds);
    }

    public function advance(int $seconds): void
    {
        $this->seconds += $seconds;
    }
}
