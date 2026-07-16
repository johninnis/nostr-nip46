<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class UnsignedEventInput
{
    private function __construct(
        private EventKind $kind,
        private ?Timestamp $createdAt,
        private TagCollection $tags,
        private EventContent $content,
    ) {
    }

    public function getKind(): EventKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): ?Timestamp
    {
        return $this->createdAt;
    }

    public function getTags(): TagCollection
    {
        return $this->tags;
    }

    public function getContent(): EventContent
    {
        return $this->content;
    }

    public function toRumour(PublicKey $author, Timestamp $fallbackCreatedAt): Rumour
    {
        return RumourFactory::createCustomKind(
            $author,
            $this->kind,
            $this->content,
            $this->tags,
            $this->createdAt ?? $fallbackCreatedAt,
        );
    }

    public static function tryFromWire(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $rawKind = $value['kind'] ?? null;

        if (!is_int($rawKind)) {
            return null;
        }

        $kind = EventKind::tryFromInt($rawKind);

        if (null === $kind) {
            return null;
        }

        $createdAt = null;
        $rawCreatedAt = $value['created_at'] ?? null;

        if (null !== $rawCreatedAt) {
            $createdAt = self::parseCreatedAt($rawCreatedAt);

            if (null === $createdAt) {
                return null;
            }
        }

        $tags = self::parseTags($value['tags'] ?? null);

        if (null === $tags) {
            return null;
        }

        $content = $value['content'] ?? '';

        if (!is_string($content)) {
            return null;
        }

        return new self($kind, $createdAt, $tags, EventContent::fromString($content));
    }

    private static function parseCreatedAt(mixed $value): ?Timestamp
    {
        return is_int($value) ? Timestamp::tryFromInt($value) : null;
    }

    private static function parseTags(mixed $value): ?TagCollection
    {
        if (null === $value) {
            return new TagCollection();
        }

        if (!is_array($value)) {
            return null;
        }

        return TagCollection::tryFromArray($value);
    }
}
