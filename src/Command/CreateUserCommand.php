<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand('hunch:user:create', 'Provision a user. They set their own password via the printed activation link.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the new user')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Set a password directly (bootstrap; skips the activation link)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if ($this->users->findOneByEmail($email)) {
            $io->error("A user with email {$email} already exists.");

            return Command::FAILURE;
        }

        $user = (new User())->setEmail($email);
        if ($input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $password = $input->getOption('password');
        if (null !== $password && '' !== $password) {
            // Bootstrap path: set the password directly, no activation link.
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $this->em->flush();
            $io->success(\sprintf('Created %s%s — can log in now.', $email, $user->isAdmin() ? ' (admin)' : ''));

            return Command::SUCCESS;
        }

        // Invite path: user sets their own password via a one-time link.
        $token = $user->startActivation();
        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Created %s%s.', $email, $user->isAdmin() ? ' (admin)' : ''));
        $io->writeln('Send them this activation link to set their password:');
        $io->writeln("  /activate/{$token}");
        $io->note('Prefix with your app URL, e.g. http://localhost:8000/activate/'.$token);

        return Command::SUCCESS;
    }
}
