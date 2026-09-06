<?php
declare(strict_types=1);
namespace Interfaces\Web\Controller;
final class MethodologyStudioController extends ControllerBase
{
 public function indexAction():void{if(!$this->requireManager())return;$this->view->metaTitle='Diagnostic Methodology Studio | COS';$this->view->active='diagnostics';$this->view->csrfToken=$this->di->getShared('csrfTokenManager')->token();}
}
