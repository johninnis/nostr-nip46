<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\KeyedCollection;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;
use Override;

/**
 * @extends KeyedCollection<Permission>
 */
final class PermissionCollection extends KeyedCollection
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
        return implode(self::SEPARATOR, $this->mapItems(static fn (Permission $permission): string => (string) $permission));
    }

    public function allows(Permission $requested): bool
    {
        return array_any(
            $this->toArray(),
            static fn (Permission $granted): bool => $granted->covers($requested),
        );
    }

    private static function tryParse(mixed $value): ?Permission
    {
        return is_string($value) ? Permission::tryFromString($value) : null;
    }
}
