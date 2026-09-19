<?php
declare(strict_types=1);

namespace App\Application\Property\Query;

use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class SearchPropertiesQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyReferencePort $properties) {}

    public function __invoke(SearchPropertiesQuery $query): array
    {
        $page=max(1,(int)($query->filters['page']??1));
        $perPage=max(1,min(100,(int)($query->filters['per_page']??50)));
        $org=$query->organizationId->value();
        $search=trim((string)($query->filters['q']??''));

        if ($search !== '') {
            $refs=$this->properties->searchPropertyReferences($org,$search,200);
            $items=[];
            foreach($refs as $ref){
                $id=$ref['asset_id']??$ref['legacy_property_id']??null;
                if($id===null)continue;
                $presentation=$this->properties->getPropertyPresentation($org,(string)$id);
                if($presentation===null)continue;
                $inventory=is_array($presentation['inventory']??null)?$presentation['inventory']:[];
                if(!in_array((string)($inventory['status']??''),['available','under_offer','reserved'],true))continue;
                $items[]=$presentation;
            }
        } else {
            $criteria=array_intersect_key($query->filters,array_flip(['transaction_type','type_code','price_max']));
            $inventory=$this->properties->findAvailableInventory($org,$criteria);
            $items=[];
            foreach($inventory as $row){
                $presentation=$this->properties->getPropertyPresentation($org,(string)($row['asset_id']??''));
                if($presentation!==null)$items[]=$presentation;
            }
        }

        $total=count($items);
        $offset=($page-1)*$perPage;
        return [
            'items'=>array_slice($items,$offset,$perPage),
            'pagination'=>[
                'page'=>$page,
                'per_page'=>$perPage,
                'total'=>$total,
                'total_pages'=>$total===0?0:(int)ceil($total/$perPage),
            ],
        ];
    }
}
