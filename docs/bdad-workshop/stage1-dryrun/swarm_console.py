#!/usr/bin/env python3
"""Readable console for a BDAD Swarm run streamed by `copilot --output-format json`.

Live:    copilot ... --output-format json | tee "$A/stream.jsonl" | python3 swarm_console.py
Replay:  python3 swarm_console.py --replay "$A/stream.jsonl" --speed 10
"""
import argparse
import json
import os
import signal
import sys
import time
from datetime import datetime

COLORS = {"MAIN": "\033[1;35m", "SCRIBE": "\033[1;36m", "TRACER": "\033[1;33m", "TEST-AUTHOR": "\033[1;34m", "PATCH-GEN": "\033[1;32m", "GATEWAY": "\033[1;37m"}
DIM, BOLD, RED, GREEN, RESET = "\033[2m", "\033[1m", "\033[31m", "\033[32m", "\033[0m"
LABELS = {"scribe": "SCRIBE", "dependency-tracer": "TRACER", "test-author": "TEST-AUTHOR",
          "patch-generator": "PATCH-GEN", "consensus-gateway": "GATEWAY"}
ARG_KEYS = ("query", "queries", "pattern", "path", "url", "urls", "command", "symbols", "targets", "file_path")
STRIP = [os.getcwd().rstrip("/") + "/", os.environ.get("A", "/nonexistent").rstrip("/") + "/"]


class Console:
    def __init__(self, max_lines, color):
        self.max_lines = max_lines
        self.color = color
        self.agents = {}       # agentId -> label
        self.calls = {}        # toolCallId -> (label, toolName)
        self.counts = {}       # label -> tool calls
        self.task_agent = {}   # task toolCallId -> label

    def c(self, code, text):
        return f"{code}{text}{RESET}" if self.color else text

    def label(self, event):
        return self.agents.get(event.get("agentId"), "MAIN")

    def line(self, ts, label, text):
        tag = self.c(COLORS.get(label, BOLD), f"[{label:<7}]")
        print(f"{self.c(DIM, ts)} {tag} {text}", flush=True)

    def block(self, title, body, label):
        rule = "═" * 100
        print(self.c(COLORS.get(label, BOLD), f"\n{rule}\n  {title}\n{rule}"), flush=True)
        lines = body.rstrip().splitlines()
        shown = lines if self.max_lines <= 0 else lines[: self.max_lines]
        print("\n".join(shown), flush=True)
        if len(shown) < len(lines):
            print(self.c(DIM, f"  … {len(lines) - len(shown)} more lines (full text in the saved artifact)"))
        print(self.c(COLORS.get(label, BOLD), rule + "\n"), flush=True)

    @staticmethod
    def brief_args(args):
        if not isinstance(args, dict):
            return ""
        parts = []
        for lane in args.get("scoped_queries") or []:
            if isinstance(lane, dict):
                q = lane.get("query") or lane.get("queries")
                parts.append("q=" + (", ".join(q) if isinstance(q, list) else str(q)))
        for key in ARG_KEYS:
            if key in args:
                value = args[key]
                value = json.dumps(value) if not isinstance(value, str) else value
                value = " ".join(value.split())
                for prefix in STRIP:
                    value = value.replace(prefix, "")
                parts.append(f"{key}={value[:90]}{'…' if len(value) > 90 else ''}")
        if not parts and args:
            key = next((k for k in args if not k.startswith("_")), None)
            if key:
                parts.append(f"{key}={' '.join(str(args[key]).split())[:90]}")
        return "  ".join(parts)

    def handle(self, e):
        t, d = e.get("type", ""), e.get("data", {})
        ts = e.get("timestamp", "")[11:19]
        if t == "session.start":
            print(self.c(BOLD, f"\nCopilot CLI {d.get('copilotVersion', '?')} — session {d.get('sessionId', '')[:8]}"))
        elif t == "session.mcp_server_status_changed" and d.get("status") == "connected":
            self.line(ts, "MAIN", self.c(DIM, f"MCP server connected: {d.get('serverName')}"))
        elif t == "subagent.started":
            label = LABELS.get(d.get("agentName"), d.get("agentName", "AGENT").upper())
            self.agents[e.get("agentId")] = label
            self.task_agent[d.get("toolCallId")] = label
            self.line(ts, "MAIN", self.c(BOLD, f"⇒ DISPATCH {label}  model={d.get('model')} "
                                               f"({d.get('modelSelectionSource')})  mode={d.get('executionMode')}"))
        elif t == "subagent.selected":
            self.line(ts, self.label(e), self.c(DIM, f"tools allowed: {', '.join(d.get('tools', []))}"))
        elif t == "subagent.completed":
            label = self.label(e)
            self.line(ts, label, self.c(BOLD, f"⇐ RETURNED to MAIN  model={d.get('model')}  "
                                             f"tool calls={d.get('totalToolCalls')}  "
                                             f"{d.get('durationMs', 0) / 1000:.1f}s  tokens={d.get('totalTokens')}"))
        elif t == "tool.execution_start":
            label = self.label(e)
            name = d.get("toolName", "?")
            self.calls[d.get("toolCallId")] = (label, name)
            self.counts[label] = self.counts.get(label, 0) + 1
            if name == "task":
                args = d.get("arguments", {})
                self.block(f"BRIEF  MAIN → {args.get('agent_type', '?').upper()}  ({args.get('mode', 'sync')})",
                           args.get("prompt", ""), "MAIN")
            else:
                self.line(ts, label, f"▶ {name:<26} {self.brief_args(d.get('arguments'))}")
        elif t == "tool.execution_complete":
            label, name = self.calls.get(d.get("toolCallId"), (self.label(e), "?"))
            if d.get("success"):
                if name == "task":
                    who = self.task_agent.get(d.get("toolCallId"), "AGENT")
                    content = (d.get("result") or {}).get("content", "")
                    self.block(f"OUTPUT  {who} → MAIN", content, who)
                else:
                    self.line(ts, label, self.c(GREEN, f"  ✔ {name}"))
            else:
                err = d.get("error") or {}
                msg = err.get("message", str(err)) if isinstance(err, dict) else str(err)
                self.line(ts, label, self.c(RED, f"  ✖ {name}: {' '.join(msg.split())[:160]}"))
        elif t == "assistant.message":
            text = (d.get("content") or "").strip()
            if text:
                label = self.label(e)
                if label == "MAIN" and not d.get("toolRequests"):
                    self.block("MAIN — final message", text, "MAIN")
                else:
                    first = text.splitlines()
                    self.line(ts, label, "💬 " + first[0][:150] + (" …" if len(first) > 1 else ""))
        elif t == "result":
            u = e.get("usage", {})
            print(self.c(BOLD, f"\nRESULT exit={e.get('exitCode')}  premium requests={u.get('premiumRequests')}  "
                               f"session={u.get('sessionDurationMs', 0) / 1000:.0f}s"))
            print("Tool calls per participant: " + ", ".join(f"{k}={v}" for k, v in self.counts.items()))


def main():
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--replay", metavar="STREAM_JSONL", help="replay a recorded stream instead of reading stdin")
    ap.add_argument("--speed", type=float, default=10.0, help="replay speed multiplier (0 = no delay)")
    ap.add_argument("--max-lines", type=int, default=0, help="cap lines per brief/output block (0 = full)")
    ap.add_argument("--no-color", action="store_true")
    a = ap.parse_args()
    con = Console(a.max_lines, color=not a.no_color and sys.stdout.isatty())
    src = open(a.replay) if a.replay else sys.stdin
    prev = None
    for raw in src:
        raw = raw.strip()
        if not raw:
            continue
        try:
            e = json.loads(raw)
        except json.JSONDecodeError:
            continue
        if e.get("type", "").endswith("_delta"):
            continue
        if a.replay and a.speed > 0 and e.get("timestamp"):
            now = datetime.fromisoformat(e["timestamp"].replace("Z", "+00:00"))
            if prev:
                time.sleep(min(max((now - prev).total_seconds() / a.speed, 0), 3))
            prev = now
        con.handle(e)


if __name__ == "__main__":
    try:
        main()
    except (BrokenPipeError, KeyboardInterrupt):
        pass
