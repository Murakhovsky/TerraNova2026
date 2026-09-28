<?php
declare(strict_types=1);

namespace App\Command;

use App\Application\Growth\Operations\GrowthProductionSmokeService;
use InvalidArgumentException;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\ModuleControlService;
use Kernel\Observability\CorrelationId;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:growth:cutover',description:'Controlled Growth production activation, live verification and rollback for one organization.')]
final class GrowthProductionCutoverCommand extends Command
{
    public function __construct(
        private readonly ModuleControlService $modules,
        private readonly ModuleLifecycleRepositoryInterface $installations,
        private readonly GrowthProductionSmokeService $smoke,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this
            ->addArgument('action',InputArgument::REQUIRED,'status|enable|verify|rollback')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Organization id.','default')
            ->addOption('actor',null,InputOption::VALUE_REQUIRED,'Positive authenticated actor id for lifecycle writes.','0')
            ->addOption('candidate',null,InputOption::VALUE_REQUIRED,'Real Growth Candidate id for live closed-loop verification.')
            ->addOption('reason',null,InputOption::VALUE_REQUIRED,'Audit reason for enable/rollback.')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'Explicit confirmation token for lifecycle writes.');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $action=strtolower(trim((string)$input->getArgument('action')));
        $organizationId=trim((string)$input->getOption('organization'))?:'default';
        $candidate=$this->nullable($input->getOption('candidate'));

        try{
            return match($action){
                'status'=>$this->status($organizationId,$output),
                'enable'=>$this->enable($input,$organizationId,$output),
                'verify'=>$this->verify($organizationId,$candidate,$output),
                'rollback'=>$this->rollback($input,$organizationId,$output),
                default=>throw new InvalidArgumentException('Growth cutover action must be status, enable, verify or rollback.'),
            };
        }catch(InvalidArgumentException|RuntimeException $error){
            $this->write($output,['ok'=>false,'action'=>$action,'organization_id'=>$organizationId,'error'=>$error->getMessage()]);
            return Command::FAILURE;
        }catch(Throwable $error){
            $this->write($output,['ok'=>false,'action'=>$action,'organization_id'=>$organizationId,'error'=>'Growth production cutover failed: '.get_class($error)]);
            return Command::FAILURE;
        }
    }

    private function status(string $organizationId,OutputInterface $output):int
    {
        $report=$this->smoke->run($organizationId);
        $this->write($output,['action'=>'status']+$report);
        return Command::SUCCESS;
    }

    private function enable(InputInterface $input,string $organizationId,OutputInterface $output):int
    {
        $this->confirm($input,'ENABLE_GROWTH');
        $actor=$this->actor($input);
        $correlation=CorrelationId::generate()->value();
        $reason=$this->nullable($input->getOption('reason'))??'Growth production cutover.';
        $installation=$this->installations->find($organizationId,'growth');
        $module=$installation===null||!$installation->isInstalled()
            ? $this->modules->install($organizationId,'growth',(string)$actor,$correlation,$reason)
            : $this->modules->enable($organizationId,'growth',(string)$actor,$correlation,$reason);
        $report=$this->smoke->run($organizationId);

        if(!$report['ok']){
            $rollbackCorrelation=CorrelationId::generate()->value();
            $rollback=$this->modules->disable(
                $organizationId,'growth',(string)$actor,$rollbackCorrelation,
                'Automatic rollback: basic Growth production smoke failed after enable.',
            );
            $this->write($output,[
                'ok'=>false,'action'=>'enable','organization_id'=>$organizationId,
                'correlation_id'=>$correlation,'module'=>$module,'smoke'=>$report,
                'automatic_rollback'=>['correlation_id'=>$rollbackCorrelation,'module'=>$rollback],
            ]);
            return Command::FAILURE;
        }

        $this->write($output,[
            'ok'=>true,'action'=>'enable','organization_id'=>$organizationId,
            'correlation_id'=>$correlation,'module'=>$module,'smoke'=>$report,
            'next'=>'Run verify with a real Candidate after the live Market → Outcome loop has completed.',
        ]);
        return Command::SUCCESS;
    }

    private function verify(string $organizationId,?string $candidate,OutputInterface $output):int
    {
        if($candidate===null)throw new InvalidArgumentException('Growth live verification requires --candidate.');
        $report=$this->smoke->run($organizationId,$candidate,true);
        $this->write($output,['action'=>'verify']+$report);
        return $report['ok']?Command::SUCCESS:Command::FAILURE;
    }

    private function rollback(InputInterface $input,string $organizationId,OutputInterface $output):int
    {
        $this->confirm($input,'DISABLE_GROWTH');
        $actor=$this->actor($input);
        $correlation=CorrelationId::generate()->value();
        $reason=$this->nullable($input->getOption('reason'))??'Growth production rollback.';
        $module=$this->modules->disable($organizationId,'growth',(string)$actor,$correlation,$reason);
        $this->write($output,[
            'ok'=>true,'action'=>'rollback','organization_id'=>$organizationId,
            'correlation_id'=>$correlation,'module'=>$module,
            'schema_rollback'=>false,
            'note'=>'Runtime activation is disabled; Growth schema and evidence are intentionally preserved.',
        ]);
        return Command::SUCCESS;
    }

    private function actor(InputInterface $input):int
    {
        $raw=trim((string)$input->getOption('actor'));
        if(!ctype_digit($raw)||(int)$raw<=0)throw new InvalidArgumentException('--actor must be a positive numeric user id.');
        return (int)$raw;
    }

    private function confirm(InputInterface $input,string $expected):void
    {
        if(trim((string)$input->getOption('confirm'))!==$expected){
            throw new InvalidArgumentException('Lifecycle write requires --confirm='.$expected.'.');
        }
    }

    private function nullable(mixed $value):?string
    {
        if($value===null)return null;
        $value=trim((string)$value);
        return $value===''?null:$value;
    }

    private function write(OutputInterface $output,array $payload):void
    {
        $output->writeln(json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
