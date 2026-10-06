<?php
declare(strict_types=1);

namespace App\Command;

use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
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

#[AsCommand(name:'cos:capital-markets:cutover',description:'Controlled tenant-scoped Capital Markets activation and rollback.')]
final class CapitalMarketsProductionCutoverCommand extends Command
{
    public function __construct(
        private readonly ModuleControlService $modules,
        private readonly ActiveModuleResolver $resolver,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this
            ->addArgument('action',InputArgument::REQUIRED,'status|enable|rollback')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Organization id.','default')
            ->addOption('actor',null,InputOption::VALUE_REQUIRED,'Positive actor id for audited lifecycle writes.','0')
            ->addOption('reason',null,InputOption::VALUE_REQUIRED,'Audit reason for enable/rollback.')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'Explicit confirmation token for lifecycle writes.');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $action=strtolower(trim((string)$input->getArgument('action')));
        $organizationId=trim((string)$input->getOption('organization'))?:'default';

        try{
            return match($action){
                'status'=>$this->status($organizationId,$output),
                'enable'=>$this->enable($input,$organizationId,$output),
                'rollback'=>$this->rollback($input,$organizationId,$output),
                default=>throw new InvalidArgumentException('Capital Markets cutover action must be status, enable or rollback.'),
            };
        }catch(InvalidArgumentException|RuntimeException $error){
            $this->write($output,['ok'=>false,'action'=>$action,'organization_id'=>$organizationId,'error'=>$error->getMessage()]);
            return Command::FAILURE;
        }catch(Throwable $error){
            $this->write($output,['ok'=>false,'action'=>$action,'organization_id'=>$organizationId,'error'=>'Capital Markets cutover failed: '.get_class($error)]);
            return Command::FAILURE;
        }
    }

    private function status(string $organizationId,OutputInterface $output):int
    {
        $module=$this->resolver->describeModule($organizationId,'capital_markets');
        $this->write($output,[
            'ok'=>(bool)($module['active']??false),
            'action'=>'status',
            'organization_id'=>$organizationId,
            'module'=>$module,
        ]);
        return ($module['active']??false)?Command::SUCCESS:Command::FAILURE;
    }

    private function enable(InputInterface $input,string $organizationId,OutputInterface $output):int
    {
        $this->confirm($input,'ENABLE_CAPITAL_MARKETS');
        $actor=$this->actor($input);
        $correlation=CorrelationId::generate()->value();
        $reason=$this->nullable($input->getOption('reason'))??'Capital Markets production cutover.';
        $module=$this->modules->enable($organizationId,'capital_markets',(string)$actor,$correlation,$reason);
        $ok=(bool)($module['active']??false)&&(bool)($module['current']??false);

        $this->write($output,[
            'ok'=>$ok,
            'action'=>'enable',
            'organization_id'=>$organizationId,
            'correlation_id'=>$correlation,
            'module'=>$module,
        ]);
        return $ok?Command::SUCCESS:Command::FAILURE;
    }

    private function rollback(InputInterface $input,string $organizationId,OutputInterface $output):int
    {
        $this->confirm($input,'DISABLE_CAPITAL_MARKETS');
        $actor=$this->actor($input);
        $correlation=CorrelationId::generate()->value();
        $reason=$this->nullable($input->getOption('reason'))??'Capital Markets production rollback.';
        $module=$this->modules->disable($organizationId,'capital_markets',(string)$actor,$correlation,$reason);
        $this->write($output,[
            'ok'=>!($module['active']??true),
            'action'=>'rollback',
            'organization_id'=>$organizationId,
            'correlation_id'=>$correlation,
            'module'=>$module,
            'schema_rollback'=>false,
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
