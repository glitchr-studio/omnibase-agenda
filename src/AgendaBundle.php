<?php

namespace Base\Agenda;

use Base\Admin\Attribute\OpenToAdmins;
use Base\Admin\Controller\AbstractCrudController;
use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dates and venues on top of omnibase's Thread. An Event IS a Thread
 * (JOINED subclass); a Venue is a plain row many events share.
 */
class AgendaBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();

        // The CRUD controllers carry omnibase/admin's #[OpenToAdmins]: an
        // omnibase/admin that does not have it yet gets a stand-in of the
        // same name, which opens nothing (compat/OpenToAdmins.php says why).
        if (class_exists(AbstractCrudController::class) && !class_exists(OpenToAdmins::class)) {
            require_once \dirname(__DIR__).'/compat/OpenToAdmins.php';
        }
    }

    /** Modern layout: the class lives in src/, the bundle root is the package root. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // App\-wins, as omnibase does for its own entities: an application may
        // declare App\Entity\Agenda\Event extending ours and take over.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Agenda\Entity', 'App\Entity\Agenda');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Agenda\Repository', 'App\Repository\Agenda');
    }
}
