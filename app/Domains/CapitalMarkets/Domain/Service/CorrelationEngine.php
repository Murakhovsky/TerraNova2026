<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
final class CorrelationEngine {
 /** @param array<string,list<float|int>> $series @return array<string,array<string,float>> */
 public function matrix(array $series):array {
  ksort($series);$out=[];
  foreach($series as $a=>$xs){foreach($series as $b=>$ys)$out[$a][$b]=$this->pearson($xs,$ys);}
  return $out;
 }
 private function pearson(array $x,array $y):float {
  $n=min(count($x),count($y));if($n<2)return 0.0;$x=array_slice($x,-$n);$y=array_slice($y,-$n);
  $mx=array_sum($x)/$n;$my=array_sum($y)/$n;$num=0.0;$dx=0.0;$dy=0.0;
  for($i=0;$i<$n;$i++){$a=(float)$x[$i]-$mx;$b=(float)$y[$i]-$my;$num+=$a*$b;$dx+=$a*$a;$dy+=$b*$b;}
  if($dx<=0||$dy<=0)return 0.0;return max(-1.0,min(1.0,$num/sqrt($dx*$dy)));
 }
}
