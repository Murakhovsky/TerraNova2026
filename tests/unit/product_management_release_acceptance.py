#!/usr/bin/env python3
import copy
import importlib.util
from pathlib import Path

path = Path(__file__).resolve().parents[2] / "bin/ci/product-release-acceptance.py"
spec = importlib.util.spec_from_file_location("gate", path)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
sha = "a"*40
url = "https://example.test/evidence/1"
when = gate.utc_now()
common = dict(revision_sha=sha, evidence_url=url, performed_at=when)
packet = {
    "engineering": dict(schema="product_management.engineering_acceptance.v1", domain="product_management",
                        candidate_sha=sha, tested_revision_sha=sha, status="ENGINEERING_PASS",
                        checks=list(gate.ENGINEERING_CHECKS), environment="disposable_ci",
                        evidence_url=url, performed_at=when, fixture_llm=True),
    "pipelines": [dict(name=name, tested_revision_sha=sha, conclusion="success", evidence_url=url, performed_at=when)
                  for name in gate.REQUIRED_PIPELINES],
    "deployment": dict(common, execution_source_sha256="a"*64, routes_sha256="b"*64),
    "independent_acceptance": [dict(common, stage=stage, result="PASS", scenario="native business",
                                     environment="production" if stage=="D5" else "staging",
                                     tenant="production-pm" if stage=="D5" else "staging-pm",
                                     operator="operator-1", independent_reviewer="reviewer-2",
                                     business_readback=["native:receipt"], alerting_verified=True,
                                     rollback_or_disable_verified=True) for stage in gate.SCENARIOS],
    "live_ai": dict(common, tenant="staging-pm", environment="staging", status="NATIVE_AI_READBACK_PASS",
                    intent_id="intent-1", goal_id="goal-1", plan_id="plan-1", run_id="run-1",
                    evaluation_id="eval-1", llm_usage_id="b"*32, provider="provider", model="model",
                    business_readback=["native:receipt"], stock_reconciliation="OK"),
}
out = gate.validate(packet, sha, False)
assert out["status"] == "EVIDENCE_COMPLETE_FOR_INDEPENDENT_REVIEW" and not out["release_authorized"], out
assert gate.validate(packet, sha, True)["status"] == "BLOCKED"
for path, value in [
    (("engineering", "tested_revision_sha"), "b"*40),
    (("pipelines", 0, "conclusion"), "failure"),
    (("deployment", "routes_sha256"), "invalid"),
    (("live_ai", "provider"), "fixture"),
    (("live_ai", "status"), "PENDING_INDEPENDENT_REVIEW"),
    (("live_ai", "tenant"), "foreign"),
    (("live_ai", "business_readback"), []),
    (("independent_acceptance", 1, "independent_reviewer"), "operator-1"),
    (("independent_acceptance", 2, "rollback_or_disable_verified"), False),
    (("independent_acceptance", 2, "evidence_url"), "http://example.test"),
    (("deployment", "revision_sha"), "b"*40),
]:
    bad = copy.deepcopy(packet)
    node = bad
    for key in path[:-1]:
        node = node[key]
    node[path[-1]] = value
    assert gate.validate(bad, sha, False)["status"] == "BLOCKED", path
assert gate.validate({}, sha, False)["status"] == "BLOCKED"
print("Product Management P7 exact-revision independent-evidence gate PASS (synthetic records only).")
