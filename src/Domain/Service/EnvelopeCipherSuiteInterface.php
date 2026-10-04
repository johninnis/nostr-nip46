<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Service\ConversationCipherInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;

interface EnvelopeCipherSuiteInterface
{
    public function cipherFor(EnvelopeCipher $cipher): ConversationCipherInterface;
}
