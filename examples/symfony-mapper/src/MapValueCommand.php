<?php

namespace App;

use App\VO\Email;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizerGenerator;

#[AsCommand(name: 'app:run')]
class MapValueCommand
{
    public function __construct(private UltraNormalizer $normalizer) {}

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Mapping value objects with Ultra Mapper');
        $output->writeln('---------------------------------------');
        $output->writeln(sprintf('ultra/mapper-compiler : %s', class_exists(NormalizerGenerator::class) ? 'Yes' : 'No'));
        $email = $this->normalizer->fromScalar('john@example.com', Email::class);

        $emailDTO = $this->normalizer->toScalar($email);
        $success = $emailDTO === 'john@example.com';

        $output->writeln(sprintf('Success : %s', $success ? 'Yes' : 'No'));

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
