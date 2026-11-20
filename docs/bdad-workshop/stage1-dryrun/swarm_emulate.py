#!/usr/bin/env python3
"""Sequential, condensed emulation of a recorded BDAD Swarm Stage 1 run (intake -> STOP 1).

Reads an artifact directory (stream.jsonl, usage.json, *scribe.md, *dependency-tracer.md, stop1.md)
and presents it one participant at a time: who acted, what it did, what it returned, and who received it.

  python3 swarm_emulate.py <ARTIFACT_DIR>            # press Enter between steps
  python3 swarm_emulate.py <ARTIFACT_DIR> --no-pause # print all steps
  python3 swarm_emulate.py <ARTIFACT_DIR> --step N   # print only step N (1-8; 9 = usage)
"""
import argparse
import collections
import glob
import json
import os
import re
import signal
import sys

ACTOR_COLOR = {"MAIN": "\033[1;35m", "SCRIBE": "\033[1;36m", "TRACER": "\033[1;33m", "HUMAN": "\033[1;32m"}
DIM, BOLD, RED, RESET = "\033[2m", "\033[1m", "\033[31m", "\033[0m"
LABELS = {"scribe": "SCRIBE", "dependency-tracer": "TRACER"}
USE_COLOR = sys.stdout.isatty()


def c(code, text):
    return f"{code}{text}{RESET}" if USE_COLOR else text


def read(path):
    return open(path).read() if path and os.path.exists(path) else ""


def find(a, pattern):
    hits = sorted(glob.glob(os.path.join(a, pattern)))
    return hits[0] if hits else None


def section(md, title_regex, level=2):
    """Body of the first heading at `level` whose title matches, up to the next heading of same/higher level."""
    lines, out, on = md.splitlines(), [], False
    for line in lines:
        m = re.match(r"^(#{1,6}) (.*)", line)
        if m and len(m.group(1)) <= level and on:
            break
        if m and len(m.group(1)) == level and re.search(title_regex, m.group(2), re.I):
            on = True
            continue
        if on:
            out.append(line)
    return "\n".join(out).strip()


def bold_line(md, label):
    m = re.search(rf"^\*\*{label}[^*]*\*\*:?\s*(.*)$", md, re.M | re.I)
    return m.group(1).strip() if m else "(not found)"


def gherkin(md):
    m = re.search(r"```gherkin\n.*?```", md, re.S)
    return m.group(0) if m else "(no gherkin block found)"


def short(text, n=150):
    text = " ".join(str(text).replace("**", "").split())
    return text if len(text) <= n else text[: n - 1] + "…"


class Run:
    def __init__(self, a):
        self.a = a
        self.events = [json.loads(l) for l in open(os.path.join(a, "stream.jsonl")) if l.strip()]
        self.agents, self.calls, self.results, self.subs = {}, collections.defaultdict(list), {}, []
        for e in self.events:
            d = e.get("data", {})
            if e["type"] == "subagent.started":
                self.agents[e["agentId"]] = LABELS.get(d["agentName"], d["agentName"].upper())
                self.subs.append(("start", e["timestamp"], self.agents[e["agentId"]], d))
            elif e["type"] == "subagent.completed":
                self.subs.append(("done", e["timestamp"], self.agents.get(e.get("agentId"), "?"), d))
            elif e["type"] == "tool.execution_complete":
                self.results[d["toolCallId"]] = d
        for e in self.events:
            if e["type"] == "tool.execution_start":
                who = self.agents.get(e.get("agentId"), "MAIN")
                self.calls[who].append((e["timestamp"][11:19], e["data"]))
        self.usage = json.load(open(os.path.join(a, "usage.json"))) if os.path.exists(os.path.join(a, "usage.json")) else {}
        self.scribe = read(find(a, "*scribe.md"))
        self.tracer = read(find(a, "*dependency-tracer.md"))
        self.stop1 = read(os.path.join(a, "stop1.md"))

    def ok(self, call):
        return bool(self.results.get(call["toolCallId"], {}).get("success"))

    def result_text(self, call):
        r = self.results.get(call["toolCallId"], {}).get("result") or {}
        return r.get("content", "") if isinstance(r, dict) else str(r)

    def main_calls(self, names):
        return [(t, d) for t, d in self.calls["MAIN"] if d["toolName"] in names]

    def tool_summary(self, who):
        calls = self.calls[who]
        counts = collections.Counter(d["toolName"] for _, d in calls)
        failed = [d["toolName"] for _, d in calls if not self.ok(d)]
        text = ", ".join(f"{n}×{k}" if n > 1 else k for k, n in counts.most_common())
        return len(calls), text, failed


class Presenter:
    def __init__(self, total, pause, only=None):
        self.n, self.total, self.pause, self.only = 0, total, pause, only
        self.stdout, self.null = sys.stdout, open(os.devnull, "w")

    def step(self, actor, title):
        sys.stdout = self.stdout if self.only in (None, self.n + 1) else self.null
        if self.only:
            pass
        elif self.n and self.pause:
            try:
                input(c(DIM, "\n  ⏎ next step "))
            except EOFError:
                pass
        self.n += 1
        bar = "━" * 100
        print(c(ACTOR_COLOR.get(actor, BOLD), f"\n{bar}\n STEP {self.n}/{self.total}  │  {actor:<6} │  {title}\n{bar}"))

    @staticmethod
    def kv(key, value):
        print(f"  {c(BOLD, f'{key:<11}')} {value}")

    @staticmethod
    def block(text, indent="    "):
        for line in text.rstrip().splitlines():
            print(indent + line)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("artifact_dir")
    ap.add_argument("--no-pause", action="store_true")
    ap.add_argument("--step", type=int, choices=range(1, 10), metavar="N", help="print only step N (1-8; 9 = usage)")
    args = ap.parse_args()
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    r = Run(args.artifact_dir)
    p = Presenter(8, pause=not args.no_pause and sys.stdin.isatty(), only=args.step)

    # 1. Preflight
    p.step("MAIN", "Execution Model preflight")
    p.kv("Action", "Check that this session can dispatch custom agents (it must be the main session, not a subagent)")
    p.kv("Result", bold_line(r.stop1, "Preflight"))

    # 2. Ticket intake
    p.step("MAIN", "Ticket intake (Glean)")
    for t, d in r.main_calls({"glean-search", "glean-read_document"}):
        arg = d["arguments"].get("query") or d["arguments"].get("urls")
        p.kv("Call", f"{t}  {d['toolName']}  {short(arg, 90)}  {'✔' if r.ok(d) else c(RED, '✖')}")
    p.kv("Ticket", short(section(r.scribe, r"^Title") or bold_line(r.stop1, "Ticket"), 160))
    p.kv("Source", short(bold_line(r.stop1, "Ticket evidence"), 160))

    # 3. Scout freshness
    p.step("MAIN", "Scout index freshness gate")
    for t, d in r.main_calls({"scout-list_repositories", "scout-ensure_fresh"}):
        p.kv("Call", f"{t}  {d['toolName']}  {'✔' if r.ok(d) else c(RED, '✖')}")
        if d["toolName"] == "scout-ensure_fresh":
            try:
                f = json.loads(r.result_text(d))
                p.kv("Result", f"status={f.get('status')}  gap_files_reindexed={f.get('gap_files_reindexed')}  "
                               f"derived_views_verified={f.get('derived_views_verified')}")
            except (ValueError, TypeError):
                p.kv("Result", short(r.result_text(d), 160))

    # 4. Routing and dispatch
    p.step("MAIN", "Routing and dispatch")
    p.kv("Decision", short(bold_line(r.stop1, "Routing"), 300))
    for kind, ts, who, d in r.subs:
        if kind == "start":
            p.kv("Dispatch", f"{ts[11:19]}  → {who:<6} model={d.get('model')} ({d.get('modelSelectionSource')})  "
                             f"mode={d.get('executionMode')}")

    # 5. Scribe
    n, tools, failed = r.tool_summary("SCRIBE")
    done = next((d for k, _, w, d in r.subs if k == "done" and w == "SCRIBE"), {})
    p.step("SCRIBE", "Intake brief and behavior contract")
    p.kv("Input", "Ticket evidence, local triage record and Scout anchors from MAIN")
    p.kv("Process", f"{n} tool call(s): {tools}" + (c(RED, f"  failed: {', '.join(failed)}") if failed else ""))
    p.kv("Sections", " · ".join(re.findall(r"^## (.+)$", r.scribe, re.M)))
    p.kv("Domain", short(re.sub(r"(?m)^\s*[-*] ", "", section(r.scribe, r"Domain classification")), 200))
    p.kv("Contract", "")
    p.block(gherkin(r.scribe))
    p.kv("Returned", f"→ MAIN  ({done.get('durationMs', 0) / 1000:.1f} s, {done.get('totalTokens', '?')} tokens)")

    # 6. Tracer
    n, tools, failed = r.tool_summary("TRACER")
    done = next((d for k, _, w, d in r.subs if k == "done" and w == "TRACER"), {})
    p.step("TRACER", "Pre-intake root-cause trace (not MIG Phase 0)")
    p.kv("Input", "Symbol seeds, Scout freshness state and a budget of 30 tool calls / 150 lines from MAIN")
    p.kv("Process", f"{n} tool calls: {tools}")
    if failed:
        p.kv("Retries", c(RED, ", ".join(failed)) + " (transient; retried)")
    p.kv("Findings", "")
    for m in re.finditer(r"^### (.+)$", r.tracer, re.M):
        body = section(r.tracer, re.escape(m.group(1)), level=3)
        lines = [re.sub(r"^[-*] ", "", l.strip()) for l in body.splitlines() if l.strip()]
        first = " ".join(lines[:2]) if lines and (lines[0].rstrip("*").endswith(":") or len(lines[0]) < 40) else (lines or [""])[0]
        print(f"    • {c(BOLD, m.group(1))}: {short(first, 130)}")
    p.kv("Returned", f"→ MAIN  ({done.get('durationMs', 0) / 1000:.1f} s, {done.get('totalTokens', '?')} tokens)")

    # 7. Receipt and synthesis
    p.step("MAIN", "Receipt, verification and synthesis")
    order = [f"{w} ({ts[11:19]})" for k, ts, w, _ in r.subs if k == "done"]
    p.kv("Received", " then ".join(order) + "; both by MAIN only, with nothing relayed between specialists")
    saved = [os.path.basename(d["arguments"].get("path", "")) for _, d in r.main_calls({"create"})]
    p.kv("Saved", ", ".join(saved))
    notes = section(r.stop1, r"synthesis|correction|caveat")
    if notes:
        p.kv("Notes", "")
        for line in [l for l in notes.splitlines() if l.strip()][:6]:
            print("    " + short(line, 150))

    # 8. STOP 1
    p.step("HUMAN", "STOP 1: human approval gate")
    p.kv("Presented", "Intake brief (condensed), Tracer mechanism (condensed), behavior contract (verbatim)")
    p.kv("Decisions", "")
    p.block(section(r.stop1, r"Decisions requested"))
    p.kv("State", "Halted. MIG Phase 0, Test Author and later stages wait for approval.")

    sys.stdout = p.stdout if args.step in (None, 9) else p.null
    m = r.usage.get("agentMetrics", {})
    if m:
        print(c(BOLD, "\nUsage"))
        for v in m.values():
            model, mm = next(iter(v["modelMetrics"].items()))
            print(f"  {LABELS.get(v.get('agentName'), 'MAIN'):<7} {model:<18} requests={mm['requests']['count']:<3} "
                  f"AI credits={v['totalNanoAiu'] / 1e9:.2f}")
        print(f"  {'TOTAL':<7} {'':<18} {'':<12} AI credits={sum(v['totalNanoAiu'] for v in m.values()) / 1e9:.2f}")


if __name__ == "__main__":
    main()
