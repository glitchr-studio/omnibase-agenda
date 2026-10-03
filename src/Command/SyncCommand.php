<?php

namespace Base\Agenda\Command;

use Base\Agenda\Service\IcsImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The calendars read into the agenda - agenda.sources, agenda.ics and the
 * address typed in the back office (/admin/agenda/calendar): new dates
 * online, changed ones brought up to date, gone ones cancelled. Run it
 * from cron (every half hour), or from the back office's "Sync now"
 * button; what it did is kept for that page. --source reads one address
 * or file instead, and is not kept.
 */
#[AsCommand(name: 'agenda:sync', description: 'Read the calendars (Google Calendar, ICS, Squarespace, another agenda feed) into the agenda.')]
final class SyncCommand extends Command
{
    public function __construct(private readonly IcsImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An address (ICS, Squarespace events page, /agenda.json) or a file to read instead of the configured calendars');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $given = $input->getOption('source');
        $sources = $given ?: $this->importer->getSources();
        if (!$sources) {
            $io->note('No calendar to read: paste its address in the back office (Agenda › Google Calendar), set agenda.sources, or pass --source.');

            return Command::SUCCESS;
        }

        $summary = $this->importer->sync($sources);
        if (!$given) {
            $this->importer->remember($summary);
        }
        foreach ($summary->errors as $error) {
            $io->error($error);
        }
        $io->definitionList(...array_map(fn ($k, $v) => [$k => $v], array_keys($summary->toArray()), $summary->toArray()));

        return $summary->errors && \count($summary->errors) === \count($sources) ? Command::FAILURE : Command::SUCCESS;
    }
}
