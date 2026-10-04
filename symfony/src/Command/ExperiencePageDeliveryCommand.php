<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Delivery\PageDeliveryContextPackageBuilder;
use App\Web\Experience\Delivery\PageDeliveryEvidenceBuilder;
use App\Web\Experience\Delivery\PageDeliveryPullRequestTemplate;
use App\Web\Experience\Delivery\PageDeliveryWorkflow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:delivery',
    description: 'Prepare or start autonomous Engineering delivery for a registered COS page.',
)]
final class ExperiencePageDeliveryCommand extends Command
{
    public function __construct(
        private readonly PageDeliveryContextPackageBuilder $context,
        private readonly PageDeliveryEvidenceBuilder $evidence,
        private readonly PageDeliveryPullRequestTemplate $pullRequestTemplate,
        private readonly PageDeliveryWorkflow $workflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'Experience Page Contract id.')
            ->addOption('start', null, InputOption::VALUE_NONE, 'Create and start the existing COS Engineering autonomous workflow.')
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id used when --start is set.', 'default')
            ->addOption('correlation', null, InputOption::VALUE_REQUIRED, 'Correlation id used when --start is set.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (string) $input->getArgument('page');

        if (!$input->getOption('start')) {
            $package = $this->context->build($pageId);
            $evidence = $this->evidence->build($package);
            $io->writeln(json_encode([
                'context_package' => $package->toArray(),
                'evidence_contract' => $evidence->toArray(),
                'pull_request_template' => $this->pullRequestTemplate->render($package, $evidence),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $correlation = trim((string) $input->getOption('correlation'));
        if ($correlation === '') {
            $correlation = 'experience-'.bin2hex(random_bytes(8));
        }

        $result = $this->workflow->start(
            $pageId,
            (string) $input->getOption('organization'),
            $correlation,
        );
        $io->success(sprintf(
            'Experience delivery started: feature=%s workflow=%s state=%s next=%s',
            $result['feature_id'],
            $result['workflow_id'],
            $result['state'],
            $result['next'],
        ));

        return Command::SUCCESS;
    }
}
