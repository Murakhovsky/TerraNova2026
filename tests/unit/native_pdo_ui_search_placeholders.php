<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

$contracts=[
    'app/Domains/Property/Infrastructure/ReadModel/MySql/CatalogService.php'=>[
        ':q_title',':q_public_id',':q_short_description',':q_description',':q_address',':q_city',
        ':order_type_id',':order_location_id',
    ],
    'app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyWorkspaceReadModel.php'=>[
        ':q_asset',':q_title',':q_slug',':q_location',':q_address',
    ],
    'app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php'=>[
        ':q_title',':q_public_id',':q_short_description',':q_description',':q_address',':q_city',
    ],
    'app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentRepository.php'=>[
        ':q_experiment_id',':q_name',':q_hypothesis',
    ],
    'app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php'=>[
        ':q_candidate_id',':q_subject_id',':q_opportunity_type',':q_recommended_play',
        ':q_name',':q_domain',':q_account_id',
        ':q_signal_id',':q_signal_type',':q_source_reference',
        ':q_reference_id',':q_reason_code',':q_account_name',
    ],
    'app/Domains/Content/Infrastructure/Persistence/MySql/MysqlContentRepository.php'=>[
        ':search_title',':search_slug',':search_keyword',
    ],
];

foreach($contracts as $path=>$markers){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach($markers as $marker){
        if(!str_contains($source,$marker)){
            throw new RuntimeException($path.' is missing distinct native PDO placeholder '.$marker);
        }
    }
}

$unsafe=[
    'app/Domains/Property/Infrastructure/ReadModel/MySql/CatalogService.php'=>['LIKE :q OR','(p.type_id = :type_id) DESC','(p.location_id = :location_id) DESC'],
    'app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyWorkspaceReadModel.php'=>['LIKE :q OR'],
    'app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php'=>['LIKE :q OR'],
    'app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentRepository.php'=>['LIKE :q OR'],
    'app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php'=>['LIKE :q OR'],
    'app/Domains/Content/Infrastructure/Persistence/MySql/MysqlContentRepository.php'=>['LIKE :search OR'],
];

foreach($unsafe as $path=>$patterns){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach($patterns as $pattern){
        if(str_contains($source,$pattern)){
            throw new RuntimeException($path.' still reuses a named placeholder under native PDO prepares: '.$pattern);
        }
    }
}

echo "Native PDO UI search placeholder regression passed.\n";
