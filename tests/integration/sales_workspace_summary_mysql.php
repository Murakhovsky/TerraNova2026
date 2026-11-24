<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Infrastructure\Capability\SalesWorkspaceSummaryAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWorkspaceScope;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceOperationalReadModel;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\{ActiveModuleResolver,CanonicalCapabilityCatalog,DomainModuleRegistry,ModuleCatalog,ModuleDefinition};
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Shared\Domain\{OrganizationId,UserId};
use Kernel\Tenant\Model\{Permission,TenantContext,TenantPermissions};

$root=dirname(__DIR__,2);
require getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php';
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $fn):void{try{$fn();}catch(DomainException){return;}throw new RuntimeException('Unauthorized scope accepted.');};
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pipeline=$db->query('SELECT p.organization_id,p.id,p.initial_stage_id FROM sales_pipelines p JOIN sales_pipeline_stages s ON s.id=p.initial_stage_id AND s.organization_id=p.organization_id AND s.pipeline_id=p.id WHERE p.status="ACTIVE" AND s.status="ACTIVE" ORDER BY p.organization_id LIMIT 1')->fetch();
$check(is_array($pipeline),'Sales pipeline fixture missing.');$org=$pipeline['organization_id'];$pid=$pipeline['id'];$stage=$pipeline['initial_stage_id'];$token=bin2hex(random_bytes(6));
$db->beginTransaction();
try{
    $makeUser=static function(string $suffix)use($db,$org,$token):int{
        $db->prepare('INSERT INTO tn_users(organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?, "manager","active")')->execute([$org,$token.'-'.$suffix.'@example.test',password_hash($token,PASSWORD_DEFAULT),'Summary fixture']);
        $id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO cos_organization_memberships(organization_id,user_id,role,status) VALUES (?,?,"manager","ACTIVE")')->execute([$org,$id]);return $id;
    };
    $owner=$makeUser('owner');$other=$makeUser('other');
    $db->prepare('INSERT INTO tn_people(organization_id,public_id,full_name) VALUES (?,?,?)')->execute([$org,'P-'.$token,'Private fixture person']);$person=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO tn_client_cases(organization_id,public_id,person_id,type,title,status,stage,pipeline_id,stage_id,probability,priority,assigned_user_id,deal_value,currency,budget_max) VALUES (?,?,?,"buy","Private title",?,"new",?,?,50,"normal",?,?,?,99999)');
    $insert=static function(int $i,int $who,?string $money,string $currency,string $status='active')use($q,$org,$token,$person,$pid,$stage):void{$q->execute([$org,'D-'.$token.'-'.$i,$person,$status,$pid,$stage,$who,$money,$currency]);};
    for($i=0;$i<251;$i++)$insert($i,$owner,'10.01','USD');
    $insert(251,$owner,'20.02','EUR');$insert(252,$owner,null,'UAH');$insert(253,$owner,'100.00','USD','closed');$insert(254,$other,'333.00','UAH');
    $read=new MysqlSalesWorkspaceReadModel($db);$scope=new MysqlSalesWorkspaceScope($db,new MysqlSalesAccessControl($db));
    $modules=new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php'),ModuleDefinition::fromArray(require $root.'/app/Domains/ProductManagement/module.php')]);$catalog=new CanonicalCapabilityCatalog($modules);
    $active=new ActiveModuleResolver($modules,new class implements ModuleStateRepositoryInterface{
        public bool $enabled=true;
        public function enabledOverride(string $organizationId,string $moduleId):?bool{return $this->enabled;}
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->enabled=$enabled;}
    });
    $scoped=new \Domains\Sales\Application\Service\SalesWorkspaceScopedReadService($scope,$read);
    $analytics=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesCommercialAnalyticsReadModel($db);
    $analyticsService=new \Domains\Sales\Application\Service\SalesCommercialAnalyticsService($scope,$analytics);
    $entityScope=new \Domains\Sales\Application\Service\SalesEntityScopeService($scope,$read);
    $authority=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationAuthority($db,$scope,new MysqlSalesAccessControl($db));
    $guard=new \Domains\Sales\Application\Service\SalesMutationGuard(new \Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager($db),$authority);
    $policy=new \App\Application\Sales\SalesDirectInvocationPolicy($entityScope,new MysqlSalesAccessControl($db),new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOfferRepository($db),$guard);
    $reports=new \Domains\Sales\Application\Service\SalesReportService($analyticsService);
    $teamSummary=new \Domains\Sales\Application\Service\SalesTeamSummaryService(
        new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamScope($db,$scope,new MysqlSalesAccessControl($db)),$read);
    $orderRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderRepository($db);
    $references=new class implements \Domains\Sales\Application\Contract\SalesOfferReferencePort {
        public function resolve(string $org,array $line):?array{
            return ($line['product_id']??null)===str_repeat('a',32)&&in_array($line['variant_id']??null,[str_repeat('b',32),str_repeat('c',32)],true)&&($line['sku']??null)==='LAMP-1'
                ?['product_id'=>str_repeat('a',32),'variant_id'=>$line['variant_id'],'sku'=>'LAMP-1','name'=>'Lamp']:null;
        }
    };
    $receipts=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationReceiptRepository($db);
    $orderService=new \Domains\Sales\Application\Service\SalesOrderService($orderRepo,$receipts,$guard,$entityScope,$references);
    // Actual Shopify source + HMAC inbox + PDO, with a provider-only mock transport.
    $shop='order-'.$token.'.myshopify.com';
    putenv('SHOPIFY_SHOP_DOMAIN='.$shop);putenv('SHOPIFY_CLIENT_ID=order-fixture');putenv('SHOPIFY_CLIENT_SECRET=order-fixture-secret');
    $shopStore=new \Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore($db);
    $conn=$shopStore->provision($org,$shop,'shopify_env_primary');
    $shopStore->map($org,$conn,'product','gid://shopify/Product/41',str_repeat('a',32));
    $shopStore->map($org,$conn,'variant','gid://shopify/ProductVariant/42',str_repeat('b',32));
    $http=new class implements \Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface {
        public int $reads=0;public string $updated='2026-11-24T10:00:00Z';public string $financial='PAID';
        public string $current='120.00';public bool $edited=false;public bool $fail=false;public bool $unmapped=false;public bool $truncated=false;
        public function post(string $url,array $headers,string $body):array
        {
            if(str_ends_with($url,'/oauth/access_token'))return ['status'=>200,'body'=>'{"access_token":"fake-order-token"}'];
            if(!str_ends_with($url,'/admin/api/2026-10/graphql.json')||($headers['X-Shopify-Access-Token']??null)!=='fake-order-token')throw new RuntimeException('Wrong provider boundary.');
            $request=json_decode($body,true,512,JSON_THROW_ON_ERROR);
            if(!str_starts_with($request['query'],'query ')||str_contains($request['query'],'mutation'))throw new RuntimeException('Unexpected Shopify write.');
            ++$this->reads;
            if($this->fail)return ['status'=>403,'body'=>'{}'];
            $money=static fn(string $amount)=>['shopMoney'=>['amount'=>$amount,'currencyCode'=>'EUR']];
            $data=['currentAppInstallation'=>['accessScopes'=>[['handle'=>'read_orders']]]];
            if(str_contains($request['query'],'COSCommerceOrderPage')){
                $data['orders']=['nodes'=>[['id'=>'gid://shopify/Order/12345']],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]];
            }else{
                $data['order']=['id'=>$request['variables']['id'],'createdAt'=>'2026-11-24T09:00:00Z','updatedAt'=>$this->updated,'cancelledAt'=>null,'test'=>false,
                    'edited'=>$this->edited,'taxesIncluded'=>true,'displayFinancialStatus'=>$this->financial,'displayFulfillmentStatus'=>'UNFULFILLED',
                    'originalTotalPriceSet'=>$money('120.00'),'totalPriceSet'=>$money('120.00'),'currentTotalPriceSet'=>$money($this->current),
                    'totalDiscountsSet'=>$money('0.00'),'totalTaxSet'=>$money('20.00'),'totalShippingPriceSet'=>$money('0.00'),
                    'lineItems'=>['nodes'=>[['id'=>'gid://shopify/LineItem/77','quantity'=>1,'sku'=>'LAMP-1','product'=>['id'=>'gid://shopify/Product/41'],
                        'variant'=>['id'=>'gid://shopify/ProductVariant/'.($this->unmapped?'99':'42')],'originalUnitPriceSet'=>$money('120.00')]],
                        'pageInfo'=>['hasNextPage'=>$this->truncated,'endCursor'=>null]]];
            }
            return ['status'=>200,'body'=>json_encode(['data'=>$data],JSON_THROW_ON_ERROR)];
        }
    };
    $productRefs=new class implements \Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort {
        public function quoteReference(string $org,string $productId,string $variantId):?array{
            return $productId===str_repeat('a',32)&&in_array($variantId,[str_repeat('b',32),str_repeat('c',32)],true)?['product_id'=>$productId,'variant_id'=>$variantId,'sku'=>'LAMP-1','name'=>'Lamp']:null;
        }
    };
    $source=new \Domains\ProductManagement\Infrastructure\Shopify\ShopifyCommerceOrderSource($shopStore,
        new \Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient($http),
        new \Domains\ProductManagement\Infrastructure\Shopify\ShopifyClientCredentials($http),$active,$productRefs);
    $reconcile=new \Domains\Sales\Application\Service\SalesOrderReconciliationService($source,
        new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderSourceRepository($db),$orderRepo,$receipts,$guard,$orderService,$references);
    $gateway=new CanonicalCapabilityGateway($catalog,$active,new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active),[
        new SalesWorkspaceSummaryAdapter($scope,$read),
        new \App\Infrastructure\Capability\SalesWorkspaceLeadsListAdapter($scoped),
        new \App\Infrastructure\Capability\SalesWorkspaceDealsListAdapter($scoped),
        new \App\Infrastructure\Capability\SalesWorkspaceTimelineAdapter($scoped),
        new \App\Infrastructure\Capability\SalesAnalyticsOutcomesAdapter($analyticsService),
        new \App\Infrastructure\Capability\SalesAnalyticsForecastAdapter($analyticsService),
        new \App\Infrastructure\Capability\SalesAnalyticsConversionAdapter($analyticsService),
        new \App\Infrastructure\Capability\SalesAnalyticsStagesAdapter($analyticsService),
        new \App\Infrastructure\Capability\SalesReportDataAdapter($reports),
        new \App\Infrastructure\Capability\SalesWorkspaceTeamSummaryAdapter($teamSummary),
        new \App\Infrastructure\Capability\SalesOrderCreateAdapter($orderService),
        new \App\Infrastructure\Capability\SalesOrderReadAdapter($orderService),
        new \App\Infrastructure\Capability\SalesOrderSourceReconcileAdapter($reconcile),
        new \App\Infrastructure\Capability\SalesOrderSourcePageAdapter($reconcile),
        new \App\Infrastructure\Capability\SalesOrderSourcePendingAdapter($reconcile),
        new \App\Infrastructure\Capability\SalesDealStateReadAdapter(new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository($db)),
        new \App\Infrastructure\Capability\SalesInboundRequestReadAdapter($read)
    ],[$policy]);
    $actor=static fn(string $tenant,int $user)=>new TenantContext(UserId::fromString((string)$user),OrganizationId::fromString($tenant),OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $manager=$actor($org,$owner);
    $invoke=static fn(array $in)=>$gateway->invoke($manager,'sales.workspace.summary.read',$in,'trusted_read','','summary-test');
    $summary=$invoke(['pipeline_id'=>$pid]);
    $check($summary['scope']['owner_id']===$owner&&$summary['counts']['active_deals']===253&&$summary['counts']['monetary_deals']===252,'Open scope/coverage mismatch.');
    $by=array_column($summary['money_by_currency'],null,'currency');
    $check(count($by)===2&&$by['USD']['deal_count']===251&&$by['USD']['pipeline_value']==='2512.51'&&$by['USD']['weighted_value']==='1256.26'&&$by['EUR']['pipeline_value']==='20.02','Currency/decimal or page truncation defect.');
    $check(!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'Private'),'PII exposed by aggregate contract.');
    $check($summary['stages'][0]['deal_count']===253,'Stage population truncated.');
    $reject(fn()=>$invoke(['scope'=>'tenant']));
    $reject(fn()=>$invoke(['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$invoke(['scope'=>'self','owner_id'=>$other]));
    $reject(fn()=>$gateway->invoke($actor('foreign-'.$token,$owner),'sales.workspace.summary.read',[],'trusted_read','','foreign'));
    $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,"sales.director.view","ACTIVE",?,NOW(6),NOW(6))')->execute([$org,$owner,(string)$owner]);
    $check($invoke(['scope'=>'owner','owner_id'=>$other])['money_by_currency'][0]['pipeline_value']==='333.00','Director owner filter failed.');
    $all=$invoke(['scope'=>'tenant','pipeline_id'=>$pid]);$check($all['scope']['owner_id']===null&&$all['counts']['active_deals']>=254,'Director tenant scope failed.');
    $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.director.view"')->execute([$org,$owner]);
    $reject(fn()=>$invoke(['scope'=>'tenant']));
    $teams=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamAdministration($db);
    $team=$teams->createTeam($org,['name'=>'Summary team','code'=>'S5_'.strtoupper($token)],(string)$owner);
    $teamId=$team['id'];
    $teams->setMember($org,$teamId,$owner,['role'=>'LEAD','status'=>'ACTIVE','assignment_enabled'=>true],(string)$owner);
    $teams->setMember($org,$teamId,$other,['role'=>'MEMBER','status'=>'ACTIVE','assignment_enabled'=>true],(string)$owner);
    $check($invoke(['scope'=>'owner','owner_id'=>$other])['counts']['active_deals']===1,'Active team lead scope failed.');
    $teamCall=static fn($who)=>$gateway->invoke($who,'sales.workspace.team.summary.read',['team_id'=>$teamId,'pipeline_id'=>$pid],'trusted_read','','team-summary');
    $teamData=$teamCall($manager);
    $check($teamData['scope']['member_count']===2&&$teamData['counts']['active_deals']===254,'Team population incorrect.');
    $teamMoney=array_column($teamData['money_by_currency'],null,'currency');
    $check($teamMoney['USD']['pipeline_value']==='2512.51'&&$teamMoney['UAH']['pipeline_value']==='333.00','Team exact currency totals incorrect.');
    $check($read->summaryForOwners($org,[],$pid)['counts']['active_deals']===0,'Empty team widened to tenant.');
    $reject(fn()=>$teamCall($actor($org,$other)));
    $db->prepare('UPDATE sales_teams SET status="DISABLED" WHERE organization_id=? AND id=?')->execute([$org,$teamId]);
    $reject(fn()=>$invoke(['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$teamCall($manager));
    // P3: forecast is a complete current projection, not a historical snapshot.
    $analytics=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesCommercialAnalyticsReadModel($db);
    $db->prepare('UPDATE tn_client_cases SET expected_close_at=? WHERE organization_id=? AND assigned_user_id=? AND currency="USD" AND status="active"')->execute(['2026-12-10',$org,$owner]);
    $db->prepare('UPDATE tn_client_cases SET expected_close_at=? WHERE organization_id=? AND assigned_user_id=? AND currency="EUR"')->execute(['2026-12-01',$org,$owner]);
    $forecast=$analytics->forecast($org,$owner,$pid,'2026-12-01','2027-01-01');
    $buckets=array_column($forecast['buckets'],null,'bucket');
    $money=array_column($buckets['in_period']['money_by_currency'],null,'currency');
    $check($buckets['in_period']['deal_count']===252&&$money['USD']['pipeline_value']==='2512.51'&&$money['USD']['weighted_value']==='1256.26'&&$money['EUR']['pipeline_value']==='20.02','Forecast amounts or scoped population invalid.');
    $check($buckets['unscheduled']['deal_count']===1&&$buckets['unscheduled']['unknown_value_count']===1,'Forecast missing-value coverage invalid.');
    $db->prepare('UPDATE tn_client_cases SET expected_close_at=? WHERE organization_id=? AND assigned_user_id=? AND currency="EUR"')->execute(['2027-01-01',$org,$owner]);
    $next=array_column($analytics->forecast($org,$owner,$pid,'2026-12-01','2027-01-01')['buckets'],null,'bucket');
    $check($next['after_period']['deal_count']===1&&$next['in_period']['deal_count']===251,'Forecast exclusive end boundary invalid.');
    $otherForecast=array_column($analytics->forecast($org,$other,$pid,'2026-12-01','2027-01-01')['buckets'],null,'bucket');
    $check($otherForecast['unscheduled']['deal_count']===1,'Forecast other owner scope invalid.');
    $reject(fn()=>$analytics->forecast($org,$owner,$pid,'2026-02-30','2026-03-02'));
    $reject(fn()=>$analytics->forecast($org,$owner,$pid,'2026-12-01','2026-12-01'));
    $analyticsService=new \Domains\Sales\Application\Service\SalesCommercialAnalyticsService($scope,$analytics);
    $analyticsInput=['pipeline_id'=>$pid,'from'=>'2026-12-01','to'=>'2027-01-01'];
    $check($analyticsService->read($org,$owner,'forecast',$analyticsInput)===$analytics->forecast($org,$owner,$pid,'2026-12-01','2027-01-01'),'Scoped analytics application parity failed.');
    $reject(fn()=>$analyticsService->read($org,$owner,'forecast',$analyticsInput+['scope'=>'tenant']));
    $reject(fn()=>$analyticsService->read($org,$owner,'outcomes',$analyticsInput+['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$analyticsService->read('foreign-'.$token,$owner,'forecast',$analyticsInput));
    $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?, "sales.director.view","ACTIVE",?,NOW(6),NOW(6))')->execute([$org,$owner,(string)$owner]);
    $check($analyticsService->read($org,$owner,'forecast',$analyticsInput+['scope'=>'tenant'])['owner_id']===null,'Director analytics scope failed.');
    $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.director.view"')->execute([$org,$owner]);
    $reject(fn()=>$analyticsService->read($org,$owner,'forecast',$analyticsInput+['scope'=>'tenant']));
    $dash=$read->dashboard($org,$owner);$check($dash['kpis']['pipeline_value']===null&&$dash['kpis']['money_by_currency']===$summary['money_by_currency']&&$dash['metrics']===[],'Dashboard parity/tenant leakage.');
    $operational=new MysqlSalesWorkspaceOperationalReadModel($db,$read);
    $pipelines=$operational->pipelines($org);$p=array_values(array_filter($pipelines,fn($x)=>$x['id']===$pid))[0];
    $s=array_values(array_filter($p['stages'],fn($x)=>$x['id']===$stage))[0];$check($s['deal_count']>=254&&count($s['money_by_currency'])>=3&&$s['pipeline_value']===null,'UI pipeline aggregate incorrect.');
    $page=static fn(string $cap,array $in)=>$gateway->invoke($manager,$cap,$in,'trusted_read','','scope-list');
    $first=$page('sales.workspace.deals.list',['status'=>'active','limit'=>100,'pipeline_id'=>$pid]);
    $second=$page('sales.workspace.deals.list',['status'=>'active','limit'=>100,'offset'=>100,'pipeline_id'=>$pid]);
    $third=$page('sales.workspace.deals.list',['status'=>'active','limit'=>100,'offset'=>200,'pipeline_id'=>$pid]);
    $check(count($first['items'])===100&&$first['has_more']&&count($second['items'])===100&&$second['has_more']&&count($third['items'])===53&&!$third['has_more'],'Scoped list pagination incorrect.');
    $check(count(array_unique(array_column([...$first['items'],...$second['items'],...$third['items']],'id')))===253,'Stable ordering lost or duplicated rows.');
    $check(!str_contains(json_encode($first,JSON_THROW_ON_ERROR),'Private'),'Deal list leaked PII.');
    $reject(fn()=>$page('sales.workspace.deals.list',['scope'=>'owner','owner_id'=>$other]));
    $ownedId=$first['items'][0]['id'];
    $q=$db->prepare('SELECT id FROM tn_client_cases WHERE organization_id=? AND assigned_user_id=? LIMIT 1');$q->execute([$org,$other]);$otherId=(int)$q->fetchColumn();
    $db->prepare('INSERT INTO tn_client_case_activities(organization_id,client_case_id,activity_type,title,body,completed_at) VALUES (?,?,"note","Private note","Private message",NOW())')->execute([$org,$ownedId]);
    $timeline=$page('sales.workspace.deal.timeline.read',['deal_id'=>$ownedId]);
    $check($timeline['found']&&count($timeline['items'])===1&&$timeline['items'][0]['status']==='completed'&&!str_contains(json_encode($timeline,JSON_THROW_ON_ERROR),'Private'),'Timeline redaction/state incorrect.');
    $check(!$page('sales.workspace.deal.timeline.read',['deal_id'=>$otherId])['found'],'Foreign owner history leaked.');
    $leadRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository($db);
    foreach([$owner,$other] as $i=>$u){
        $lead=$leadRepo->create($org,['person_id'=>null,'client_case_id'=>null,'property_id'=>null,'full_name'=>'Private Lead','phone'=>null,'email'=>'lead-'.$i.'-'.$token.'@example.test','role'=>'buyer','deal_type'=>'consultation','message'=>'Private body','source_page'=>'/ci']);
        $db->prepare('UPDATE tn_leads SET assigned_user_id=? WHERE organization_id=? AND id=?')->execute([$u,$org,$lead]);
    }
    $leads=$page('sales.workspace.leads.list',['status'=>'new']);
    $check(count($leads['items'])===1&&$leads['items'][0]['owner_id']===$owner&&!str_contains(json_encode($leads,JSON_THROW_ON_ERROR),'Private'),'Scoped lead list mismatch.');
    $analyticsCall=static fn(string $view,array $input)=>$gateway->invoke($manager,'sales.analytics.'.$view.'.read',$input,'trusted_read','','analytics-test');
    $range=['from'=>'2026-12-01','to'=>'2027-01-01'];
    $check($analyticsCall('forecast',$range+['pipeline_id'=>$pid])===$analytics->forecast($org,$owner,$pid,$range['from'],$range['to']),'Forecast capability parity failed.');
    $check($analyticsCall('outcomes',$range)['win_rate_percent']===null,'Empty outcome period returned fabricated rate.');
    $reject(fn()=>$analyticsCall('forecast',$range+['scope'=>'tenant']));
    $reject(fn()=>$analyticsCall('stages',$range+['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$analyticsCall('conversion',$range+['pipeline_id'=>$pid]));
    $reject(fn()=>$analyticsCall('outcomes',['from'=>'2026-02-30','to'=>'2026-03-02']));
    $leadId=$leads['items'][0]['id'];
    $db->prepare('UPDATE tn_leads SET created_at=?,client_case_id=? WHERE organization_id=? AND id=?')->execute(['2026-12-05',$ownedId,$org,$leadId]);
    $cohort=$analyticsCall('conversion',$range);
    $check($cohort['lead_count']===1&&$cohort['linked_count']===1&&$cohort['link_without_event_count']===1&&$cohort['event_confirmed_count']===0&&$cohort['conversion_percent']==='100.00','Lead cohort/link evidence mismatch.');
    // Native projection fixtures: historical owner at entry and COMPLETE duration only.
    $db->prepare("INSERT INTO sales_deal_owner_history(id,organization_id,deal_id,owner_user_id,assigned_at,unassigned_at,history_quality) VALUES (?,?,?,?,?,?,'COMPLETE')")->execute(['fixture-owner-'.$token,$org,(string)$ownedId,$owner,'2026-12-02','2026-12-20']);
    $stageInsert=$db->prepare('INSERT INTO sales_deal_stage_history(id,organization_id,deal_id,pipeline_id,from_stage_id,to_stage_id,to_stage_code,entered_at,left_at,duration_seconds,history_quality) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stageInsert->execute(['fixture-stage-a-'.$token,$org,(string)$ownedId,$pid,null,$stage,'FIXTURE','2026-12-03','2026-12-04',86400,'COMPLETE']);
    $stageInsert->execute(['fixture-stage-b-'.$token,$org,(string)$ownedId,$pid,null,$stage,'FIXTURE','2026-12-05','2026-12-06',86400,'PARTIAL']);
    $history=$analyticsCall('stages',$range+['pipeline_id'=>$pid]);
    $check(count($history['items'])===1&&$history['items'][0]['entry_count']===2&&$history['items'][0]['distinct_deal_count']===1&&$history['items'][0]['complete_count']===1&&$history['items'][0]['partial_count']===1&&$history['items'][0]['duration_count']===1&&$history['items'][0]['average_duration_seconds']==='86400.00','Stage history quality/duration mismatch.');
    $check(!str_contains(json_encode([$cohort,$history],JSON_THROW_ON_ERROR),'Private'),'Analytics leaked PII.');
    // P4: actual direct Gateway authorization, not only declared scope metadata.
    $check($page('sales.deal.state.read',['deal_id'=>$ownedId])['found'],'Own deal denied.');
    $reject(fn()=>$page('sales.deal.state.read',['deal_id'=>$otherId]));
    $check($page('sales.inbound.request.read',['lead_id'=>$leadId])['found'],'Own lead denied.');
    $reject(fn()=>$page('sales.inbound.request.read',['lead_id'=>$lead]));
    $db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$otherId,$org,$leadId]);
    $reject(fn()=>$page('sales.inbound.request.read',['lead_id'=>$leadId]));
    $db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$ownedId,$org,$leadId]);
    // Director read capability is live and revocable.
    $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?, "sales.director.view","ACTIVE",?,NOW(6),NOW(6))')->execute([$org,$owner,(string)$owner]);
    $check($page('sales.deal.state.read',['deal_id'=>$otherId])['found'],'Director deal read denied.');
    $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.director.view"')->execute([$org,$owner]);
    $reject(fn()=>$page('sales.deal.state.read',['deal_id'=>$otherId]));
    // P4: HTTP boundary checks execute before legacy controller methods.
    $tenantProvider=new class($manager) implements \Kernel\Tenant\Contract\TenantContextProviderInterface {
        public function __construct(private \Kernel\Tenant\Model\TenantContext $tenant){}
        public function current():?\Kernel\Tenant\Model\TenantContext{return $this->tenant;}
    };
    $subscriber=new \App\Security\SalesWorkspaceAccessSubscriber($tenantProvider,$active,$entityScope,new MysqlSalesAccessControl($db));
    $kernel=new class implements \Symfony\Component\HttpKernel\HttpKernelInterface {
        public function handle(\Symfony\Component\HttpFoundation\Request $request,int $type=self::MAIN_REQUEST,bool $catch=true):\Symfony\Component\HttpFoundation\Response {
            return new \Symfony\Component\HttpFoundation\Response();
        }
    };
    $readController=(new ReflectionClass(\App\Http\Api\V1\Controller\SalesReadController::class))->newInstanceWithoutConstructor();
    $request=\Symfony\Component\HttpFoundation\Request::create('/api/v1/sales/opportunities');
    $subscriber->authorize(new \Symfony\Component\HttpKernel\Event\ControllerEvent($kernel,[$readController,'opportunities'],$request,\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST));
    $check((int)$request->query->get('owner_id')===$owner,'HTTP list missing authorized owner predicate.');
    $httpDenied=static function(callable $call,int $status):void {
        try{$call();}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){
            if($e->getStatusCode()===$status)return;
            throw new RuntimeException('Unexpected authorization status: '.$e->getStatusCode());
        }
        throw new RuntimeException('HTTP authorization bypass.');
    };
    $request=\Symfony\Component\HttpFoundation\Request::create('/api/v1/sales/opportunities/'.$otherId);
    $request->attributes->set('id',(string)$otherId);
    $httpDenied(fn()=>$subscriber->authorize(new \Symfony\Component\HttpKernel\Event\ControllerEvent($kernel,[$readController,'opportunity'],$request,\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST)),404);
    $request=\Symfony\Component\HttpFoundation\Request::create('/api/v1/sales/opportunities?scope=tenant');
    $httpDenied(fn()=>$subscriber->authorize(new \Symfony\Component\HttpKernel\Event\ControllerEvent($kernel,[$readController,'opportunities'],$request,\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST)),403);
    $directorController=(new ReflectionClass(\App\Web\Sales\SalesDirectorController::class))->newInstanceWithoutConstructor();
    $request=\Symfony\Component\HttpFoundation\Request::create('/sales/director');
    $httpDenied(fn()=>$subscriber->authorize(new \Symfony\Component\HttpKernel\Event\ControllerEvent($kernel,[$directorController,'index'],$request,\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST)),403);
    // Current-stage aging uses the matching native history entry, not case creation time.
    $operational=new MysqlSalesWorkspaceOperationalReadModel($db,$read);
    $check($operational->deal($org,$ownedId)['days_in_stage']===null,'Unknown stage age was fabricated.');
    $entered=(string)$db->query('SELECT DATE_SUB(NOW(),INTERVAL 5 DAY)')->fetchColumn();
    $stageInsert->execute(['fixture-open-'.$token,$org,(string)$ownedId,$pid,null,$stage,'FIXTURE',$entered,null,null,'COMPLETE']);
    $age=$operational->deal($org,$ownedId);
    $check($age['days_in_stage']!==null&&$age['days_in_stage']>=4&&!$age['stage_age_estimated'],'Complete current stage age missing.');
    $cards=$operational->deals($org,['q'=>$age['public_id'],'owner_id'=>$owner,'limit'=>10]);
    $card=array_values(array_filter($cards,static fn(array $row):bool=>(int)$row['id']===$ownedId));
    $check(count($card)===1&&$card[0]['days_in_stage']!==null&&(int)$card[0]['days_in_stage']>=4,'Pipeline aging projection diverged.');
    $db->prepare('DELETE FROM sales_deal_stage_history WHERE organization_id=? AND id=?')->execute([$org,'fixture-open-'.$token]);
    // Director display consumes exact canonical facts, including values beyond float precision.
    $director=(new \App\Web\Sales\SalesDirectorPresenter())->present(['canonical'=>[
        'summary'=>['money_by_currency'=>[['currency'=>'USD','pipeline_value'=>'9007199254740993.01','weighted_value'=>'1256.26']]],
        'outcomes'=>['win_rate_percent'=>'50.00','booked_by_currency'=>[['currency'=>'USD','booked_value'=>'999.99']]],
        'forecast'=>['buckets'=>[['bucket'=>'in_period','money_by_currency'=>[['currency'=>'USD','pipeline_value'=>'2512.51','weighted_value'=>'1256.26']]]]],
        'conversion'=>$cohort,'stages'=>$history,
    ]],30,30,$pid);
    $moneyRow=$director->executiveGrid->page->rows[0];
    $check($moneyRow['pipeline']==='9 007 199 254 740 993.01 USD'&&$moneyRow['booked']==='999.99 USD'&&$moneyRow['forecast']==='2 512.51 USD','Director exact decimal presentation failed.');
    // Compound projections must scope the population before LIMIT and aggregation.
    $caseFactory=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlClientCaseReadModelFactory(
        $db,new \Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort($db));
    $ownCases=$caseFactory->forOwner($org,$owner);
    $check($ownCases->stats()['all']===254,'Client Case stats widened or truncated.');
    $check($ownCases->case($otherId)===null&&$ownCases->case($ownedId)!==null,'Client Case detail scope failed.');
    $check(!in_array($otherId,array_map('intval',array_column($ownCases->openCaseOptions(),'id')),true),'Foreign link target leaked.');
    $db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$otherId,$org,$leadId]);
    $check(!in_array($leadId,array_map('intval',array_column($ownCases->inboundInbox([]),'id')),true),'Cross-owner linked lead exposed.');
    $inboxStats=$ownCases->inboundInboxStats();
    $check(array_sum($inboxStats['status'])===$inboxStats['total']&&array_sum($inboxStats['intent'])===$inboxStats['total'],'Inbox statistics have different populations.');
    $db->prepare('UPDATE tn_leads SET client_case_id=? WHERE organization_id=? AND id=?')->execute([$ownedId,$org,$leadId]);
    $db->prepare('UPDATE tn_client_cases SET next_contact_at=NOW()-INTERVAL 2 DAY WHERE organization_id=? AND id=?')->execute([$org,$otherId]);
    $db->prepare('UPDATE tn_client_cases SET next_contact_at=NOW()-INTERVAL 1 DAY WHERE organization_id=? AND id=?')->execute([$org,$ownedId]);
    $teamScope=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamScope($db,$scope,new MysqlSalesAccessControl($db));
    $check($teamScope->ledMembers($org,$owner)===[$owner],'Disabled team still authorized Today.');
    $db->prepare('UPDATE sales_teams SET status="ACTIVE" WHERE organization_id=? AND id=?')->execute([$org,$teamId]);
    $members=$teamScope->ledMembers($org,$owner);
    $check(in_array($other,$members,true),'Active team member missing.');
    $selfToday=$read->todayForOwners($org,[$owner]);$teamToday=$read->todayForOwners($org,$members);
    $check(!in_array($otherId,array_map('intval',array_column($selfToday['overdue'],'id')),true),'Foreign Today item leaked.');
    $check(in_array($otherId,array_map('intval',array_column($teamToday['overdue'],'id')),true),'Team Today missing authorized item.');
    foreach($read->todayForOwners($org,[]) as $section)$check($section===[],'Empty Today population widened.');
    $db->prepare('UPDATE sales_teams SET status="DISABLED" WHERE organization_id=? AND id=?')->execute([$org,$teamId]);
    // P4 atomic legacy facade: real native factory, same PDO and transaction.
    $nativeFactory=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWriteServiceFactory(
        $db,new \Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort($db),
        new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority($db));
    $guardedFactory=new \Domains\Sales\Application\Service\GuardedSalesWriteServiceFactory($nativeFactory,$guard,$authority);
    $writes=$guardedFactory->forOrganization($org);
    $grant=$db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,?,"ACTIVE",?,NOW(6),NOW(6))');
    $grant->execute([$org,$owner,'sales.workspace.use',(string)$owner]);
    // S5A O1/O2: manual channel, exact snapshots, native receipts and no financial assertion.
    $orderInput=['currency'=>'EUR','lines'=>[['sku'=>'LAMP-1','title'=>'Lamp','quantity'=>3,'unit_price'=>'301.01']],
        'discount'=>'3.03','tax'=>'10.00','shipping'=>'20.00','total'=>'930.00','attribution'=>['utm_campaign'=>'test-campaign']];
    $createOrder=static fn(array $input,string $key)=>$gateway->invoke($manager,'sales.order.create',$input,'human_api',$key,'order-test');
    $createdOrder=$createOrder($orderInput,'order-'.$token);
    $orderId=$createdOrder['order']['order_id'];
    $check(!$createdOrder['replayed']&&$createdOrder['order']['subtotal']==='903.03'&&$createdOrder['order']['total']==='930.00'&&$createdOrder['order']['status']==='placed'&&$createdOrder['order']['deal_id']===null,'Native order terms failed.');
    $check($createOrder($orderInput,'order-'.$token)['order']['order_id']===$orderId&&$createOrder($orderInput,'order-'.$token)['replayed'],'Order receipt replay duplicated order.');
    $reject(fn()=>$createOrder([...$orderInput,'total'=>'931.00'],'bad-total-'.$token));
    $reject(fn()=>$createOrder([...$orderInput,'attribution'=>['utm_campaign'=>'changed']],'order-'.$token));
    $reject(fn()=>$createOrder([...$orderInput,'owner_id'=>$other],'foreign-owner-'.$token));
    $reject(fn()=>$createOrder([...$orderInput,'deal_id'=>$otherId],'foreign-deal-'.$token));
    $reject(fn()=>$createOrder([...$orderInput,'lines'=>[[...$orderInput['lines'][0],'product_id'=>'missing','variant_id'=>'missing']]],'missing-product-'.$token));
    $readOrder=static fn($who)=>$gateway->invoke($who,'sales.order.read',['order_id'=>$orderId],'trusted_read','','order-test');
    $check($readOrder($manager)['order']===$createdOrder['order'],'Order readback differs.');
    $reject(fn()=>$readOrder($actor($org,$other)));
    $reject(fn()=>$readOrder($actor('foreign-'.$token,$owner)));
    $reject(fn()=>$gateway->invoke($manager,'sales.order.create',$orderInput,'trusted_read','no-write-'.$token,'order-test'));
    $q=$db->prepare('SELECT payload FROM sales_order_evidence WHERE organization_id=? AND order_id=?');$q->execute([$org,$orderId]);$events=$q->fetchAll(PDO::FETCH_COLUMN);
    $fact=json_decode($events[0],true,512,JSON_THROW_ON_ERROR);
    $check(json_decode(json_encode($orderRepo->placement($org,$orderId),JSON_THROW_ON_ERROR),true)===$fact&&$orderRepo->placement('foreign-'.$token,$orderId)===null,'Cross-domain placement evidence scope failed.');
    $check(count($events)===1&&$fact['event_type']==='sales.commerce.order.placed'&&$fact['realized_revenue']===null&&$fact['financial_evidence_reference']===null&&$fact['attribution_status']==='unresolved'&&$fact['attribution_observations']['utm_campaign']==='test-campaign','Placement fabricated attribution or financial fact.');
    $sourceInput=['channel'=>'shopify','connection_id'=>$conn,'external_order_id'=>'gid://shopify/Order/12345'];
    $runSource=static fn(array $input,string $key)=>$gateway->invoke($manager,'sales.order.source.reconcile',$input,'human_api',$key,'source-test');
    $reject(fn()=>$runSource($sourceInput,'denied-'.$token));
    $check($http->reads===0,'Unauthorized source operator contacted provider.');
    $grant->execute([$org,$owner,'sales.admin.integrations.manage',(string)$owner]);
    $readSource=static fn(string $cap,array $input)=>$gateway->invoke($manager,$cap,$input,'trusted_read','','source-test');
    $page=$readSource('sales.order.source.page.read',['channel'=>'shopify','connection_id'=>$conn]);
    $check($page===['order_ids'=>['gid://shopify/Order/12345'],'next_cursor'=>null],'Shopify backfill page mismatch.');
    $delivery='delivery-'.$token;$raw='{"id":12345,"email":"must-not-store@example.test"}';
    $signature=base64_encode(hash_hmac('sha256',$raw,'order-fixture-secret',true));
    $gid=\Domains\ProductManagement\Infrastructure\Shopify\ShopifyOrderWebhookEvidence::extractVerified($raw,$signature,'order-fixture-secret','orders/paid');
    $check($shopStore->recordVerifiedDelivery($org,$conn,$delivery,'orders/paid',$raw,$gid),'Verified webhook missing.');
    $pendingInput=['channel'=>'shopify','connection_id'=>$conn];
    $check(count($readSource('sales.order.source.pending.read',$pendingInput)['deliveries'])===1,'Verified pointer not visible.');
    $import=$runSource([...$sourceInput,'delivery_id'=>$delivery],'import-'.$token);
    $externalOrderId=$import['order_id'];$externalOrder=$orderService->read($org,$owner,$externalOrderId)['order'];
    $check($import['outcome']==='imported'&&$externalOrder['channel']==='shopify'&&$externalOrder['total']==='120.00'&&$externalOrder['tax_included']===true&&$externalOrder['status']==='placed'&&$externalOrder['revision']===1,'Tax inclusive Shopify order failed.');
    $check($orderRepo->placement($org,$externalOrderId)['realized_revenue']===null&&$orderRepo->placement($org,$externalOrderId)['provenance']==='shopify_admin_read','External paid status treated as Finance evidence.');
    $check($readSource('sales.order.source.pending.read',$pendingInput)['deliveries']===[],'Successful pointer acknowledgement failed.');
    $reads=$http->reads;
    $replay=$runSource([...$sourceInput,'delivery_id'=>$delivery],'import-'.$token);
    $check($replay['replayed']&&$replay['order_id']===$externalOrderId&&$http->reads===$reads,'Provider request replay was not idempotent.');
    $reject(fn()=>$runSource([...$sourceInput,'external_order_id'=>'gid://shopify/Order/12346','delivery_id'=>$delivery],'import-'.$token));
    $same=$runSource($sourceInput,'same-'.$token);
    $check($same['outcome']==='unchanged'&&$same['order_id']===$externalOrderId,'Source identity duplicated across keys.');
    $http->financial='REFUNDED';$http->current='0.00';$http->updated='2026-11-24T11:00:00Z';
    $observed=$runSource($sourceInput,'newer-'.$token);
    $check($observed['outcome']==='observed'&&$orderService->read($org,$owner,$externalOrderId)['order']===$externalOrder,'Provider update overwrote canonical terms.');
    $http->updated='2026-11-24T10:00:00Z';$http->financial='PAID';$http->current='120.00';
    $check($runSource($sourceInput,'older-'.$token)['outcome']==='stale','Out-of-order provider revision not fenced.');
    $http->updated='2026-11-24T11:00:00Z';
    $check($runSource($sourceInput,'conflict-'.$token)['outcome']==='conflict','Same revision conflicting provider snapshot accepted.');
    $http->updated='2026-11-24T12:00:00Z';$http->unmapped=true;
    $newInput=[...$sourceInput,'external_order_id'=>'gid://shopify/Order/12346'];
    $blocked=$runSource($newInput,'blocked-'.$token);
    $check($blocked['outcome']==='blocked'&&$blocked['order_id']===null,'Unmapped order silently imported.');
    $shopStore->map($org,$conn,'variant','gid://shopify/ProductVariant/99',str_repeat('c',32));
    $check($runSource($newInput,'retry-'.$token)['outcome']==='imported','Mapping repair did not permit new supervised import.');
    $http->unmapped=false;
    $http->fail=true;
    $db->exec('SAVEPOINT order_provider_failure');
    try {$reject(fn()=>$runSource($sourceInput,'outage-'.$token));}
    finally {$db->exec('ROLLBACK TO SAVEPOINT order_provider_failure');$db->exec('RELEASE SAVEPOINT order_provider_failure');}
    $http->fail=false;
    $check($runSource($sourceInput,'outage-'.$token)['outcome']==='observed','Failed provider call consumed retry receipt.');
    $reject(fn()=>$runSource([...$sourceInput,'connection_id'=>str_repeat('e',32)],'foreign-shop-'.$token));
    $reject(fn()=>$gateway->invoke($manager,'sales.order.source.reconcile',$sourceInput,'trusted_read','automated-'.$token,'source-test'));
    echo "Sales O2 Shopify HMAC pointer, mock GraphQL, exact placement, replay and revision reconciliation PASS.\n";

    $check($writes->quickUpdateOpportunity($ownedId,['priority'=>'high'],$owner,'p4-own')->ok,'Guarded own write failed.');
    $check($read->deal($org,$ownedId)['priority']==='high','Guarded write did not change native state.');
    $shared=$writes->updateOpportunity($ownedId,['full_name'=>'Forbidden shared contact edit'],$owner);
    $check(!$shared->ok&&$shared->code==='sales_access_denied','Shared contact edited through owned case.');
    $foreignBefore=$read->deal($org,$otherId);
    $denied=$writes->quickUpdateOpportunity($otherId,['priority'=>'urgent'],$owner,'p4-foreign');
    $check(!$denied->ok&&$denied->code==='sales_access_denied'&&$read->deal($org,$otherId)['priority']===$foreignBefore['priority'],'Foreign mutation bypass.');
    $denyOwner=$writes->updateLead($leadId,['owner_id'=>$other],$owner,'p4-assign');
    $check(!$denyOwner->ok&&$denyOwner->code==='sales_access_denied','Unauthorized assignment accepted.');
    $created=$writes->createLead(['full_name'=>'Private guard fixture','email'=>'guard-'.$token.'@example.test'],$owner,'p4-create','guard-'.$token);
    $check($created->ok,'Authorized lead creation failed.');
    $newLead=(int)$created->data['lead_id'];
    $db->prepare('UPDATE tn_leads SET assigned_user_id=? WHERE organization_id=? AND id=?')->execute([$other,$org,$newLead]);
    $replay=$writes->createLead(['full_name'=>'Private guard fixture','email'=>'guard-'.$token.'@example.test'],$owner,'p4-replay','guard-'.$token);
    $check(!$replay->ok&&$replay->code==='sales_access_denied','Receipt replay exposed reassigned lead.');
    $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.workspace.use"')->execute([$org,$owner]);
    $denied=$writes->quickUpdateOpportunity($ownedId,['priority'=>'urgent'],$owner,'p4-revoked');
    $check(!$denied->ok&&$denied->code==='sales_access_denied'&&$read->deal($org,$ownedId)['priority']==='high','Revoked permission still wrote.');
    // P5: stable table data contract for Workspace/export, exact values and scope.
    $reportCall=static fn(string $id,array $extra=[])=>$gateway->invoke($manager,'sales.reports.data.read',['report_id'=>$id,...$range,...$extra],'trusted_read','','reports-test');
    $report=$reportCall('forecast',['pipeline_id'=>$pid]);
    $usd=array_values(array_filter($report['rows'],static fn(array $r):bool=>$r['metric']==='weighted_value'&&$r['currency']==='USD'&&$r['bucket']==='in_period'));
    $check($report['row_count']===count($report['rows'])&&count($usd)===1&&$usd[0]['value']==='1256.26','Forecast report lost exact money or row metadata.');
    foreach(['outcomes','conversion','stages'] as $reportId){
        $result=$reportCall($reportId);
        $check($result['schema_version']==='1.0.0'&&$result['report_id']===$reportId&&$result['owner_id']===$owner
            &&!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'Private'),'Report scope or PII failure.');
    }
    $reject(fn()=>$reportCall('outcomes',['scope'=>'tenant']));
    $reject(fn()=>$reportCall('forecast',['scope'=>'owner','owner_id'=>$other]));
    $reject(fn()=>$reportCall('conversion',['pipeline_id'=>$pid]));
    $reject(fn()=>$reportCall('unknown'));
    $db->prepare('DELETE FROM cos_organization_memberships WHERE organization_id=? AND user_id=?')->execute([$org,$owner]);
    $reject(fn()=>$reportCall('outcomes'));
    echo "Sales S5 scoped exact summary, redacted lists and timeline PASS.\n";
}finally{$db->rollBack();}


// Two physical connections, committed isolated fixture: prove row locks, not just
// rejection against an uncommitted fixture visible only on the first connection.
$lockActor=0;$lockPerson=0;$lockDeal=0;
$db2=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db2->exec('SET SESSION innodb_lock_wait_timeout=1');
try {
    $db->beginTransaction();
    $lockActor=$makeUser('lock');
    $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,?,"ACTIVE",?,NOW(6),NOW(6))')->execute([$org,$lockActor,'sales.workspace.use',(string)$lockActor]);
    $db->prepare('INSERT INTO tn_people(organization_id,public_id,full_name) VALUES (?,?,?)')->execute([$org,'LP-'.$token,'Lock fixture']);
    $lockPerson=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO tn_client_cases(organization_id,public_id,person_id,type,title,status,stage,pipeline_id,stage_id,priority,assigned_user_id,currency) VALUES (?,?,?,"buy","Lock fixture","active","new",?,?,"normal",?,"USD")')->execute([$org,'LD-'.$token,$lockPerson,$pid,$stage,$lockActor]);
    $lockDeal=(int)$db->lastInsertId();
    $db->commit();
    $locked=static function(callable $competing)use($check):void {
        try {$competing();} catch(PDOException $e) {
            $check((int)($e->errorInfo[1]??0)===1205,'Unexpected competing transaction error: '.$e->getMessage());return;
        }
        throw new RuntimeException('Competing mutation bypassed the authorization lock.');
    };
    $guard->run($org,$lockActor,function()use($db2,$org,$lockActor,$lockDeal,$locked):void{
        $locked(fn()=>$db2->prepare('UPDATE tn_client_cases SET assigned_user_id=NULL WHERE organization_id=? AND id=?')->execute([$org,$lockDeal]));
        $locked(fn()=>$db2->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.workspace.use"')->execute([$org,$lockActor]));
        $locked(fn()=>$db2->prepare('DELETE FROM cos_organization_memberships WHERE organization_id=? AND user_id=?')->execute([$org,$lockActor]));
    },'deal',$lockDeal);
    $db2->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=? AND capability="sales.workspace.use"')->execute([$org,$lockActor]);
    $reject(fn()=>$guard->run($org,$lockActor,static fn()=>null,'deal',$lockDeal));
    echo "Sales two-connection authorization lock and revocation PASS.\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    if($lockDeal>0)$db->prepare('DELETE FROM tn_client_cases WHERE organization_id=? AND id=?')->execute([$org,$lockDeal]);
    if($lockPerson>0)$db->prepare('DELETE FROM tn_people WHERE organization_id=? AND id=?')->execute([$org,$lockPerson]);
    if($lockActor>0){
        $db->prepare('DELETE FROM sales_user_capabilities WHERE organization_id=? AND user_id=?')->execute([$org,$lockActor]);
        $db->prepare('DELETE FROM cos_organization_memberships WHERE organization_id=? AND user_id=?')->execute([$org,$lockActor]);
        $db->prepare('DELETE FROM tn_users WHERE organization_id=? AND id=?')->execute([$org,$lockActor]);
    }
}
