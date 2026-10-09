<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavSourceDocumentVerifier;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
};
$expectRejection=static function(callable $callback,string $message):void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
};

$path=tempnam(sys_get_temp_dir(),'cm-nav-evidence-');
if (!is_string($path)) throw new RuntimeException('Unable to create test fixture.');
$link=$path.'.lnk';
try {
    file_put_contents($path,"independent statement\naccount: test\nUSD 10.25\n");
    $digest=hash_file('sha256',$path);
    $result=PortfolioNavSourceDocumentVerifier::verify($path,$digest);
    $assert($result['verified_bytes']===true,'Original statement bytes must be checked.');
    $assert($result['sha256']===$digest,'Statement SHA-256 must match the actual file.');
    $assert($result['source_authenticated']===false && $result['reconciled']===false,
        'Matching bytes must never certify independent authority or account reconciliation.');
    $expectRejection(
        static fn()=>PortfolioNavSourceDocumentVerifier::verify($path,str_repeat('a',64)),
        'A forged checksum must fail closed.',
    );
    $expectRejection(
        static fn()=>PortfolioNavSourceDocumentVerifier::verify($path,'not-a-sha'),
        'Invalid checksum syntax must fail closed.',
    );
    $expectRejection(
        static fn()=>PortfolioNavSourceDocumentVerifier::verify($path.'.missing',$digest),
        'Missing original document must fail closed.',
    );
    if (function_exists('symlink') && @symlink($path,$link)) {
        $expectRejection(
            static fn()=>PortfolioNavSourceDocumentVerifier::verify($link,$digest),
            'Symbolic links must not bypass source document validation.',
        );
    }
    file_put_contents($path,'');
    $expectRejection(
        static fn()=>PortfolioNavSourceDocumentVerifier::verify($path,hash_file('sha256',$path)),
        'Empty documents must not be accepted.',
    );
} finally {
    if (is_link($link)) unlink($link);
    if (is_file($path)) unlink($path);
}
echo "Capital Markets source document checksum policy passed.\n";
