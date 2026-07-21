<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;
use Override;

/**
 * @extends TypedCollection<Permission>
 */
final class PermissionCollection extends TypedCollection
{
    private const string SEPARATOR = ',';

    #[Override]
    protected function elementType(): string
    {
        return Permission::class;
    }

    public static function grantable(): self
    {
        $askable = array_filter(Nip46Method::cases(), static fn (Nip46Method $method): bool => $method->requiresAuthorisation());

        return new self(array_map(Permission::forMethod(...), $askable));
    }

    public static function fromPermsString(string $perms): self
    {
        return self::fromEach(explode(self::SEPARATOR, $perms), self::tryParse(...))->unique();
    }

    public function unique(): self
    {
        return new self($this->deduplicate(self::keyOf(...)));
    }

    public function minimal(): self
    {
        $permissions = $this->unique()->toArray();

        return new self(array_values(array_filter(
            $permissions,
            static fn (Permission $permission): bool => !array_any(
                $permissions,
                static fn (Permission $other): bool => !$other->equals($permission) && $other->covers($permission),
            ),
        )));
    }

    public function toPermsString(): string
    {
        return implode(self::SEPARATOR, $this->mapItems(self::keyOf(...)));
    }

    public function allows(Permission $requested): bool
    {
        return array_any(
            $this->toArray(),
            static fn (Permission $granted): bool => $granted->covers($requested),
        );
    }

    private static function keyOf(Permission $permission): string
    {
        return (string) $permission;
    }

    private static function tryParse(mixed $value): ?Permission
    {
        return is_string($value) ? Permission::tryFromString($value) : null;
    }
}
