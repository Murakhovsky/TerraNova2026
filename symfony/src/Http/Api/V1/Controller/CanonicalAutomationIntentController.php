<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Capability\CanonicalAutomationIntentStore;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** No session, no delegated user identity, no Action submission. Signed proposals only. */
final readonly class CanonicalAutomationIntentController
{
    public function __construct(private CanonicalAutomationIntentStore $intents) {}

    public function submit(Request $request):JsonResponse
    {
        $body=(string)$request->getContent();
        if (strlen($body)>65536) return self::reply(['error'=>'payload_too_large'],413);
        try {
            $result=$this->intents->submit(
                (string)$request->headers->get('X-COS-Automation-Origin',''),
                (string)$request->headers->get('X-COS-Automation-Timestamp',''),
                (string)$request->headers->get('X-COS-Automation-Nonce',''),
                (string)$request->headers->get('X-COS-Automation-Signature',''),
                $body,
            );
            return self::reply($result,202);
        } catch(DomainException|\JsonException) {
            return self::reply(['error'=>'invalid_or_untrusted_automation_request'],403);
        } catch(Throwable $e) {
            error_log('cos.automation.intent.submit '.$e->getMessage());
            return self::reply(['error'=>'automation_intake_unavailable'],503);
        }
    }

    /** @param array<string,mixed> $data */
    private static function reply(array $data,int $status):JsonResponse
    {
        $res=new JsonResponse($data,$status);
        $res->headers->set('Cache-Control','no-store');
        return $res;
    }
}
