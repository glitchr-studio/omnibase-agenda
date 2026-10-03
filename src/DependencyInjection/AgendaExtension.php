<?php

namespace Base\Agenda\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class AgendaExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): AgendaConfiguration
    {
        return new AgendaConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new AgendaConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // Flat parameters: agenda.sources (the list itself), agenda.past_per_page, agenda.timezone...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
