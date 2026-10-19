<?php
declare(strict_types=1);

namespace App\Application\Property;

use DomainException;

/**
 * Bounded UTF-8 CSV intake, not a Property writer.
 * Excel can export CSV UTF-8 (comma, semicolon, tab).
 */
final class PropertyBulkCsvReader
{
    private const COLUMNS = [
        'type_code','city','address','region','district','country_code',
        'area_total','area_living','land_area','rooms','floors','floor','built_year','kind',
    ];
    private const NUMBERS = ['area_total','area_living','land_area','rooms'];
    private const INTEGERS = ['floors','floor','built_year'];

    /** @return list<array<string,mixed>> */
    public function parse(string $filename,string $bytes):array
    {
        if (!preg_match('/\.csv$/iD',$filename) || $bytes==='' || strlen($bytes)>65536
            || str_contains($bytes,"\0") || !mb_check_encoding($bytes,'UTF-8')) {
            throw new DomainException('Use a UTF-8 CSV file smaller than 64 KiB.');
        }
        if (str_starts_with($bytes,"\xEF\xBB\xBF")) $bytes=substr($bytes,3);
        $first=strtok($bytes,"\r\n");
        if (!is_string($first) || $first==='') throw new DomainException('CSV headers are required.');
        $separator=',';
        if (substr_count($first,';')>substr_count($first,',')
            && substr_count($first,';')>substr_count($first,"\t")) $separator=';';
        elseif (substr_count($first,"\t")>substr_count($first,',')) $separator="\t";

        $stream=fopen('php://temp/maxmemory:65536','w+');
        if ($stream===false) throw new DomainException('Unable to parse CSV.');
        try {
            fwrite($stream,$bytes);
            rewind($stream);
            $header=fgetcsv($stream,0,$separator,'"','');
            if (!is_array($header)||$header===[]) throw new DomainException('CSV header row missing.');
            $header=array_map(static fn(mixed $cell):string=>trim((string)$cell),$header);
            if (count($header)!==count(array_unique($header))
                || !in_array('type_code',$header,true)
                || !in_array('city',$header,true)
                || !in_array('address',$header,true)) {
                throw new DomainException('CSV needs unique type_code, city, address columns.');
            }
            foreach($header as $field) {
                if (!in_array($field,self::COLUMNS,true)) {
                    throw new DomainException('Unrecognized CSV column: '.$field);
                }
            }
            $items=[];
            while(($cells=fgetcsv($stream,0,$separator,'"',''))!==false) {
                if($cells===[null] || (count($cells)===1 && trim((string)$cells[0])==='')) continue;
                if(count($cells)!==count($header)) {
                    throw new DomainException('CSV row does not match header column count.');
                }
                $row=[];
                foreach($header as $index=>$field) {
                    $value=trim((string)$cells[$index]);
                    if ($value==='') continue;
                    if (in_array($field,self::NUMBERS,true)) {
                        $number=str_replace(',','.',$value);
                        if (!preg_match('/^\d+(?:\.\d+)?$/D',$number)) {
                            throw new DomainException('Invalid numeric CSV field: '.$field);
                        }
                        $row[$field]=(float)$number;
                    } elseif(in_array($field,self::INTEGERS,true)) {
                        if(!preg_match('/^\d+$/D',$value)) {
                            throw new DomainException('Invalid integer CSV field: '.$field);
                        }
                        $row[$field]=(int)$value;
                    } else {
                        // Prevent spreadsheet formulas from being imported as text.
                        if(preg_match('/^[=+\-@]/u',$value)) {
                            throw new DomainException('Spreadsheet formula-like values are forbidden.');
                        }
                        $row[$field]=$value;
                    }
                }
                if(!isset($row['type_code'],$row['city'],$row['address'])) {
                    throw new DomainException('Each CSV row requires type_code, city and address.');
                }
                $items[]=$row;
                if(count($items)>10) throw new DomainException('Maximum 10 CSV Property rows per batch.');
            }
            if($items===[]) throw new DomainException('CSV must contain at least one Property row.');
            return $items;
        } finally {
            fclose($stream);
        }
    }
}
