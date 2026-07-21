<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\Collection\PermissionCollection;

final readonly class ClientMetadata
{
    public function __construct(
        private ?string $name,
        private ?string $url,
        private ?string $image,
        private PermissionCollection $requested,
    ) {
    }

    public static function fromPairingUri(PairingUri $uri): self
    {
        return new self(
            self::nonEmpty($uri->first('name')),
            self::nonEmpty($uri->first('url')),
            self::nonEmpty($uri->first('image')),
            PermissionCollection::fromPermsString($uri->first('perms') ?? ''),
        );
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getRequested(): PermissionCollection
    {
        return $this->requested;
    }

    private static function nonEmpty(?string $value): ?string
    {
        $trimmed = trim($value ?? '');

        return '' === $trimmed ? null : $trimmed;
    }
}
