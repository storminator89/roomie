#!/usr/bin/env python3
"""Roomie MCP HTTP smoke. Requires Python 3, PHP CLI and PDO SQLite."""
from http.cookiejar import CookieJar
import json
import re
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, ProxyHandler, Request

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
        [PHP, "-d", "display_errors=0", "-d", "session.save_path=" + str(Path(env["ROOMIE_DB_PATH"]).parent), "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
        cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=temp,
    )
    return proc, f"http://127.0.0.1:{port}/mcp.php"


def stop(proc):
    proc.terminate()
    proc.wait(timeout=5)



def smoke_admin(url, database, password, starts):
    opener = build_opener(ProxyHandler({}), HTTPCookieProcessor(CookieJar()))
    base = url.rsplit("/", 1)[0]

    def page(route, fields=None):
        data = urlencode(fields).encode() if fields is not None else None
        try:
            response = opener.open(Request(base + "/" + route, data=data), timeout=5)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode()

    def field(html, name):
        match = re.search(r'<input\b[^>]*name="' + name + r'"[^>]*value="([^"]+)"', html)
        assert match, f"Expected {name} form field"
        return match.group(1)

    def sign_in(email):
        _, _, html = page("login.php")
        page("login.php", {"csrf_token": field(html, "csrf_token"), "email": email, "password": password})

    def form():
        status, headers, html = page("admin_mcp.php")
        assert status == 200 and "no-store" in headers["Cache-Control"]
        return {name: field(html, name) for name in ("csrf_token", "mcp_form_token")}

    def generated(fields):
        status, headers, html = page("admin_mcp.php", fields)
        assert status == 200 and "no-store" in headers["Cache-Control"] and "Location" not in headers
        match = re.search(r'<input\b[^>]*id="mcp-issued-key"[^>]*value="([a-f0-9]{64})"', html)
        assert match, "Expected a one-time issued key in the form response"
        return match.group(1)

    def visible(key):
        status, _, response = request(url, rpc("tools/call", {
            "name": "list_room_bookings", "arguments": starts,
        }), protocol=VERSION, key=key)
        assert status == 200 and not response["result"]["isError"]
        return {row["room_id"] for row in response["result"]["structuredContent"]["bookings"]}

    def denied(key):
        assert request(url, rpc("tools/list"), key=key, protocol=VERSION)[0] in (401, 503)

    assert 'mcp_form_token' not in page("admin_mcp.php")[2]
    sign_in("mcp@example.test")
    assert 'mcp_form_token' not in page("admin_mcp.php")[2]
    opener = build_opener(ProxyHandler({}), HTTPCookieProcessor(CookieJar()))
    sign_in("admin@example.test")
    credentials = form()
    invalid = dict(credentials, csrf_token="x" * 64, action="rotate", user_id="2")
    status, _, html = page("admin_mcp.php", invalid)
    assert status == 403 and 'mcp-issued-key' not in html
    credentials = form()
    first = generated(dict(credentials, action="rotate", user_id="2", confirm_rotation="1"))
    denied(TOKEN)
    assert visible(first) == {1, 3}
    with sqlite3.connect(database) as db:
        assert first not in "\n".join(db.iterdump()), "Raw API key must not be stored in SQLite"
    _, _, replay = page("admin_mcp.php", dict(credentials, action="rotate", user_id="2", confirm_rotation="1"))
    assert first not in replay and 'mcp-issued-key' not in replay
    _, _, html = page("admin_mcp.php")
    assert first not in html and 'mcp-issued-key' not in html
    assert visible(first) == {1, 3}
    second = generated(dict(form(), action="rotate", user_id="4", confirm_rotation="1"))
    assert first != second
    denied(first)
    assert visible(second) == {1}
    page("admin_mcp.php", dict(form(), action="revoke", confirm_revoke="1"))
    for key in (TOKEN, first, second):
        denied(key)
    assert second not in page("admin_mcp.php")[2]
    print("MCP admin: role separation, CSRF, one-time key, ACL switch, rotation and revocation passed.")


def main():
    with tempfile.TemporaryDirectory(prefix="roomie-mcp-") as temp:
        path = Path(temp) / "test.sqlite"
        env = dict(os.environ, ROOMIE_DB_PATH=str(path), ROOMIE_DOCUMENT_ROOT=str(ROOT),
                   ROOMIE_ADMIN_NAME="Synthetic Admin", ROOMIE_ADMIN_EMAIL="admin@example.test",
                   ROOMIE_MCP_API_KEY=TOKEN, ROOMIE_MCP_USER_ID="2")
        env.pop("ROOMIE_MCP_ORIGIN", None)
        password = secrets.token_hex(32)
        env["ROOMIE_ADMIN_PASSWORD_HASH"] = subprocess.check_output(
            [PHP, "-r", "echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);"],
            input=password, text=True,
        )
        subprocess.run([PHP, str(ROOT / "init_db.php")], cwd=ROOT, env=env, check=True,
                       stdout=subprocess.DEVNULL)
        with sqlite3.connect(path) as db:
            db.executemany("INSERT INTO users (id, name, email, password, is_admin) VALUES (?, ?, ?, ?, 0)", [
                (2, "Connector account", "mcp@example.test", env["ROOMIE_ADMIN_PASSWORD_HASH"]),
                (4, "Second connector", "other-mcp@example.test", "!"),
            ])
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
                assert request(url, rpc("tools/list"), protocol=VERSION)[0] in (401, 503)
                with sqlite3.connect(path) as db:
                    db.execute("UPDATE users SET is_admin=0 WHERE id=2")
                smoke_admin(url, path, password, starts)
            finally:
                stop(process)
    print("MCP HTTP: key, origin, legacy and modern transports, ACL, minimal output, bounds and fail-closed checks passed.")


if __name__ == "__main__":
    main()
