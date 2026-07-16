<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthUrlListenerInterface;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;

interface Nip46ClientInterface
{
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;

    public function connect(BunkerUrl $bunker, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): PublicKey|Nip46Failure;

    public function signEvent(Rumour $unsigned, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): Event|Nip46Failure;

    public function nip44Encrypt(PublicKey $peer, string $plaintext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure;

    public function nip44Decrypt(PublicKey $peer, string $ciphertext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure;

    public function nip04Encrypt(PublicKey $peer, string $plaintext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure;

    public function nip04Decrypt(PublicKey $peer, string $ciphertext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure;

    public function setAuthUrlListener(?Nip46AuthUrlListenerInterface $listener): void;

    public function close(): void;
}
