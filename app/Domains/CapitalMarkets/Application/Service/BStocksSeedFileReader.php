<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

/**
 * Reads declared seed evidence, never executes XLSM macros or authorizes listings.
 * OOXML is read in memory from two fixed ZIP entries; no archive extraction.
 */
final class BStocksSeedFileReader
{
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_XML_BYTES = 4194304;
    private const MAX_ROWS = 500;
    private const COLUMNS = ['B','C','D','E','F','G','H','I'];

    /**
     * @return array{format:string,sha256:string,source_as_of:string,rows:list<array<string,mixed>>}
     */
    public function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file) || is_link($file)) {
            throw new InvalidArgumentException('Seed source must be a readable regular file.');
        }
        $size = filesize($file);
        if ($size === false || $size < 10 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Seed source exceeds permitted size or is empty.');
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx','xlsm'], true)) {
            throw new InvalidArgumentException('Only CSV and OOXML XLSX/XLSM are supported.');
        }
        $loaded = $ext === 'csv'
            ? ['rows'=>$this->csv($file),'as_of'=>'2026-09-27T17:53:00Z']
            : $this->ooxml($file);
        $rows = $loaded['rows'];
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['token_symbol']])) {
                throw new InvalidArgumentException('Duplicate seed token: '.$row['token_symbol']);
            }
            $seen[$row['token_symbol']] = true;
        }
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Seed row count outside 1..500.');
        }
        return ['format'=>$ext,'sha256'=>hash_file('sha256',$file),
            'source_as_of'=>$loaded['as_of'],'rows'=>$rows];
    }

    /** @return list<array<string,mixed>> */
    private function csv(string $file): array
    {
        $h = fopen($file,'rb');
        if ($h === false) throw new InvalidArgumentException('CSV unreadable.');
        try {
            $header = fgetcsv($h);
            if ($header !== ['underlying_symbol','token_symbol','binance_spot_symbol','source_asset_type','leverage_factor']) {
                throw new InvalidArgumentException('Unsupported CSV seed schema.');
            }
            $rows = [];
            while (($cols = fgetcsv($h)) !== false) {
                if ($cols === [null]) continue;
                if (count($cols) !== 5 || count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('Malformed or oversized CSV.');
                }
                $rows[] = $this->normalize([$cols[0],$cols[1],$cols[2],null,null,null,$cols[3],$cols[4]]);
            }
            return $rows;
        } finally {
            fclose($h);
        }
    }

    /** @return list<array<string,mixed>> */
    private function ooxml(string $file): array
    {
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new InvalidArgumentException('OOXML support requires ZIP and DOM extensions.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new InvalidArgumentException('Invalid OOXML ZIP.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 150) {
                throw new InvalidArgumentException('Invalid OOXML ZIP entry count.');
            }
            $total = 0;
            for ($i=0; $i<$zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new InvalidArgumentException('Invalid OOXML ZIP directory.');
                }
                $name = $stat['name'];
                if (str_contains($name,'..') || str_starts_with($name,'/')
                    || str_contains($name,chr(92))) {
                    throw new InvalidArgumentException('Unsafe OOXML ZIP entry.');
                }
                $total += (int)($stat['size'] ?? 0);
                if ($total > 16777216) throw new InvalidArgumentException('OOXML inflated size limit exceeded.');
            }
            $sst = $this->xml($zip,'xl/sharedStrings.xml');
            $sheet = $this->xml($zip,'xl/worksheets/sheet1.xml');
            $shared = [];
            $sx = new DOMXPath($sst);
            $sx->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sx->query('//x:sst/x:si') as $si) {
                $shared[] = trim($si->textContent);
                if (count($shared) > 10000) throw new InvalidArgumentException('Shared strings excessive.');
            }
            $xp = new DOMXPath($sheet);
            $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = [];
            $header = [];
            $sourceAsOf = null;
            foreach ($xp->query('//x:sheetData/x:row') as $row) {
                $rowNumber = (int)$row->getAttribute('r');
                if ($rowNumber < 2) continue;
                if ($rowNumber > 5500) break;
                $cells = [];
                foreach ($xp->query('./x:c',$row) as $cell) {
                    $ref = $cell->getAttribute('r');
                    if (!preg_match('/^([A-Z]{1,3})[0-9]+$/D',$ref,$m) || !in_array($m[1],array_merge(['A'],self::COLUMNS),true)) continue;
                    if ($xp->query('./x:f',$cell)->length > 0) throw new InvalidArgumentException('Formula cells are not importable.');
                    $v = $xp->query('./x:v',$cell)->item(0);
                    $raw = $v?->textContent ?? '';
                    $type = $cell->getAttribute('t');
                    if ($type === 's') {
                        if (!ctype_digit($raw) || !array_key_exists((int)$raw,$shared)) {
                            throw new InvalidArgumentException('Invalid OOXML shared-string index.');
                        }
                        $raw = $shared[(int)$raw];
                    } elseif ($type === 'inlineStr') {
                        $raw = $xp->query('./x:is',$cell)->item(0)?->textContent ?? '';
                    }
                    $cells[$m[1]] = trim($raw);
                }
                if ($rowNumber === 2) {
                    $description = (string)($cells['A'] ?? '');
                    if (preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})\s*UTC/u',$description,$matches)!==1) {
                        throw new InvalidArgumentException('Missing workbook source timestamp.');
                    }
                    $sourceAsOf=$matches[1].'T'.$matches[2].':00Z';
                    continue;
                }
                if ($rowNumber === 3) { $header = $cells; continue; }
                if (($cells['B'] ?? '') === '' && ($cells['C'] ?? '') === '') continue;
                if (count($rows) >= self::MAX_ROWS) throw new InvalidArgumentException('OOXML seed row limit exceeded.');
                $rows[] = $this->normalize(array_map(
                    static fn(string $col): ?string => $cells[$col] ?? null,
                    self::COLUMNS
                ));
            }
            if (strtolower($header['G'] ?? '') !== 'name'
                || strtolower($header['H'] ?? '') !== 'type'
                || strtolower($header['I'] ?? '') !== 'leverage') {
                throw new InvalidArgumentException('Unexpected bStocks worksheet schema.');
            }
            if ($sourceAsOf === null) throw new InvalidArgumentException('Workbook timestamp was not verified.');
            return ['rows'=>$rows,'as_of'=>$sourceAsOf];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip,string $name): DOMDocument
    {
        $stat = $zip->statName($name);
        if (!is_array($stat) || (int)$stat['size'] > self::MAX_XML_BYTES) {
            throw new InvalidArgumentException('Required OOXML member unavailable or oversized.');
        }
        $payload = $zip->getFromName($name);
        if (!is_string($payload) || strlen($payload)>self::MAX_XML_BYTES
            || stripos($payload,'<!DOCTYPE') !== false || stripos($payload,'<!ENTITY') !== false) {
            throw new InvalidArgumentException('Unsafe OOXML member.');
        }
        $doc = new DOMDocument();
        if (!$doc->loadXML($payload,LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new InvalidArgumentException('Malformed OOXML member.');
        }
        return $doc;
    }

    /** @param array<int,string|null> $cols @return array<string,mixed> */
    private function normalize(array $cols): array
    {
        [$underlying,$token,$market,$status,$url,$name,$type,$leverage] = array_pad($cols,8,null);
        foreach ([$underlying,$token,$market] as $value) {
            if (!is_string($value) || preg_match('/^[A-Z0-9]{1,40}$/D',$value)!==1) {
                throw new InvalidArgumentException('Invalid declared seed identifier.');
            }
        }
        if ($market !== $token.'USDT' || !in_array($type,['Stock','ETF'],true)
            || !is_string($leverage) || !preg_match('/^-?[1-5]$/D',$leverage)
            || ($type === 'Stock' && $leverage !== '1')) {
            throw new InvalidArgumentException('Invalid declared seed type, leverage or pair.');
        }
        if ($status !== null && $status !== 'TRADING') {
            throw new InvalidArgumentException('Invalid historical seed status.');
        }
        if ($url !== null && ($url !== 'https://www.binance.com/en/trade/'.$token.'_USDT')) {
            throw new InvalidArgumentException('Unexpected seed exchange URL.');
        }
        if ($name !== null && ($name === '' || mb_strlen($name)>190)) {
            throw new InvalidArgumentException('Invalid issuer/product name.');
        }
        return [
            'underlying_symbol'=>$underlying,'token_symbol'=>$token,
            'venue_symbol'=>$market,'declared_status'=>$status,
            'exchange_url'=>$url,'issuer_or_instrument_name'=>$name,
            'declared_instrument_type'=>$type,'declared_leverage'=>(int)$leverage,
            'risk_class'=>(int)$leverage === 1 ? 'STANDARD_CANDIDATE' : 'LEVERAGED_OR_INVERSE',
        ];
    }
}
