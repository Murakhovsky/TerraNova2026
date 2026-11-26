#!/usr/bin/env python3
"""Exercise the supervised shell boundary without a real host, Docker or Shopify."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SHA = 'a' * 40
CONNECTION = 'b' * 32
ORDER = 'gid://shopify/Order/123'
CONTAINER = 'c' * 64


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


with tempfile.TemporaryDirectory(prefix='series01-guard-') as tmp:
    fixture = Path(tmp)
    host = fixture / 'cos'
    for path in ('app/Kernel', 'app/Platform', 'app/Domains', 'app/Infrastructure', 'symfony/src', 'symfony/config'):
        (host / path).mkdir(parents=True)
    (host / 'app/Domains/example.php').write_text('<?php // fixture\n')
    (host / 'symfony/config/services.yaml').write_text('services: {}\n')
    routes = host / 'symfony/config/routes.yaml'
    routes.write_text('routes: {}\n')
    code_files = ['app/Domains/example.php', 'symfony/config/services.yaml']
    code_sha = hashlib.sha256(''.join(f'{sha(host / name)}  {name}\n' for name in code_files).encode()).hexdigest()
    routes_sha = sha(routes)
    manifest = host / '.cos-deployment-attestation.json'
    expected = dict(source_sha=SHA, execution_source_sha256=code_sha, routes_sha256=routes_sha)
    secrets = fixture / 'shopify.env'
    secrets.write_text('SHOPIFY_SHOP_DOMAIN=e2rnbw-mr.myshopify.com\nSHOPIFY_CLIENT_ID=fixture-client\nSHOPIFY_CLIENT_SECRET=fixture-secret\n')
    secrets.chmod(0o600)
    # Replace only the two fixed filesystem locations in the fixture, never
    # introduce environment-based production path/tenant overrides.
    script = fixture / 'operator.sh'
    script.write_text((ROOT / 'bin/shopify-series01-operator.sh').read_text()
                      .replace('/opt/cos', str(host)).replace('/etc/cos/shopify.env', str(secrets)))
    bindir = fixture / 'bin'
    bindir.mkdir()
    for name, content in {'id': '#!/bin/sh\necho 0\n', 'stat': '#!/bin/sh\necho 0:600\n'}.items():
        f = bindir / name
        f.write_text(content)
        f.chmod(0o755)
    calls = fixture / 'calls'
    docker = bindir / 'docker'
    docker.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
args=sys.argv[1:]
mode=os.environ['TEST_MODE']
read=Path(os.environ['TEST_CALLS'])
container=os.environ['TEST_CONTAINER']
if args[0]=='inspect':
    if '{{.Id}} {{.State.Running}}' in args:
        print(('d'*64 if mode=='replacement' and read.exists() else container)+' true')
    elif '{{.Id}}' in args: print(container)
    sys.exit(0)
assert args[0]=='exec', args
if args[1]=='-i':
    assert args[2]==container, args
    assert sys.stdin.read().splitlines()==['e2rnbw-mr.myshopify.com','fixture-client','fixture-secret']
    assert 'order-lifecycle-sandbox' in args and '--confirm=VERIFY_TEST_LIFECYCLE_READ_ONLY' in args
    read.write_text('called')
    if mode=='provider_failure': sys.exit(4)
    result=dict(status='SANDBOX_LIFECYCLE_READBACK_VERIFIED',scenario_acceptance='independent_review_required',
        organization_id='sandbox',connection_id=os.environ['TEST_CONNECTION'],order_gid=os.environ['TEST_ORDER'],test_order=True,
        inventory_authority='external_review',native_order_created=False,local_stock_mutation=False,payment_settled=False,
        provider_mutation=False,ownership_changed=False,physical_commitment_verified=False)
    if mode=='foreign_result': result['organization_id']='foreign'
    if mode=='stock_result': result['local_stock_mutation']=True
    print(json.dumps(result))
else:
    assert args[1]==container, args
    if args[2]=='sha256sum': print(os.environ['TEST_ROUTES_SHA']+'  routes.yaml')
    else:
        assert args[2]=='sh', args
        print('d'*64 if mode=='code_mismatch' or (mode=='post_code_drift' and read.exists()) else os.environ['TEST_CODE_SHA'])
''')
    docker.chmod(0o755)
    base_args = ['bash', str(script), 'sandbox', 'order-lifecycle-sandbox', CONNECTION, ORDER]
    env = dict(os.environ, PATH=f'{bindir}:{os.environ["PATH"]}', TEST_CALLS=str(calls),
               TEST_CONTAINER=CONTAINER, TEST_CONNECTION=CONNECTION, TEST_ORDER=ORDER,
               TEST_CODE_SHA=code_sha, TEST_ROUTES_SHA=routes_sha)

    cases = [('success', True, True), ('missing_sha', False, False), ('wrong_sha', False, False),
             ('wrong_manifest', False, False), ('code_mismatch', False, False),
             ('post_code_drift', False, True), ('replacement', False, True),
             ('provider_failure', False, True), ('foreign_result', False, True), ('stock_result', False, True)]
    for mode, success, provider_called in cases:
        calls.unlink(missing_ok=True)
        manifest.write_text(json.dumps({**expected, **({'source_sha': 'd'*40} if mode=='wrong_manifest' else {})}))
        args = base_args if mode=='missing_sha' else [*base_args, 'd'*40 if mode=='wrong_sha' else SHA, code_sha, routes_sha]
        result = subprocess.run(args, env={**env, 'TEST_MODE': mode}, capture_output=True, text=True, timeout=20)
        assert (result.returncode == 0) == success, (mode, result.returncode, result.stderr)
        assert calls.exists() == provider_called, (mode, result.stderr)
        assert 'fixture-secret' not in (result.stdout + result.stderr), mode
        if success:
            report = json.loads(result.stdout)
            assert report['deployment_attestation'] == {**expected, 'cli_source_verified_before_and_after': True}
            assert report['scenario_acceptance'] == 'independent_review_required'
        else:
            assert not result.stdout.strip(), (mode, result.stdout)
        print(f'SERIES01 {mode}: PASS')
