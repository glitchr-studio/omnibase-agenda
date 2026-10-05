<?php

namespace Base\Agenda\Tests\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Agenda\Controller\Admin\Crud\EventCrudController;
use Base\Agenda\Controller\Admin\Crud\VenueCrudController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The agenda's screens are written by the site's administrator (ROLE_ADMIN:
 * the artist, the speaker, their agent), not by the super-admin only: a
 * date and a venue are created, changed and removed, a date is duplicated,
 * the calendars are read on demand. Someone who is not an administrator
 * does none of it.
 */
final class OpenToAdminsTest extends KernelTestCase
{
    private const WRITES = [Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER];

    protected function setUp(): void
    {
        // Run by a host application's PHPUnit (see README): its kernel, its container.
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS'])) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        if (!method_exists(Actions::class, 'openTo')) {
            self::markTestSkipped('Needs an omnibase/admin that has #[OpenToAdmins]: before it, these screens are the super-admin\'s.');
        }

        self::bootKernel();
        $request = Request::create('/admin');
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);
    }

    /**
     * Each screen, its own actions (#[AdminAction]), and whether records are created there.
     *
     * @return iterable<string, array{class-string<AbstractCrudController>, list<string>, bool}>
     */
    public static function cruds(): iterable
    {
        yield 'events' => [EventCrudController::class, ['duplicate', 'sync'], true];
        yield 'venues' => [VenueCrudController::class, [], true];
    }

    /**
     * @param class-string<AbstractCrudController> $class
     * @param list<string>                         $own
     */
    #[DataProvider('cruds')]
    public function testAnAdministratorWrites(string $class, array $own, bool $creates): void
    {
        $crud = $this->crudAs($class, 'ROLE_ADMIN');

        foreach ([...self::WRITES, ...$own] as $action) {
            $this->assertSame('ROLE_ADMIN', $this->actions($crud)->getEffectivePermission($action), $action);
        }
        $this->assertSame($creates, \array_key_exists(Action::NEW, $this->actions($crud)->getAll(Actions::PAGE_INDEX)), 'the "new" button');
        $this->assertArrayHasKey(Action::EDIT, $this->actions($crud)->getAll(Actions::PAGE_INDEX), 'the "edit" button is drawn');
        foreach ([...($creates ? [Action::NEW] : []), Action::EDIT, Action::DELETE, ...$own] as $action) {
            $this->assertTrue($this->mayRun($crud, $action), $action);
        }
    }

    /**
     * @param class-string<AbstractCrudController> $class
     * @param list<string>                         $own
     */
    #[DataProvider('cruds')]
    public function testSomeoneElseIsRefused(string $class, array $own, bool $creates): void
    {
        $crud = $this->crudAs($class, 'ROLE_USER');

        foreach ([Action::NEW, Action::EDIT, Action::DELETE] as $action) {
            $this->assertArrayNotHasKey($action, $this->actions($crud)->getAll(Actions::PAGE_INDEX), 'no "'.$action.'" button');
        }
        foreach ([Action::NEW, Action::EDIT, Action::DELETE, ...$own] as $action) {
            $this->assertFalse($this->mayRun($crud, $action), $action);
        }
    }

    /**
     * The container's CRUD, for a user holding these roles (what the
     * controller worked out for the previous user is forgotten).
     *
     * @param class-string<AbstractCrudController> $class
     */
    private function crudAs(string $class, string ...$roles): AbstractCrudController
    {
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('someone', null, $roles), 'main', $roles)
        );
        $crud = static::getContainer()->get($class);
        (new \ReflectionProperty(AbstractCrudController::class, 'actionsConfig'))->setValue($crud, null);
        (new \ReflectionProperty(AbstractCrudController::class, 'actionPermissions'))->setValue($crud, []);

        return $crud;
    }

    private function actions(AbstractCrudController $crud): Actions
    {
        return (new \ReflectionMethod($crud, 'getActionsConfig'))->invoke($crud);
    }

    /** What the back office asks before it runs an action (AbstractCrudController::denyAccessUnlessGrantedToRun()). */
    private function mayRun(AbstractCrudController $crud, string $action): bool
    {
        $page = Action::NEW === $action ? Crud::PAGE_NEW : (Action::EDIT === $action ? Crud::PAGE_EDIT : Crud::PAGE_INDEX);
        $config = (new \ReflectionMethod($crud, 'getCrudConfig'))->invoke($crud, $page, $action);

        try {
            (new \ReflectionMethod($crud, 'denyAccessUnlessGrantedToRun'))->invoke($crud, $config);
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
