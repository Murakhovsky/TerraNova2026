<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('symfony/src/Web/ProductManagement/ProductManagementPageController.php');
$twig=$read('symfony/templates/experience/product_management/workspace.html.twig');
$routes=$read('symfony/config/routes.yaml');
$workflow=$read('.github/workflows/runtime.yml');
$media=$read('app/Domains/ProductManagement/Application/Service/ProductMediaService.php');
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Product Media Workspace: '.$message);
};
foreach(['media.attach','media.detach','media.search','CanonicalCapabilityGateway',
    'guardWrite($request)','product_id','media_id','file_id','containsId('] as $required){
    $assert(str_contains($controller,$required),'Missing tenant-scoped Media write/readback: '.$required);
}
foreach(['new PDO','->prepare(', 'unlink(','file_put_contents(', 'ShopifyReadClient'] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Web created an unauthorized file/writer: '.$forbidden);
}
foreach(['verifiedImage(','attachMedia(', 'detachMedia('] as $required){
    $assert(str_contains($media,$required),'Verified Documents media binding missing: '.$required);
}
$assert(substr_count($twig,'name="csrf_token"')>=7
    &&str_contains($twig,'name="file_id"')
    &&str_contains($routes,'cos_product_management_product_media_attach:')
    &&str_contains($routes,'cos_product_management_product_media_detach:'),
    'Media forms or guarded routes missing.');
$assert(str_contains($workflow,'php tests/architecture/product_management_media_workspace_contract.php'),
    'Media Workspace architecture CI gate missing.');
foreach(['imagePreview','uploadMedia','ProductMediaBrowserPort','UploadDocumentCommand','getimagesizefromstring','Cache-Control'] as $required)
    $assert(str_contains($controller,$required),'Missing verified image browser/upload: '.$required);
foreach(['cos_product_management_image_preview:','cos_product_management_product_media_upload:'] as $required)
    $assert(str_contains($routes,$required),'Missing image route: '.$required);
echo "Product Management native media Workspace architecture passed.\n";
