<?php
declare(strict_types=1);

/**
 * Prevent reintroducing path filters that silently skip changes to COS source.
 * A push to main must always run the full runtime gates before deploying code.
 */
$root=dirname(__DIR__,2);
$read=static function(string $path) use ($root):string {
    $content=file_get_contents($root.'/'.$path);
    if (!is_string($content)) throw new RuntimeException('Cannot read '.$path);
    return $content;
};
$assert=static function(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
};
$runtime=$read('.github/workflows/runtime.yml');
$ci=$read('.github/workflows/ci.yml');
foreach (['Runtime'=>$runtime,'CI'=>$ci] as $name=>$content) {
    $header=explode("\npermissions:",$content,2)[0];
    $assert(
        preg_match('/^on:\\n  push:\\n    branches: \\[main\\]\\n  pull_request:\\n    branches: \\[main\\]/m',$header)===1,
        $name.' must run on every main push and on main PRs.'
    );
    $assert(preg_match('/^    paths(?:-ignore)?:/m',$header)!==1,
        $name.' must never silently skip a main commit via paths filters.'
    );
}
$assert(str_contains($runtime,"  deploy-dev:\n    name: Deploy main to AWS dev\n    needs: [runtime, federation-mysql, capital-markets-unit]"),
    'Deployment must depend on all primary Runtime acceptance jobs.');
$assert(str_contains($runtime,"if: github.event_name == 'push' && github.ref == 'refs/heads/main'"),
    'Only merged main pushes may deploy to shared COS AWS dev.');
$assert(str_contains($runtime,'environment: AWS TN2026')
    && str_contains($runtime,'group: cos-dev-deploy')
    && str_contains($runtime,'cancel-in-progress: false'),
    'Protected environment or serialized deployment must not be removed.');
$assert(str_contains($runtime,"--exclude='.env'")&&str_contains($runtime,"--exclude='.env.docker'")
    && str_contains($runtime,"--exclude='public/uploads/'")
    && str_contains($runtime,"--exclude='public/docs/'"),
    'Environment secrets, uploads and documentation must not be overwritten by code deploy.');
$assert(str_contains($runtime,'bash deploy/dev.sh')
    && str_contains($runtime,'COMPANY_OS_HEALTHCHECK_URL')
    && str_contains($runtime,'Create verifiable deployment source attestation'),
    'Full app deployment, attestation and post-deploy health verification are required.');
echo "COS all-main code auto-deployment contract PASS.\n";
