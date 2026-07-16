<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum Nip46FailureReason
{
    case EncryptionFailed;
    case TimedOut;
    case Rejected;
    case InvalidResponse;
    case IdentityMismatch;
    case InvalidSignature;
}
