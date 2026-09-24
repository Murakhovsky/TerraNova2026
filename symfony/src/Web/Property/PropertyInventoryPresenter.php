<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;
use App\Web\Property\ViewModel\PropertyInventoryViewModel;

final class PropertyInventoryPresenter
{
    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        DataGridQuery $query,
        string $mode,
        ?string $error=null,
    ): PropertyInventoryViewModel {
        $items=$this->list($data['items']??null);
        $pagination=is_array($data['pagination']??null)?$data['pagination']:[];
        $page=max(1,(int)($pagination['page']??$query->page));
        $perPage=max(10,(int)($pagination['per_page']??$query->perPage));
        $total=max(0,(int)($pagination['total']??$data['total']??count($items)));

        $rows=[];
        foreach($items as $item){
            $currency=trim((string)($item['price_currency']??'USD'))?:'USD';
            $price=($item['price_amount']??null)!==null
                ? number_format((float)$item['price_amount'],0,'.',' ').' '.$currency
                : '—';
            $title=trim((string)($item['title']??''));
            $assetId=trim((string)($item['asset_id']??''));
            $location=trim((string)(($item['location_name']??'')?:($item['formatted_address']??'')));

            $rows[]=[
                'id'=>(string)($item['inventory_id']??$assetId),
                'property'=>trim(($title!==''?$title:'Property').' · '.$assetId,' ·'),
                'inventory_id'=>(string)($item['inventory_id']??''),
                'type'=>(string)($item['type_code']??$item['kind']??'—'),
                'location'=>$location!==''?$location:'—',
                'transaction'=>(string)($item['transaction_type']??'—'),
                'price'=>$price,
                'inventory_status'=>(string)($item['status']??'—'),
                'listing_status'=>(string)($item['listing_status']??'—'),
            ];
        }

        $gridState=$error!==null
            ? DataGridState::Error
            : ($total===0?DataGridState::Empty:DataGridState::Ready);

        $listing=$mode==='listing';

        return new PropertyInventoryViewModel(
            mode:$listing?'listing':'manage',
            title:$listing?'Listing':'Inventory',
            subtitle:$listing
                ? 'Listing projection поверх canonical Property Asset та Inventory.'
                : 'Canonical Property Asset → Inventory projection для операційної роботи.',
            baseUrl:$listing?'/property/listing':'/property/manage',
            query:$query,
            page:new DataGridPage($rows,$total,$page,$perPage),
            gridState:$gridState,
            columns:[
                new DataGridColumn('property','Property',mobilePriority:10),
                new DataGridColumn('inventory_id','Inventory ID',mobilePriority:20),
                new DataGridColumn('type','Type',mobilePriority:30),
                new DataGridColumn('location','Location',mobilePriority:40),
                new DataGridColumn('transaction','Transaction',mobilePriority:50),
                new DataGridColumn('price','Price',mobilePriority:60,align:'end'),
                new DataGridColumn('inventory_status','Inventory',mobilePriority:70),
                new DataGridColumn('listing_status','Listing',mobilePriority:80),
            ],
            filters:[
                new DataGridFilter(
                    'status','Inventory status',
                    [''=>'All','available'=>'Available','reserved'=>'Reserved','under_offer'=>'Under offer','on_hold'=>'On hold','sold'=>'Sold','rented'=>'Rented','off_market'=>'Off market','withdrawn'=>'Withdrawn'],
                    $query->filters['status']??null,
                ),
                new DataGridFilter(
                    'transaction_type','Transaction',
                    [''=>'All','sale'=>'Sale','rent'=>'Rent','investment'=>'Investment'],
                    $query->filters['transaction_type']??null,
                ),
                new DataGridFilter(
                    'type_code','Type code',[],
                    $query->filters['type_code']??null,
                    type:'text',
                    placeholder:'apartment, house…',
                ),
            ],
            stats:is_array($data['stats']??null)?$data['stats']:[],
            error:$error,
        );
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if(!is_array($value))return [];
        return array_values(array_filter($value,'is_array'));
    }
}
