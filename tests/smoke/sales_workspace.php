<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$renderer = new class {
    public object $url;
    public function __construct() { $this->url = new class { public function get(string $path=''): string { return '/'.ltrim($path,'/'); } }; }
    public function partial(string $name, array $vars=[]): void {}
    public function render(string $file, array $vars): string { extract($vars, EXTR_SKIP); ob_start(); include $file; return (string)ob_get_clean(); }
};
$workspace = [
    'kpis'=>['active_deals'=>12,'pipeline_value'=>100000,'expected_revenue'=>40000,'deals_at_risk'=>2,'followups_overdue'=>1],
    'today'=>['overdue'=>[],'must_do'=>[]],
    'at_risk'=>[['id'=>7,'customer'=>'<script>x</script>','stage_name'=>'Qualified','public_id'=>'CC-7','deal_value'=>50000,'risk_level'=>'HIGH']],
    'new_leads'=>[],
];
$html=$renderer->render($root.'/app/Interfaces/Web/View/sales/dashboard.phtml',['workspace'=>$workspace,'pageStatus'=>null]);
foreach (['Sales workspace','Pipeline value','At-risk Deals','New Leads'] as $text) if (!str_contains($html,$text)) throw new RuntimeException('Dashboard missing '.$text);
if (str_contains($html,'<script>x</script>') || !str_contains($html,'&lt;script&gt;x&lt;/script&gt;')) throw new RuntimeException('Sales dashboard did not escape data.');

$migration=(string)file_get_contents($root.'/app/migrations/20260904_000021_sales_runtime_workspace.sql');
foreach (['sales_pipelines','sales_pipeline_stages','sales_pipeline_transitions','sales_communications','cos_action_outcomes','sales_metric_snapshots'] as $table) if (!str_contains($migration,$table)) throw new RuntimeException('Migration missing '.$table);

echo "Sales workspace UI and schema contract passed.\n";
