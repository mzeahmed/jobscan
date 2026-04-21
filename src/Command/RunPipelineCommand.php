<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Processor\JobProcessor;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Provider\JobProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:jobs:run',
    description: "Lance le pipeline d'agrégation et de scoring des offres d'emploi freelance.",
)]
final class RunPipelineCommand extends Command
{
    /**
     * @param  iterable<JobProviderInterface>  $providers  Injecté via tagged_iterator app.job_provider
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly JobProcessor $processor,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OPPSCAN — Pipeline offres freelance PHP/Symfony');

        $total = 0;

        foreach ($this->providers as $provider) {
            $name = (new \ReflectionClass($provider))->getShortName();
            $io->section('Provider : ' . $name);

            $jobs = $provider->fetch();
            $io->writeln(sprintf('  → <info>%d</info> offre(s) récupérée(s)', \count($jobs)));

            foreach ($jobs as $dto) {
                $this->processor->process($dto);
                $total++;
            }
        }

        $io->success(sprintf('Pipeline terminé. %d offre(s) traitée(s).', $total));

        $this->em->flush();

        return Command::SUCCESS;
    }
}
