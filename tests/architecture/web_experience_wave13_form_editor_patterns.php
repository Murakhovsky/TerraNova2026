<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Web/Experience/Component/CosFormSection.php',
 'symfony/templates/components/experience/cos_form_section.html.twig',
 'symfony/src/Web/Experience/Component/CosStickyActions.php',
 'symfony/templates/components/experience/cos_sticky_actions.html.twig',
] as $relative){
 if(!is_file($root.'/'.$relative))throw new RuntimeException('FormEditor pattern artifact missing: '.$relative);
}

$catalog=(string)file_get_contents($root.'/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
foreach(['CosFormSection','CosStickyActions'] as $marker){
 if(!str_contains($catalog,$marker))throw new RuntimeException('/dev/ui missing FormEditor component: '.$marker);
}

$forms=(string)file_get_contents($root.'/symfony/assets/styles/forms.css');
foreach(['.cos-form-section {','.cos-sticky-actions {','@media (max-width: 650px)'] as $marker){
 if(!str_contains($forms,$marker))throw new RuntimeException('FormEditor visual contract incomplete: '.$marker);
}
if(str_contains($forms,'@media (max-width: 760px)')){
 throw new RuntimeException('Forms retained non-canonical 760px breakpoint.');
}

$registry=(string)file_get_contents($root.'/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
foreach(["PageArchetype::FormEditor","['PageHeader', 'FormSection', 'StickyActions']"] as $marker){
 if(!str_contains($registry,$marker))throw new RuntimeException('FormEditor archetype contract incomplete: '.$marker);
}

echo "Wave 13 FormEditor reusable pattern foundation passed.\n";
