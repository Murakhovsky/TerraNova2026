<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use Domains\Sales\Application\Contract\SalesCommercialEvidencePort;
use DomainException;

/** Source evidence is not booked income, a receivable or payment. */
final readonly class SalesCommercialEvidenceReader
{
    public function __construct(private SalesCommercialEvidencePort $sales){}
    public function read(string $org,int $id):array
    {
        $evidence=$this->sales->acceptedOffer($org,$id);
        if($evidence!==null&&(($evidence['deal_id']??null)!==$id||($evidence['settled']??true)!==false))throw new DomainException('Invalid commercial evidence.');
        return ['found'=>$evidence!==null,'evidence'=>$evidence];
    }
}
