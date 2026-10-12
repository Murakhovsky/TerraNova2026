<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use Domains\CapitalMarkets\Application\Contract\StreamingWorkerTransportInterface;
use RuntimeException;

/**
 * Minimal RFC6455 TLS client for public JSON market streams.
 * Provider command encoding and raw-event decoding live outside this class.
 * No auto-trust and no credentials in URLs.
 */
final class NativeWebSocketTransport implements StreamingWorkerTransportInterface
{
    /** @var resource|null */
    private $socket=null;
    private string $buffer='';
    private ?int $epoch=null;
    private ?string $pendingFragment=null;

    /**
     * @param callable(list<string>,int):list<string> $subscribeFrames
     * @param callable(string,int):void $onMessage
     */
    public function __construct(
        private readonly string $url,
        private readonly \Closure $subscribeFrames,
        private readonly \Closure $onMessage,
        private readonly int $maxMessageBytes=2097152,
        private readonly ?\Closure $onControl=null,
    ){
        $parsed=parse_url($url);
        if(!is_array($parsed)||($parsed['scheme']??'')!=='wss'
            ||!isset($parsed['host'])||isset($parsed['user'])||isset($parsed['pass'])
            ||isset($parsed['fragment'])||$maxMessageBytes<1024){
            throw new RuntimeException('Invalid secure WebSocket endpoint.');
        }
        if(filter_var($parsed['host'],FILTER_VALIDATE_IP)){
            throw new RuntimeException('WebSocket endpoint must use an approved DNS hostname.');
        }
    }

    public function open(string $organizationId,string $sourceId,int $generation):void
    {
        $this->close();
        $parts=parse_url($this->url);
        $host=(string)$parts['host'];
        $port=(int)($parts['port']??443);
        if($port!==443)throw new RuntimeException('Public WebSocket transport requires port 443.');
        $path=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
        $context=stream_context_create(['ssl'=>[
            'verify_peer'=>true,'verify_peer_name'=>true,
            'peer_name'=>$host,'SNI_enabled'=>true,
        ]]);
        $socket=@stream_socket_client('tls://'.$host.':'.$port,$errno,$error,8,STREAM_CLIENT_CONNECT,$context);
        if(!is_resource($socket))throw new RuntimeException('WebSocket TLS connection failed.');
        stream_set_timeout($socket,8);
        $nonce=base64_encode(random_bytes(16));
        $request="GET ".$path." HTTP/1.1\r\nHost: ".$host."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$nonce."\r\nSec-WebSocket-Version: 13\r\n\r\n";
        if(fwrite($socket,$request)!==strlen($request)){fclose($socket);throw new RuntimeException('WebSocket handshake write failed.');}
        $header='';
        while(!str_contains($header,"\r\n\r\n") && strlen($header)<16384){
            $chunk=fread($socket,1024);
            if($chunk===false||$chunk===''){fclose($socket);throw new RuntimeException('WebSocket handshake timed out.');}
            $header.=$chunk;
        }
        [$headers,$remaining]=explode("\r\n\r\n",$header,2)+['',''];
        if(!preg_match('~^HTTP/1\\.[01] 101\\b~',$headers)
            ||!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)\s*$/mi',$headers,$matches)
            ||!hash_equals(base64_encode(sha1($nonce.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true)),trim($matches[1]))){
            fclose($socket);throw new RuntimeException('WebSocket handshake rejected.');
        }
        stream_set_blocking($socket,false);
        $this->socket=$socket;
        $this->buffer=$remaining;
        $this->epoch=$generation;
    }

    /** Public feed heartbeat, used by Bybit JSON-level ping. */
    public function sendText(string $payload,int $generation):void
    {
        $this->assertEpoch($generation);
        if(strlen($payload)>4096)throw new RuntimeException('Provider control message too large.');
        $this->send(0x1,$payload);
    }

    public function subscribe(array $keys,int $generation):void
    {
        $this->assertEpoch($generation);
        foreach(($this->subscribeFrames)($keys,$generation) as $payload){
            if(!is_string($payload))throw new RuntimeException('Invalid provider subscription frame.');
            $this->send(0x1,$payload);
        }
    }

    public function pump(int $generation,int $timeoutMilliseconds):void
    {
        $this->assertEpoch($generation);
        if($timeoutMilliseconds<0||$timeoutMilliseconds>10000)throw new RuntimeException('Invalid WebSocket poll interval.');
        $read=[$this->socket];$write=[];$except=[];
        $ready=@stream_select($read,$write,$except,intdiv($timeoutMilliseconds,1000),($timeoutMilliseconds%1000)*1000);
        if($ready===false)throw new RuntimeException('WebSocket select failed.');
        if($ready>0){
            $chunk=fread($this->socket,65536);
            if($chunk===false||($chunk===''&&feof($this->socket)))throw new RuntimeException('WebSocket peer disconnected.');
            $this->buffer.=$chunk;
        }
        while($this->readFrame($generation)){}
    }

    public function close():void
    {
        if(is_resource($this->socket))fclose($this->socket);
        $this->socket=null;$this->epoch=null;$this->buffer='';$this->pendingFragment=null;
    }

    private function readFrame(int $generation):bool
    {
        $size=strlen($this->buffer);
        if($size<2)return false;
        $first=ord($this->buffer[0]);$second=ord($this->buffer[1]);
        if(($first&0x70)!==0||($second&0x80)!==0)throw new RuntimeException('Invalid server WebSocket frame.');
        $opcode=$first&0x0f;$length=$second&0x7f;$offset=2;
        if($length===126){
            if($size<4)return false;
            $length=unpack('n',substr($this->buffer,2,2))[1];$offset=4;
        }elseif($length===127){
            if($size<10)return false;
            $pair=unpack('Nhi/Nlo',substr($this->buffer,2,8));
            if($pair['hi']!==0)throw new RuntimeException('WebSocket frame too large.');
            $length=$pair['lo'];$offset=10;
        }
        if($length>$this->maxMessageBytes||$size>$this->maxMessageBytes+65536)throw new RuntimeException('WebSocket buffer limit exceeded.');
        if($size<$offset+$length)return false;
        $payload=substr($this->buffer,$offset,$length);
        $this->buffer=substr($this->buffer,$offset+$length);
        $fin=($first&0x80)!==0;
        if($opcode>=8){
            if(!$fin||$length>125)throw new RuntimeException('Invalid control frame.');
            if($opcode===8)throw new RuntimeException('WebSocket server closed connection.');
            if($opcode===9){
                $this->send(0xA,$payload);
                if($this->onControl!==null)($this->onControl)('PING',$generation);
            }
            if($opcode===10 && $this->onControl!==null)($this->onControl)('PONG',$generation);
            return true;
        }
        if($opcode===1){
            if($this->pendingFragment!==null)throw new RuntimeException('Interleaved WebSocket messages.');
            if($fin)($this->onMessage)($payload,$generation);
            else $this->pendingFragment=$payload;
        }elseif($opcode===0 && $this->pendingFragment!==null){
            $this->pendingFragment.=$payload;
            if(strlen($this->pendingFragment)>$this->maxMessageBytes)throw new RuntimeException('Fragment limit exceeded.');
            if($fin){
                ($this->onMessage)($this->pendingFragment,$generation);
                $this->pendingFragment=null;
            }
        }else throw new RuntimeException('Unsupported WebSocket opcode.');
        return true;
    }

    private function send(int $opcode,string $data):void
    {
        if(!is_resource($this->socket))throw new RuntimeException('WebSocket transport not connected.');
        $length=strlen($data);
        if($length>$this->maxMessageBytes)throw new RuntimeException('Outgoing WebSocket frame too large.');
        $header=chr(0x80|$opcode);
        $header.=$length<126?chr(0x80|$length):($length<=65535?chr(0x80|126).pack('n',$length):chr(0x80|127).pack('NN',0,$length));
        $mask=random_bytes(4);$masked='';
        for($i=0;$i<$length;$i++)$masked.=$data[$i]^$mask[$i%4];
        $frame=$header.$mask.$masked;
        $written=0;
        while($written<strlen($frame)){
            $n=@fwrite($this->socket,substr($frame,$written));
            if($n===false||$n===0)throw new RuntimeException('WebSocket write failed.');
            $written+=$n;
        }
    }

    private function assertEpoch(int $generation):void
    {
        if(!is_resource($this->socket)||$generation!==$this->epoch)throw new RuntimeException('Disconnected or stale WebSocket session.');
    }
}
