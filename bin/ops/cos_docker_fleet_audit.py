#!/usr/bin/env python3
"""Read-only Docker fleet inventory. Never prints environment values or raw logs."""
import argparse
import json
import re
import subprocess
import sys

WORKER_MARKERS = ("worker", "scheduler", "stream", "sweeper")
ERROR_PATTERNS = [
    ("oom", re.compile(r"out of memory|oomkiller|oom.killed|allowed memory size", re.I)),
    ("database", re.compile(r"SQLSTATE|PDOException|mysqli?_connect|connection refused.*mysql", re.I)),
    ("redis", re.compile(r"RedisException|redis.*connection refused|NOAUTH Authentication", re.I)),
    ("fatal", re.compile(r"PHP Fatal error|uncaught .*Exception|critical.*exception", re.I)),
    ("permission", re.compile(r"permission denied|access denied", re.I)),
]

def call_docker(args):
    proc = subprocess.run(["docker", *args], stdout=subprocess.PIPE,
                          stderr=subprocess.DEVNULL, text=True, timeout=25, check=False)
    if proc.returncode:
        raise RuntimeError("Docker inspection unavailable (no changes performed)")
    return proc.stdout

def container_items(records):
    items = []
    for row in records:
        cfg = row.get("Config") or {}
        labels = cfg.get("Labels") or {}
        project = labels.get("com.docker.compose.project")
        if not project:
            continue
        state = row.get("State") or {}
        name = str(row.get("Name", "")).lstrip("/")
        service = labels.get("com.docker.compose.service") or "unknown"
        mounts = {m.get("Name") for m in row.get("Mounts", [])
                  if m.get("Type") == "volume" and m.get("Name")}
        networks = set((row.get("NetworkSettings") or {}).get("Networks") or {})
        env = dict(e.split("=", 1) for e in cfg.get("Env", []) if "=" in e)
        items.append(dict(name=name, project=project, service=service,
                          running=state.get("Running") is True,
                          restarting=state.get("Restarting") is True or
                          state.get("Status") == "restarting",
                          exit_code=state.get("ExitCode"),
                          oom=state.get("OOMKilled") is True,
                          restarts=int(row.get("RestartCount") or 0),
                          is_worker=any(x in service for x in WORKER_MARKERS),
                          mounts=mounts, networks=networks,
                          db=env.get("DATABASE_URL"), queue=env.get("MESSENGER_TRANSPORT_DSN")))
    return sorted(items, key=lambda x: x["name"])

def classify_logs(raw):
    return [label for label, pattern in ERROR_PATTERNS if pattern.search(raw)]

def assess(records, logs=None):
    items = container_items(records)
    groups = sorted({c["project"] for c in items})
    blockers, findings = [], []
    if not items:
        blockers.append("no_compose_containers")
    if len(groups) > 1:
        findings.append("multiple_compose_projects_require_authority_review")
    worker_projects = {c["project"] for c in items if c["running"] and c["is_worker"]}
    if len(worker_projects) > 1:
        findings.append("workers_active_across_compose_projects")
    for c in items:
        if c["is_worker"] and (c["restarting"] or c["oom"] or (not c["running"])):
            blockers.append("unhealthy_execution_consumer:" + c["name"])
    for i, a in enumerate(items):
        for b in items[i + 1:]:
            if a["project"] == b["project"]:
                continue
            if a["mounts"] & b["mounts"] and (
                a["service"] in ("mysql", "redis") or b["service"] in ("mysql", "redis")
            ):
                blockers.append("cross_project_persistence_volume_collision")
            if (a["running"] and b["running"] and a["is_worker"] and b["is_worker"] and
                a["networks"] & b["networks"] and (
                    (a["db"] and a["db"] == b["db"]) or
                    (a["queue"] and a["queue"] == b["queue"])
                )):
                blockers.append("cross_project_active_writer_endpoint_collision")
    summaries = []
    for c in items:
        entry = dict(name=c["name"], project=c["project"], service=c["service"],
                     state="restarting" if c["restarting"] else
                           "running" if c["running"] else "stopped",
                     restarts=c["restarts"], oom_killed=c["oom"])
        if c["is_worker"] and not c["running"]:
            entry["exit_code"] = c["exit_code"]
        if logs and c["name"] in logs:
            entry["diagnostic_categories"] = classify_logs(logs[c["name"]])
        summaries.append(entry)
    return dict(status="BLOCKED" if blockers else "REVIEW" if findings else "PASS",
                read_only=True, no_side_effects=True, project_count=len(groups),
                projects=groups, blockers=sorted(set(blockers)),
                findings=sorted(set(findings)), containers=summaries,
                live_authority_verified=False, staging_ready=False)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixture", help="Read Docker-inspect fixture; no Docker calls")
    parser.add_argument("--inspect-logs", action="store_true",
                        help="Classify worker log patterns; never print raw logs")
    args = parser.parse_args()
    try:
        if args.fixture:
            with open(args.fixture, encoding="utf-8") as inp:
                records = json.load(inp)
        else:
            ids = call_docker(["ps", "-aq", "--filter", "label=com.docker.compose.project"]).split()
            records = json.loads(call_docker(["inspect", *ids])) if ids else []
        logs = {}
        if not args.fixture and args.inspect_logs:
            for c in container_items(records):
                if c["is_worker"] and (c["restarting"] or not c["running"] or c["oom"]):
                    try:
                        logs[c["name"]] = call_docker(["logs", "--tail", "100", c["name"]])
                    except (RuntimeError, subprocess.TimeoutExpired):
                        logs[c["name"]] = ""
        report = assess(records, logs)
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired):
        report = dict(status="BLOCKED", read_only=True, no_side_effects=True,
                      blockers=["inventory_unavailable"], findings=[], containers=[],
                      live_authority_verified=False, staging_ready=False)
    print(json.dumps(report, sort_keys=True, indent=2))
    return 0 if report["status"] == "PASS" else 2

if __name__ == "__main__":
    sys.exit(main())
