<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Everything in src/ is a plain autowired service, the way an application's
 * own src/ is. The backoffice CRUD controllers and the dashboard widget are
 * loaded only when omnibase/admin is installed, the newsletter's digest
 * source only when omnibase/newsletter is.
 */
return function (ContainerConfigurator $configurator) {
    $src = dirname(__DIR__).'/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Agenda\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Controller/Admin/',
            $src.'/Admin/',
            $src.'/Digest/',
            $src.'/AgendaBundle.php',
        ]);

    $services->load('Base\\Agenda\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Agenda\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
        $services->load('Base\\Agenda\\Admin\\', $src.'/Admin/');
    }

    if (interface_exists('Base\\Newsletter\\Digest\\DigestSourceInterface')) {
        $services->load('Base\\Agenda\\Digest\\', $src.'/Digest/');
    }
};
