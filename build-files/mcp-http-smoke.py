#!/usr/bin/env python3
"""Roomie MCP HTTP smoke. Requires Python 3, PHP CLI and PDO SQLite."""
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import build_opener, ProxyHandler, Request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
TOKEN = secrets.token_hex(32)
VERSION = "2025-11-25"
MODERN = "2026-07-28"
ACCEPT = "application/json, text/event-stream"
opener = build_opener(ProxyHandler({}))


def request(url, body=None, *, key=TOKEN, protocol=None, headers=None, method="POST"):
    send_headers = {"Accept": ACCEPT, "Content-Type": "application/json"}
    if key is not None:
        send_headers["Authorization"] = f"Bearer {key}"
    if protocol is not None:
        send_headers["MCP-Protocol-Version"] = protocol
    send_headers.update(headers or {})
    payload = json.dumps(body).encode() if body is not None else None
    try:
        res = opener.open(Request(url, data=payload, headers=send_headers, method=method), timeout=4)
    except HTTPError as err:
        res = err
    with res:
        raw = res.read()
        return res.status, res.headers, json.loads(raw) if raw else None


def rpc(method, params=None, case_id=1):
    body = {"jsonrpc": "2.0", "method": method}
    if params is not None:
        body["params"] = params
    if case_id is not None:
        body["id"] = case_id
    return body


def modern(url, method, params=None):
    args = dict(params or {})
    args["_meta"] = {
        "io.modelcontextprotocol/protocolVersion": MODERN,
        "io.modelcontextprotocol/clientCapabilities": {},
    }
    headers = {"Mcp-Method": method}
    if method == "tools/call":
        headers["Mcp-Name"] = args["name"]
    return request(url, rpc(method, args), protocol=MODERN, headers=headers)


def booking(url, args):
    return request(url, rpc("tools/call", {"name": "list_room_bookings", "arguments": args}), protocol=VERSION)


def ready(url, process):
    for _ in range(60):
        assert process.poll() is None, "Local PHP HTTP service exited"
        try:
            return request(url, rpc("ping"), key=None)
        except URLError:
            time.sleep(0.05)
    raise AssertionError("Local PHP HTTP service did not start")


def serve(env, temp):
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    proc = subprocess.Popen(
        [PHP, "-d", "display_errors=0", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
        cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=temp,
    )
    return proc, f"http://127.0.0.1:{port}/mcp.php"


def stop(proc):
    proc.terminate()
    proc.wait(timeout=5)


def main():
    with tempfile.TemporaryDirectory(prefix="roomie-mcp-") as temp:
        path = Path(temp) / "test.sqlite"
        env = dict(os.environ, ROOMIE_DB_PATH=str(path), ROOMIE_DOCUMENT_ROOT=str(ROOT),
                   ROOMIE_ADMIN_NAME="Synthetic Admin", ROOMIE_ADMIN_EMAIL="admin@example.test",
                   ROOMIE_MCP_API_KEY=TOKEN, ROOMIE_MCP_USER_ID="2")
        env.pop("ROOMIE_MCP_ORIGIN", None)
        env["ROOMIE_ADMIN_PASSWORD_HASH"] = subprocess.check_output(
            [PHP, "-r", "echo password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);"],
            text=True,
        )
        subprocess.run([PHP, str(ROOT / "init_db.php")], cwd=ROOT, env=env, check=True,
                       stdout=subprocess.DEVNULL)
        with sqlite3.connect(path) as db:
            db.execute("INSERT INTO users (id, name, email, password, is_admin) VALUES (2, 'Connector account', 'mcp@example.test', '!', 0)")
            db.executemany("INSERT INTO rooms (id, name, type) VALUES (?, ?, ?)", [
                (1, "Visible Desk", "shared-desk"), (2, "Private Office", "fk-office"),
                (3, "Granted Office", "fk-office"),
            ])
            db.execute("INSERT INTO permissions (user_id, room_id) VALUES (2, 3)")
            db.execute("INSERT INTO seats (id, room_id, top, left_pos) VALUES (1, 1, '0', '0')")
            db.executemany(
                "INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES (1, ?, ?, ?, ?, ?)",
                [
                    (1, None, "2026-11-02", "09:00", "10:00"),
                    (None, 1, "2026-11-03", "10:30", "11:00"),
                    (2, None, "2026-11-03", "09:00", "12:00"),
                    (3, None, "2026-11-03", "09:00", "12:00"),
                    (1, None, "2026-11-04", "09:00", "10:00"),
                ],
            )
        starts = {"from": "2026-11-02", "to": "2026-11-03"}
        with open(Path(temp) / "php.log", "w+") as log:
            process, url = serve(dict(env, ROOMIE_MCP_API_KEY=""), log)
            try:
                assert ready(url, process)[0] in (401, 503)
                assert request(url, rpc("tools/list"))[0] in (401, 503)
            finally:
                stop(process)
            process, url = serve(env, log)
            try:
                assert ready(url, process)[0] == 401
                assert request(url, rpc("tools/list"), key=secrets.token_hex(32))[0] == 401
                assert request(url, rpc("tools/list"), headers={"Origin": "https://example.test"})[0] == 403
                assert request(url, method="GET")[0] == 405
                assert request(url, method="DELETE")[0] == 405
                status, hdr, body = request(url, rpc("initialize", {
                    "protocolVersion": VERSION, "capabilities": {},
                    "clientInfo": {"name": "Roomie smoke", "version": "1"},
                }))
                assert status == 200 and body["result"]["protocolVersion"] == VERSION
                assert "no-store" in hdr["Cache-Control"] and "Mcp-Session-Id" not in hdr
                status, _, body = request(url, rpc("notifications/initialized", case_id=None), protocol=VERSION)
                assert status == 202 and body is None
                assert request(url, rpc("tools/list"), protocol="2099-01-01")[0] == 400
                status, _, body = request(url, rpc("tools/list"), protocol=VERSION)
                assert status == 200 and [x["name"] for x in body["result"]["tools"]] == ["list_room_bookings"]
                status, _, body = booking(url, starts)
                assert status == 200, body
                data = body["result"]["structuredContent"]
                assert data["timezone"] == "Europe/Berlin" and not data["truncated"]
                assert len(data["bookings"]) == 3 and {row["room_id"] for row in data["bookings"]} == {1, 3}
                for row in data["bookings"]:
                    assert set(row) == {"room_id", "room_name", "date", "start_time", "end_time"}
                assert "Private Office" not in json.dumps(body) and "example.test" not in json.dumps(body)
                for hidden in (2, 999):
                    assert booking(url, dict(starts, room_id=hidden))[2]["result"]["structuredContent"]["bookings"] == []
                small = booking(url, dict(starts, limit=1))[2]["result"]["structuredContent"]
                assert small["truncated"] and len(small["bookings"]) == 1
                bad = booking(url, dict(starts, to="2026-12-03"))[2]
                assert bad.get("error") or bad["result"]["isError"]
                status, _, body = modern(url, "server/discover")
                assert status == 200 and MODERN in body["result"]["supportedVersions"]
                assert modern(url, "tools/list")[2]["result"]["tools"][0]["name"] == "list_room_bookings"
                status, _, body = modern(url, "tools/call", {"name": "list_room_bookings", "arguments": starts})
                assert status == 200 and body["result"]["structuredContent"] == data
                assert request(url, rpc("tools/list"), protocol=MODERN)[0] == 400
                with sqlite3.connect(path) as db:
                    db.execute("UPDATE users SET is_admin=1 WHERE id=2")
                body = booking(url, starts)[2]
                assert body.get("error") or body["result"]["isError"]
            finally:
                stop(process)
    print("MCP HTTP: key, origin, legacy and modern transports, ACL, minimal output, bounds and fail-closed checks passed.")


if __name__ == "__main__":
    main()
