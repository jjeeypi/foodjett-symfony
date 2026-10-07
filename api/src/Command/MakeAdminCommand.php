<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Admin;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:make-admin', description: 'Create a Foodjett administrator account.')]
final class MakeAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Administrator name')
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Initial password (at least 8 characters)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = trim((string) $input->getArgument('name'));
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');

        $errors = [
            ...$this->validator->validate($name, [new Assert\NotBlank(), new Assert\Length(max: 255)]),
            ...$this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 255)]),
            ...$this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)]),
        ];

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $io->error((string) $error->getMessage());
            }

            return Command::INVALID;
        }

        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            $io->error('A user with that email address already exists.');

            return Command::FAILURE;
        }

        $this->entityManager->wrapInTransaction(function () use ($name, $email, $password): void {
            $now = new \DateTimeImmutable();
            $user = (new User())
                ->setName($name)
                ->setEmail($email)
                ->setRole(UserRole::ADMIN)
                ->setStatus(UserStatus::ACTIVE)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $admin = (new Admin())
                ->setUser($user)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($admin);
            $this->entityManager->flush();
        });

        $io->success(sprintf('Administrator %s <%s> was created.', $name, $email));

        return Command::SUCCESS;
    }
}
