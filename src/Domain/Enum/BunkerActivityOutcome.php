<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum BunkerActivityOutcome
{
    case Answered;
    case Failed;
}
