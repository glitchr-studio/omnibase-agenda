<?php

namespace Base\Agenda\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class AgendaConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('sources')->scalarPrototype()->end()->defaultValue([])
                    ->info('ICS addresses agenda:sync reads (a Google Calendar\'s private address, an orchestra\'s feed).')->end()
                ->scalarNode('ics')->defaultNull()
                    ->info('More ICS addresses, comma-separated, read when the command runs: the place for "%env(AGENDA_ICS)%", which a list cannot take.')->end()
                ->integerNode('past_per_page')->min(1)->defaultValue(24)
                    ->info('Past dates listed per page.')->end()
                ->scalarNode('timezone')->defaultValue('Europe/Berlin')
                    ->info('The timezone of a new date, and of an imported one that names none.')->end()
                ->scalarNode('calendar_name')->defaultNull()
                    ->info('The name of the ICS feed; null: the site\'s title.')->end()
                ->booleanNode('jsonld')->defaultTrue()
                    ->info('A schema.org event on each date\'s page.')->end()
                ->enumNode('jsonld_type')->values(['MusicEvent', 'EducationEvent', 'Event'])->defaultValue('MusicEvent')
                    ->info('Its schema.org type: a musician\'s MusicEvent (a masterclass stays an EducationEvent), a lecturer\'s EducationEvent, or Event.')->end()
                ->booleanNode('show_past')->defaultTrue()
                    ->info('The past dates under the ones to come.')->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
