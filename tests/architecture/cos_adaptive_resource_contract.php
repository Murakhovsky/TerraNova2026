<?php
declare(strict_types=1);

use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Adaptive\ExperienceSemantic;

$root=dirname(__DIR__,2);
require_once $root.'/symfony/src/Web/Experience/Adaptive/ExperienceMode.php';
require_once $root.'/symfony/src/Web/Experience/Adaptive/ExperienceSemantic.php';

$keys=['label','preference_unavailable','expand','collapse','identifiers','more_actions','json_input','bulk_process'];
foreach (ExperienceMode::cases() as $mode) {
    $keys[]='mode.'.$mode->value;
    $keys[]='description.'.$mode->value;
}
foreach (ExperienceSemantic::TRIGGERS as $trigger) {
    $keys[]='trigger.'.$trigger;
}
$expected=array_map(static fn(string $key):string=>'uw.adaptive.'.$key,$keys);
sort($expected);
foreach (['uk','en'] as $locale) {
    $catalog=(string)file_get_contents($root.'/symfony/translations/cuw_adaptive.'.$locale.'.yaml');
    preg_match_all('/^"(uw\.adaptive\.[a-z0-9_.-]+)":\s*".+"\s*$/m',$catalog,$matches);
    $actual=$matches[1];
    sort($actual);
    if ($actual!==$expected || str_contains((string)file_get_contents($root.'/symfony/translations/messages.'.$locale.'.yaml'),'uw.adaptive.')) {
        throw new RuntimeException('UX-04 translation domain is incomplete, duplicated or not isolated: '.$locale);
    }
}
$pageRoot=$root.'/symfony/templates/experience/';
foreach (['unified/home','unified/business','unified/property_query','unified/capabilities','property/bulk_intake'] as $page) {
    $source=(string)file_get_contents($pageRoot.$page.'.html.twig');
    if (!str_contains($source,"{% include 'experience/adaptive/_styles.html.twig' %}")) {
        throw new RuntimeException('UX-04 stylesheet missing from '.$page);
    }
}
$source=(string)file_get_contents($pageRoot.'adaptive/_styles.html.twig');
if (!str_contains($source,"asset('styles/cuw-adaptive.css')")
    || !is_file($root.'/symfony/assets/styles/cuw-adaptive.css')
    || str_contains((string)file_get_contents($root.'/symfony/assets/styles/unified-workspace.css'),'.cos-adaptive-controls')) {
    throw new RuntimeException('UX-04 stylesheet is not isolated.');
}
echo "UX-04 dedicated style and UK/EN translation domain contract PASS.\n";
