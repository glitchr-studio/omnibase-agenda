<?php

namespace Base\Admin\Attribute;

/*
 * A stand-in for omnibase/admin's #[OpenToAdmins], declared by the bundle
 * (AgendaBundle::__construct()) only when the installed omnibase/admin does not
 * have the attribute yet - before its commit 7474f85.
 *
 * The bundle's CRUD controllers carry #[OpenToAdmins]. PHP itself never
 * looks an attribute's class up, but omnibase does: its AttributeReader
 * instantiates every attribute of every routed controller when the cache is
 * warmed, and a class that does not exist stopped the site there ("Attribute
 * class Base\Admin\Attribute\OpenToAdmins not found"). On such an admin this
 * class is that name and nothing more: nobody applies it, and the screens
 * stay as that admin makes them - the super-admin's to write.
 *
 * To delete, with the lines that load it, once every application that
 * mounts the bundle has an omnibase/admin with the attribute.
 */
if (!class_exists(OpenToAdmins::class, false)) {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final class OpenToAdmins
    {
        /** @param string[] $actions */
        public function __construct(
            public readonly string $role = 'ROLE_ADMIN',
            public readonly array $actions = [],
        ) {
        }
    }
}
