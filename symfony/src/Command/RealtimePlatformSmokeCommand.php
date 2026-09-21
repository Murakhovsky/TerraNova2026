<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Realtime\RealtimeStreamPublisher;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:web:realtime:smoke',
    description: 'Validate the canonical COS Mercure and Turbo Stream realtime runtime.',
)]
final class RealtimePlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly RealtimeTopicFactory $topics,
        private readonly RealtimeStreamPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->topics->organization('default');
        $workspace = $this->topics->workspace('default', 'wave12.12.smoke');

        if (
            $organization->value !== 'https://realtime.cos.internal/organizations/default'
            || $workspace->value !== 'https://realtime.cos.internal/organizations/default/workspaces/wave12.12.smoke'
        ) {
            $output->writeln('<error>Realtime topic identity is invalid.</error>');

            return Command::FAILURE;
        }

        $updateId = $this->publisher->publish(
            $workspace,
            'experience/realtime/streams/demo.stream.html.twig',
            [
                'message' => 'Wave 12.12 realtime smoke',
                'publishedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
        );

        if (trim($updateId) === '') {
            $output->writeln('<error>Mercure did not return an update id.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS Realtime Platform runtime passed.');

        return Command::SUCCESS;
    }
}
