<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;
use DomainException;

final class SalesOfferDocument
{
    public static function render(array $offer):array
    {
        if($offer['direction']!=='sales')throw new DomainException('Only a sales-authored quote can be rendered for presentation.');
        $text=$offer['title']."\nOffer: ".$offer['offer_id']." / v".$offer['terms_version']."\nValid until (UTC): ".$offer['valid_until']."\n\n";
        $html='<!doctype html><html lang="en"><meta charset="utf-8"><title>'.self::e($offer['title']).'</title><body><h1>'.self::e($offer['title']).'</h1><p>Offer '.self::e($offer['offer_id']).' / v'.(int)$offer['terms_version'].'</p><p>Valid until (UTC): '.self::e($offer['valid_until']).'</p><table><thead><tr><th>Description</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead><tbody>';
        foreach($offer['lines'] as $line){
            $text.=$line['title'].' | '.$line['quantity'].' x '.$line['unit_price'].' '.$offer['currency'].' = '.$line['line_total']."\n";
            $html.='<tr><td>'.self::e($line['title']).'</td><td>'.(int)$line['quantity'].'</td><td>'.self::e($line['unit_price'].' '.$offer['currency']).'</td><td>'.self::e($line['line_total'].' '.$offer['currency']).'</td></tr>';
        }
        $text.="\nTotal: ".$offer['total'].' '.$offer['currency']."\nTerms reference: ".$offer['terms_hash']."\n";
        $html.='</tbody></table><p><strong>Total: '.self::e($offer['total'].' '.$offer['currency']).'</strong></p><p>Terms reference: '.self::e($offer['terms_hash']).'</p></body></html>';
        if(mb_strlen($text)>10000)throw new DomainException('Offer document exceeds supported delivery size.');
        return ['offer_id'=>$offer['offer_id'],'revision'=>$offer['revision'],'terms_version'=>$offer['terms_version'],'terms_hash'=>$offer['terms_hash'],
            'document_hash'=>hash('sha256',$text),'subject'=>$offer['title'],'text'=>$text,'html'=>$html];
    }
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
