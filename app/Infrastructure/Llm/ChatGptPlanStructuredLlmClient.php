<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Closure;
use Kernel\Llm\LlmProviderException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;
use Platform\Settings\Contract\PlatformSettingsWriterInterface;

/** Explicit admin-only smoke calls; never used by unattended Agents or CRON. */
final readonly class ChatGptPlanStructuredLlmClient implements StructuredLlmClientInterface
{
    public function __construct(
        private ChatGptPlanOAuth $oauth,
        private PlatformSettingsReaderInterface $settings,
        private PlatformSettingsWriterInterface $writer,
        private ?Closure $transport = null,
    ) {}

    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        if ($request->useCase !== 'siwc.test' || $request->actorId === null || $request->organizationId === null)
            throw new LlmProviderException('chatgpt_plan', false, 'Personal plan forbids autonomous inference.');
        return $this->smoke($request->organizationId, $request->actorId, trim((string) $request->model),
            $request->userPrompt, $request->responseSchema);
    }

    /** @param array<string,mixed> $schema */
    public function smoke(string $organizationId, string $actorId, string $model, string $prompt, array $schema): StructuredLlmResponse
    {
        if (!$this->oauth->configured()) throw new LlmProviderException('chatgpt_plan',false,'Partner OAuth not enabled.');
        $key = 'credentials.'.$actorId;
        $secret = $this->settings->secret($organizationId,'chatgpt_plan',$key);
        $credentials = is_string($secret) ? json_decode($secret,true) : null;
        if (!is_array($credentials) || !is_string($credentials['access_token'] ?? null)
            || !is_string($credentials['refresh_token'] ?? null) || !is_string($credentials['sub'] ?? null)
            || !in_array(ChatGptPlanOAuth::PLAN_SCOPE,preg_split('/\s+/',(string)($credentials['scope'] ?? '')) ?: [],true))
            throw new LlmProviderException('chatgpt_plan',false,'Connect your own ChatGPT plan first.');
        if ((int)($credentials['expires_at'] ?? 0) < time()+90) {
            try {
                $fresh = $this->oauth->refresh($credentials['refresh_token']);
                if (!is_string($fresh['access_token'] ?? null) || $fresh['access_token'] === '')
                    throw new \RuntimeException('Missing refreshed access token.');
                if (isset($fresh['scope']) && !$this->oauth->hasPlanScope($fresh))
                    throw new \RuntimeException('Plan permission revoked.');
                if (is_string($fresh['id_token'] ?? null)
                    && $this->oauth->verifiedSubject($fresh['id_token'],null) !== $credentials['sub'])
                    throw new \RuntimeException('OpenAI subject changed.');
                $credentials['access_token'] = $fresh['access_token'];
                if (is_string($fresh['refresh_token'] ?? null) && $fresh['refresh_token'] !== '')
                    $credentials['refresh_token'] = $fresh['refresh_token'];
                if (isset($fresh['scope'])) $credentials['scope'] = $fresh['scope'];
                $credentials['expires_at'] = time()+max(1,(int)($fresh['expires_in'] ?? 3600));
                $this->writer->putSecret($organizationId,'chatgpt_plan',$key,
                    json_encode($credentials,JSON_THROW_ON_ERROR),$actorId,bin2hex(random_bytes(16)));
            } catch (\Throwable) {
                throw new LlmProviderException('chatgpt_plan',false,'ChatGPT access expired. Reconnect in settings.');
            }
        }
        if ($model === '') throw new LlmProviderException('chatgpt_plan',false,'Select an eligible model.');
        $payload = [
            'model'=>$model,
            'instructions'=>'Return only a JSON object matching the required schema.',
            'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$prompt]]]],
            'text'=>['format'=>['type'=>'json_schema','name'=>'cos_siwc_test',
                'strict'=>true,'schema'=>$schema]],
            'store'=>false, 'stream'=>true, 'max_output_tokens'=>300,
        ];
        [$status,$raw] = $this->stream($credentials['access_token'],json_encode($payload,JSON_THROW_ON_ERROR));
        if ($status < 200 || $status >= 300)
            throw new LlmProviderException('chatgpt_plan',false,'ChatGPT plan HTTP '.$status.
                ': check approval, granted scopes and model.', $status);
        $response = null;
        foreach (preg_split('/\n\n+/',str_replace("\r\n","\n",$raw)) ?: [] as $block) {
            $data=[];
            foreach (explode("\n",$block) as $line)
                if (str_starts_with($line,'data:')) $data[]=ltrim(substr($line,5));
            if ($data === []) continue;
            $event=json_decode(implode("\n",$data),true);
            if (!is_array($event)) continue;
            if (($event['type'] ?? '')==='response.completed' && is_array($event['response'] ?? null))
                $response=$event['response'];
            if (in_array($event['type'] ?? '',['response.failed','response.incomplete','error'],true))
                throw new LlmProviderException('chatgpt_plan',false,'Streaming inference failed.');
        }
        if (!is_array($response))
            throw new LlmProviderException('chatgpt_plan',true,'No response.completed SSE event.');
        $text=null;
        foreach (($response['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? '')!=='message') continue;
            foreach (($item['content'] ?? []) as $content)
                if (is_array($content) && ($content['type'] ?? '')==='output_text')
                    $text=$content['text'] ?? null;
        }
        $output=is_string($text)?json_decode($text,true):null;
        if (!is_array($output)) throw new LlmProviderException('chatgpt_plan',false,'Invalid structured JSON.');
        $usage=is_array($response['usage'] ?? null)?$response['usage']:[];
        return new StructuredLlmResponse(
            output:$output,provider:'chatgpt_plan',
            model:is_string($response['model'] ?? null)?$response['model']:$model,
            inputTokens:isset($usage['input_tokens'])?(int)$usage['input_tokens']:null,
            outputTokens:isset($usage['output_tokens'])?(int)$usage['output_tokens']:null,
            costSource:'CHATGPT_PLAN_NOT_API_BILLING',
            providerRequestId:is_string($response['id'] ?? null)?$response['id']:null,
        );
    }

    /** @return array{0:int,1:string} */
    private function stream(string $token,string $body): array
    {
        $endpoint='https://api.openai.com/v1/responses';
        if ($this->transport!==null) {
            $result=($this->transport)($endpoint,$token,$body);
            if (is_array($result) && isset($result[0],$result[1]))
                return [(int)$result[0],(string)$result[1]];
            throw new LlmProviderException('chatgpt_plan',false,'Invalid fixture transport.');
        }
        $curl=curl_init($endpoint);
        if ($curl===false) throw new LlmProviderException('chatgpt_plan',true,'Transport unavailable.');
        $received='';
        $overLimit=false;
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: text/event-stream'],
            CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_MAXREDIRS=>0,
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$received,&$overLimit): int {
                if (strlen($received)+strlen($chunk)>2000000) {$overLimit=true;return 0;}
                $received.=$chunk;
                return strlen($chunk);
            },
        ]);
        $ok=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($overLimit) throw new LlmProviderException('chatgpt_plan',false,'Streaming response too large.');
        if ($ok===false && $status===0) throw new LlmProviderException('chatgpt_plan',true,'Transport failure.');
        return [$status,$received];
    }
}
