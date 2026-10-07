<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MakeAdminCommand;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MakeAdminCommandTest extends KernelTestCase
{
    private const EMAIL = 'command-admin@foodjett.test';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->deleteAdmin();
    }

    protected function tearDown(): void
    {
        $this->deleteAdmin();
        parent::tearDown();
    }

    public function testCommandCreatesHashedAdminAccountAndRejectsDuplicate(): void
    {
        $command = self::getContainer()->get(MakeAdminCommand::class);
        $tester = new CommandTester($command);
        $status = $tester->execute([
            'name' => 'Command Admin',
            'email' => self::EMAIL,
            'password' => 'AdminPassword123!',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserRole::ADMIN, $user->getRole());
        self::assertNotNull($user->getAdmin());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'AdminPassword123!'));

        $duplicateStatus = $tester->execute([
            'name' => 'Duplicate Admin',
            'email' => self::EMAIL,
            'password' => 'AdminPassword123!',
        ]);
        self::assertSame(Command::FAILURE, $duplicateStatus);
    }

    private function deleteAdmin(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if (!$user instanceof User) {
            return;
        }

        if (null !== $user->getAdmin()) {
            $entityManager->remove($user->getAdmin());
        }
        $entityManager->remove($user);
        $entityManager->flush();
    }
}
