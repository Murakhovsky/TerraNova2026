#!/usr/bin/env python3
"""Fail-safe GitHub Actions step-duration report, including action-based Docker builds.

Uses the GitHub Actions REST jobs endpoint so setup actions, Docker Buildx,
Composer, MySQL and E2E are measured consistently without shell wrapping.
Read-only; errors affect observability, never the acceptance result.
"""
from __future__ import annotations

import datetime as dt
import json
import os
import pathlib
import sys
import urllib.error
import urllib.request

def duration(start: str | None, end: str | None) -> float | None:
    if not start or not end:
        return None
    try:
        a = dt.datetime.fromisoformat(start.replace("Z", "+00:00"))
        b = dt.datetime.fromisoformat(end.replace("Z", "+00:00"))
        return max(0.0, (b - a).total_seconds())
    except ValueError:
        return None

def report() -> None:
    repo = os.environ["GITHUB_REPOSITORY"]
    run_id = os.environ["GITHUB_RUN_ID"]
    job_name = os.environ["CI_TIMING_JOB"]
    token = os.environ["GITHUB_TOKEN"]
    url = f"https://api.github.com/repos/{repo}/actions/runs/{run_id}/jobs?per_page=100"
    jobs = []
    for page in range(1, 11):
        request = urllib.request.Request(
            f"{url}&page={page}",
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": f"Bearer {token}",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "cos-ci-performance-report",
            },
        )
        with urllib.request.urlopen(request, timeout=20) as response:
            payload = json.load(response)
        jobs.extend(payload.get("jobs", []))
        if len(payload.get("jobs", [])) < 100:
            break
    matching = [job for job in jobs if job.get("name") == job_name]
    if len(matching) != 1:
        raise RuntimeError(f"Expected one running job named {job_name!r}; found {len(matching)}")
    job = matching[0]
    steps = []
    for step in job.get("steps", []):
        elapsed = duration(step.get("started_at"), step.get("completed_at"))
        if elapsed is not None:
            steps.append({
                "name": step["name"],
                "seconds": round(elapsed, 2),
                "conclusion": step.get("conclusion") or step.get("status") or "unknown",
                "number": step["number"],
            })
    if not steps:
        raise RuntimeError("No completed step timestamps available yet")
    output = {
        "job": job_name,
        "run_id": run_id,
        "head_sha": job.get("head_sha") or os.environ.get("GITHUB_SHA"),
        "timed_steps": steps,
        "measured_seconds": round(sum(s["seconds"] for s in steps), 2),
        "note": "Includes finished steps only; the timing-report and upload steps are not yet complete.",
    }
    dest = pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "cos-ci-timings.json"
    dest.write_text(json.dumps(output, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        f"### Performance: {job_name}",
        f"Commit: \`{output['head_sha']}\`, run: \`{run_id}\`",
        "",
        "| Stage | Seconds | Result |",
        "|---|---:|---|",
    ]
    for item in steps:
        name = item["name"].replace("|", "\\|").replace("\n", " ")
        lines.append(f"| {name} | {item['seconds']:.1f} | {item['conclusion']} |")
    lines.extend(["", f"**Measured finished stages:** {output['measured_seconds']:.1f}s", ""])
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
        summary.write("\n".join(lines))

if __name__ == "__main__":
    try:
        report()
    except (ValueError, KeyError, OSError, RuntimeError, urllib.error.URLError) as exc:
        print(f"::warning::CI timing report unavailable: {exc}", file=sys.stderr)
        sys.exit(0)
