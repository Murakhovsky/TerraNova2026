<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Platform\Documents\Model\Document;
use Platform\Documents\Model\File;
use Platform\Documents\Model\Permission;
use Platform\Documents\Model\Relation;
use Platform\Documents\Model\Signature;
use Platform\Documents\Model\Template;
use Platform\Documents\Model\Version;
use Platform\Documents\Contract\DocumentAttachmentPort;
use Platform\Documents\Contract\DocumentMutationReceiptInterface;
use Platform\Documents\Contract\DocumentsRepositoryInterface;
use Platform\Documents\Event\DocumentsEventType;

function expectDocuments(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$org = OrganizationId::fromString('org-1');
$document = new Document('doc-1', $org, 'Sales contract');
$file = new File('file-1', $org, 'documents/doc-1/v1.pdf', 'application/pdf');
$template = new Template('template-1', $org, 'Sales contract');
$version = new Version('version-1', $org, 'doc-1', '1');
$signature = new Signature('signature-1', $org, 'doc-1', 'user-1');
$relation = new Relation('relation-1', $org, 'doc-1', 'sales.opportunity', 'opportunity-1');
$permission = new Permission('permission-1', $org, 'doc-1', 'user-1', 'read');

expectDocuments($document->organizationId->value() === 'org-1', 'Document must be tenant-scoped.');
expectDocuments($file->mimeType === 'application/pdf', 'File metadata must be represented without storage provider coupling.');
expectDocuments($template->name === 'Sales contract' && $version->documentId === 'doc-1', 'Template and version vocabulary must autoload.');
expectDocuments($signature->signerId === 'user-1', 'Signature vocabulary must autoload.');
expectDocuments($relation->relatedType === 'sales.opportunity' && $permission->level === 'read', 'Relation and permission vocabulary must stay generic.');

$attachmentPort=new ReflectionClass(DocumentAttachmentPort::class);
expectDocuments($attachmentPort->hasMethod('attachExistingDocument'),'Documents cross-domain attachment port must expose attachExistingDocument.');

$repository=new ReflectionClass(DocumentsRepositoryInterface::class);
foreach(['createDocument','createVersion','attach','findTemplate','createSignatureRequest','findSignature','sign','archive','view','lockDocumentStatus','nextVersionNumber'] as $method){
    expectDocuments($repository->hasMethod($method),'Documents repository contract missing '.$method.'.');
}

$receipt=new ReflectionClass(DocumentMutationReceiptInterface::class);
expectDocuments($receipt->hasMethod('claim'),'Documents mutation receipt must expose atomic claim.');

expectDocuments(count(DocumentsEventType::values())===7,'Documents Wave 10 must expose seven lifecycle event types.');
expectDocuments(in_array(DocumentsEventType::SIGNED,DocumentsEventType::values(),true),'Documents signed event must be canonical.');

echo "Platform Documents vocabulary/runtime contracts passed.\n";
