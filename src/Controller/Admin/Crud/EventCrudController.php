<?php

namespace Base\Agenda\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Attribute\OpenToAdmins;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Agenda\Entity\Event;
use Base\Agenda\Enum\Role;
use Base\Agenda\Service\IcsImporter;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateTimePickerField;
use Base\Field\EditorField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\StateField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dates: when, where, with whom, what is played, where the tickets
 * are. Published, a date is on /agenda; a date read from a calendar
 * follows it at each sync, unless "Keep the site's texts" (syncLocked) is
 * ticked - then only its hours and its cancellation follow. Two buttons on
 * top: duplicate a date (the next one of a tour) and read the calendars now.
 */
#[OpenToAdmins(actions: ['duplicate', 'sync'])]
class EventCrudController extends AbstractCrudController
{
    private IcsImporter $importer;
    private TranslatorInterface $translator;
    private string $timezone = 'Europe/Berlin';

    #[Required]
    public function setAgendaServices(IcsImporter $importer, TranslatorInterface $translator, #[Autowire('%agenda.timezone%')] string $timezone = 'Europe/Berlin'): void
    {
        $this->importer = $importer;
        $this->translator = $translator;
        $this->timezone = $timezone;
    }

    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-calendar-days';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('venue')->add('role')->add('startsAt')->add('cancelled');
    }

    public function configureFields(string $pageName): iterable
    {
        $roles = [];
        foreach (Role::cases() as $role) {
            $roles[$this->translator->trans($role->label(), [], 'agenda')] = $role->value;
        }

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', '@agenda.admin.event.title')->setColumns(8);
        yield StateField::new('state')->setColumns(4);
        yield SlugField::new('slug')->setColumns(4)->hideOnIndex();
        yield DateTimePickerField::new('startsAt', '@agenda.admin.event.starts_at')->setFormTypeOption('input', 'datetime_immutable')->setColumns(3);
        yield DateTimePickerField::new('endsAt', '@agenda.admin.event.ends_at')->setFormTypeOption('input', 'datetime_immutable')->setColumns(3)->hideOnIndex();
        yield TextField::new('timezone', '@agenda.admin.event.timezone')->setColumns(2)->hideOnIndex()->setHelp('@agenda.admin.event.timezone_help');
        yield BooleanField::new('allDay', '@agenda.admin.event.all_day')->setColumns(2)->hideOnIndex();
        yield BooleanField::new('cancelled', '@agenda.admin.event.cancelled')->setColumns(2);
        yield AssociationField::new('venue', '@agenda.admin.event.venue')->setColumns(6)->setRequired(false);
        yield SelectField::new('role', '@agenda.admin.event.role')->setChoices($roles)->setColumns(3);
        yield TextField::new('ensemble', '@agenda.admin.event.ensemble')->setColumns(6)->hideOnIndex();
        yield TextField::new('conductor', '@agenda.admin.event.conductor')->setColumns(6)->hideOnIndex();
        yield TextareaField::new('programme', '@agenda.admin.event.programme')->hideOnIndex()->setHelp('@agenda.admin.event.programme_help');
        yield TextareaField::new('performers', '@agenda.admin.event.performers')->hideOnIndex()->setHelp('@agenda.admin.event.performers_help');
        yield TextField::new('ticketsUrl', '@agenda.admin.event.tickets_url')->setColumns(6)->hideOnIndex();
        yield TextField::new('eventUrl', '@agenda.admin.event.event_url')->setColumns(6)->hideOnIndex();
        yield ImageField::new('cover', '@agenda.admin.event.cover')->setColumns(6)->hideOnIndex();
        yield TextField::new('headline', '@agenda.admin.event.headline')->setColumns(12)->hideOnIndex();
        yield TextareaField::new('excerpt', '@agenda.admin.event.excerpt')->hideOnIndex()->setHelp('@agenda.admin.event.excerpt_help');
        yield EditorField::new('content', '@agenda.admin.event.content')->hideOnIndex();
        yield TextField::new('sourceUid', '@agenda.admin.event.source_uid')->onlyOnDetail();
        // Only a date read from a calendar has texts a sync could overwrite.
        $event = $this->adminContext->getEntity();
        if ($event instanceof Event && $event->isImported()) {
            yield BooleanField::new('syncLocked', '@agenda.admin.event.sync_locked')->hideOnIndex()->setColumns(12)->setHelp('@agenda.admin.event.sync_locked_help');
        }
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL, Actions::PAGE_EDIT] as $page) {
            $actions->add($page, Action::new('duplicate', '@agenda.admin.event.action.duplicate', 'fa-solid fa-clone')->linkToCrudAction('duplicate'));
        }
        if ($this->importer->hasSources()) {
            $actions->add(Actions::PAGE_INDEX, Action::new('sync', '@agenda.admin.event.action.sync', 'fa-solid fa-rotate')
                ->createAsGlobalAction()
                ->linkToCrudAction('sync')
                ->askConfirmation('@agenda.admin.event.action.sync_confirm'));
        }

        return $actions;
    }

    public function createEntity(string $entityFqcn): object
    {
        $event = new Event();
        $event->setTimezone($this->timezone);
        $user = $this->getUser();
        if ($user instanceof \Base\Entity\User) {
            $event->addOwner($user);
        }

        return $event;
    }

    /** A copy of the date, a draft, to set the next one of a tour: same venue, same programme. */
    #[AdminAction('/{entityId}/duplicate')]
    public function duplicate(string $entityId): Response
    {
        /** @var Event $event */
        $event = $this->findEntity($entityId);
        $copy = $event->duplicate(' ('.$this->translator->trans('admin.event.copy', [], 'agenda').')');
        $this->entityManager->persist($copy);
        $this->entityManager->flush();
        $this->addFlash('success', '@agenda.admin.event.flash.duplicated');

        return $this->redirect($this->adminUrlGenerator->setController(static::class)->setAction(Action::EDIT)->setEntityId($copy->getId())->generateUrl());
    }

    /** The calendars read now, as agenda:sync does. */
    #[AdminAction('/sync')]
    public function sync(): Response
    {
        if (!$this->importer->hasSources()) {
            $this->addFlash('warning', '@agenda.admin.event.flash.no_source');

            return $this->redirectToIndex();
        }

        $summary = $this->importer->sync();
        $this->importer->remember($summary);
        foreach ($summary->errors as $error) {
            $this->addFlash('danger', $error);
        }
        $this->addFlash('success', $this->translator->trans('admin.event.flash.synced', $summary->toArray(), 'agenda'));

        return $this->redirectToIndex();
    }
}
