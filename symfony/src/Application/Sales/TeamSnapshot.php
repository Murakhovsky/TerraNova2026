<?php
declare(strict_types=1);

namespace App\Application\Sales;

use DomainException;

final class TeamSnapshot
{
    public static function fromRow(array $row):array
    {
        if (!is_string($row['id']??null) || $row['id']==='' || (int)($row['configuration_version']??0)<1) throw new DomainException('Invalid native team.');
        $members=[];
        foreach($row['members']??[] as $m)$members[]=[
            'user_id'=>(int)$m['user_id'],'role'=>(string)$m['role'],'status'=>(string)$m['status'],
            'assignment_enabled'=>(bool)$m['assignment_enabled'],'approval_enabled'=>(bool)$m['approval_enabled'],'assignment_weight'=>(int)$m['assignment_weight'],
        ];
        return ['team_id'=>$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'status'=>(string)$row['status'],
            'assignment_mode'=>(string)$row['assignment_mode'],'configuration_version'=>(int)$row['configuration_version'],'members'=>$members];
    }
}
