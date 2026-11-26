<?php

declare(strict_types=1);

namespace GettingStarted\Command;

use GettingStarted\Domain\Contact;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWorkRunner;

#[AsCommand(name: 'app:contact:rename', description: 'Create or rename a persisted contact.')]
final class RenameContact extends Command
{
    public function __construct(
        private readonly StateStore $states,
        private readonly UnitOfWorkRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED);
        $this->addArgument('name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $name = $input->getArgument('name');
        assert(is_string($id) && is_string($name), 'Required console arguments are strings.');

        $this->runner->run(function () use ($id, $name): void {
            $this->states->observe(Contact::class, $id);
            $this->states->save(new Contact($id, $name));
        });

        $contact = $this->runner->run(fn(): ?Contact => $this->states->load(Contact::class, $id));
        if (null === $contact || $contact->name !== $name) {
            throw new \RuntimeException('The committed contact could not be read back.');
        }
        $output->writeln($contact->id . ': ' . $contact->name);

        return Command::SUCCESS;
    }
}
