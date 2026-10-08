<?php

namespace Base\Agenda;

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
