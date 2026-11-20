<?php
declare(strict_types=1);
namespace App\Web\Experience\Unified;

/** Presentation-only allowlist. Canonical Capability Library owns all authority. */
final readonly class UnifiedCapabilityPresentation {
    private const PRESENTERS = [
        'property.asset.create'=>['label'=>'Створити об’єкт нерухомості','type'=>'action','href'=>'#cos-uw-create'],
        'property.asset.search'=>['label'=>'Знайти нерухомість','type'=>'query','href'=>'/workspace/unified/property'],
        'property.asset.get'=>['label'=>'Переглянути об’єкт','type'=>'query','href'=>'/workspace/unified/property'],
        'property.bulk.create'=>['label'=>'Імпорт об’єктів','type'=>'approval','href'=>'/workspace/property/bulk'],
    ];
    public function supports(string $id): bool {
        return isset(self::PRESENTERS[$id]);
    }
    /** @param array<string,mixed> $entry @return array<string,mixed> */
    public function describe(array $entry): array {
        $id=(string)($entry['id']??'');
        $contract=is_array($entry['contract']??null) ? $entry['contract'] : null;
        $presentation=self::PRESENTERS[$id]??null;
        $status=(string)($entry['status']??'unavailable');
        $uiReady=$contract!==null && $presentation!==null
            && in_array($status,['direct_human','read_only','workflow_approval_required'],true);
        return [
            'id'=>$id,
            'label'=>$presentation['label']??$id,
            'kind'=>$presentation['type']??'unavailable',
            'href'=>$uiReady ? $presentation['href'] : null,
            'status'=>$status,
            'reason'=>(string)($entry['reason']??'Немає перевіреного presentation contract.'),
            'ui_ready'=>$uiReady,
            'tenant_ready'=>$status!=='unavailable',
            // A declaration or route is never a production execution certificate.
            'execution_verified'=>false,
            'needs_approval'=>$status==='workflow_approval_required',
            'risk'=>$contract['side_effect_level']??'unknown',
        ];
    }
}
