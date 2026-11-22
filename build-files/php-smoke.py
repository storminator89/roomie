#!/usr/bin/env python3
"""Local application smoke test. Python 3 + selected PHP CLI (PDO SQLite, Linux POSIX)."""

import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from base64 import b64decode
import datetime as dt
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import signal
import sqlite3
import struct
import socket
import subprocess
import sys
import tempfile
from threading import Barrier
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlsplit
from zoneinfo import ZoneInfo
from urllib.request import (
    HTTPRedirectHandler,
    HTTPCookieProcessor,
    ProxyHandler,
    Request,
    build_opener,
)


class SmokeFailure(RuntimeError):
    pass


def expect(condition, description):
    if not condition:
        raise SmokeFailure(description)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base_url):
        self.base_url = base_url
        self.opener = build_opener(
            ProxyHandler({}), HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect()
        )

    def request(self, resource, fields=None, *, data=None, content_type=None, binary=False, csrf=None):
        payload = urlencode(fields).encode("utf-8") if fields is not None else data
        headers = {"Content-Type": content_type} if content_type else {}
        if csrf is not None:
            headers["X-CSRF-Token"] = csrf
        try:
            response = self.opener.open(Request(self.base_url + resource, data=payload, headers=headers), timeout=5)
        except HTTPError as error:
            response = error
        with response:
            payload = response.read()
            return response.code, response.headers, payload if binary else payload.decode("utf-8")

    def status(self):
        status, headers, body = self.request("check_admin.php")
        expect(status == 200 and "application/json" in headers.get("Content-Type", ""),
               "Expected JSON account status")
        return json.loads(body)

    def login(self, email, password):
        status, _, body = self.request("login.php")
        token = re.search(r'name="csrf_token" value="([0-9a-f]{64})"', body)
        expect(status == 200 and token, "Expected login form with CSRF token")
        status, headers, _ = self.request("login.php", {
            "email": email, "password": password, "csrf_token": token.group(1),
        })
        expect(status in (302, 303) and headers.get("Location") == "index.php",
               f"Login failed for synthetic account: HTTP {status}")
        return self.status()


SKIP_DIRS = {".git", "node_modules", "vendor", ".venv", "__pycache__"}
PHP_TEST_ARGS = ["-d", "error_reporting=-1", "-d", "display_errors=0", "-d", "log_errors=1"]
DIAGNOSTIC = re.compile(r"\b(?:PHP )?(?:warning|notice|deprecat(?:ed|ion)|fatal error|parse error)\s*:", re.I)


def run(command, root, env):
    proc = subprocess.run(command, cwd=root, env=env, text=True, capture_output=True, timeout=30)
    if proc.returncode or DIAGNOSTIC.search(proc.stdout + proc.stderr):
        raise SmokeFailure(f"{' '.join(map(str, command[:6]))} failed:\n{proc.stdout}{proc.stderr}")
    return proc.stdout.strip()


def smoke_avatar(command, root, env, guest, user, status):
    gd = run(command + ["-r", "echo extension_loaded('gd') && (imagetypes() & IMG_PNG) ? 'yes' : 'no';"], root, env)
    if gd != "yes":
        print("Avatar: skipped (GD PNG not enabled)", flush=True)
        return
    source = run(command + ["-r", "ob_start(); imagepng(imagecreatetruecolor(320, 160)); echo base64_encode(ob_get_clean());"], root, env)
    boundary = "RoomieLocalSmokePortrait"
    body = b""
    for field, value in (("csrf_token", status["csrf_token"]), ("name", "Roomie Smoke User")):
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"\r\n\r\n{value}\r\n'.encode()
    body += (
        f'--{boundary}\r\nContent-Disposition: form-data; name="profile_image"; filename="fixture.png"'
        '\r\nContent-Type: image/png\r\n\r\n'
    ).encode() + b64decode(source) + f"\r\n--{boundary}--\r\n".encode()
    code, _, html = user.request("profile.php", data=body, content_type=f"multipart/form-data; boundary={boundary}")
    match = re.search(r'src="(avatar\.php\?user_id=\d+)"', html)
    expect(code == 200 and match, "Expected uploaded avatar on the synthetic profile")
    avatar_url = match.group(1)
    expect(guest.request(avatar_url, binary=True)[0] == 401, "Avatar must not be public")
    code, headers, data = user.request(avatar_url, binary=True)
    expect(code == 200 and headers.get("Content-Type") == "image/png" and data.startswith(b"\x89PNG\r\n\x1a\n")
           and struct.unpack(">II", data[16:24]) == (256, 128), "Expected private avatar re-encoded at 256×128")
    print("Avatar: upload, PNG resize, download and guest access restriction passed", flush=True)


def smoke_floorplan(command, root, env, guest, admin, admin_status, user, room_id):
    gd = run(command + ["-r", "echo extension_loaded('gd') && (imagetypes() & IMG_PNG) ? 'yes' : 'no';"], root, env)
    if gd != "yes":
        print("Floorplan: skipped (GD PNG not enabled)", flush=True)
        return
    private_room = int(run(command + ["-r", """
        require 'database.php';
        $db = getDatabaseConnection();
        $db->exec("INSERT INTO rooms (name, type, capacity) VALUES ('Test Restricted', 'fk-office', 2)");
        echo $db->lastInsertId();
    """], root, env))
    png = b64decode(run(command + ["-r", "ob_start(); imagepng(imagecreatetruecolor(480, 300)); echo base64_encode(ob_get_clean());"], root, env))
    boundary = "RoomieSyntheticFloorplan"
    token = admin_status["csrf_token"]
    def upload(name):
        body = (f'--{boundary}\r\nContent-Disposition: form-data; name="title"\r\n\r\n{name}\r\n'
                f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="floor.png"\r\n'
                'Content-Type: image/png\r\n\r\n').encode() + png + f"\r\n--{boundary}--\r\n".encode()
        code, _, raw = admin.request("floorplan_upload.php", data=body, content_type=f"multipart/form-data; boundary={boundary}", csrf=token)
        expect(code == 200 and json.loads(raw).get("success") is True, "Expected administrator floorplan upload")
        return json.loads(raw)["id"]
    first, second = upload("Test floor A"), upload("Test floor B")
    code, _, raw = admin.request("floorplan_list.php")
    expect(code == 200, "Expected administrator plan list")
    floors = {floor["id"]: floor for floor in json.loads(raw)["plans"]}
    expect(set(floors) == {first, second}, "Both named floorplans must be retained")
    expect((floors[first]["width"], floors[first]["height"]) == (480, 300), "Floorplan image dimensions must be preserved")
    revision = floors[first]["revision"]
    rooms = [
        {"roomId": int(room_id), "x": 0.15, "y": 0.12, "w": 0.2, "h": 0.2},
        {"roomId": private_room, "x": 0.55, "y": 0.12, "w": 0.2, "h": 0.2},
    ]
    payload = json.dumps({"id": first, "revision": revision, "markers": rooms}).encode()
    code, _, raw = admin.request("floorplan_markers.php", data=payload, content_type="application/json", csrf=token)
    expect(code == 200 and json.loads(raw)["revision"] != revision, "Saving markers must advance the plan revision")
    expect(admin.request("floorplan_markers.php", data=payload, content_type="application/json", csrf=token)[0] == 409,
           "Old marker revisions must not overwrite newer edits")
    current_revision = json.loads(raw)["revision"]
    rename = json.dumps({"id": first, "revision": current_revision, "title": "Test floor A renamed"}).encode()
    rename_args = {"data": rename, "content_type": "application/json"}
    expect(user.request("floorplan_title.php", **rename_args)[0] == 403,
           "Ordinary users must not rename floorplans")
    expect(admin.request("floorplan_title.php", **rename_args)[0] == 403,
           "Admin floorplan renames require CSRF")
    code, _, renamed = admin.request("floorplan_title.php", csrf=token, **rename_args)
    expect(code == 200 and json.loads(renamed)["revision"] != current_revision,
           "Renaming a plan must advance its shared revision")
    expect(admin.request("floorplan_title.php", csrf=token, **rename_args)[0] == 409,
           "A stale rename must not overwrite newer edits")
    code, _, raw = user.request("floorplan_list.php")
    expect(code == 200, "Expected member floorplan list")
    visible = next(floor for floor in json.loads(raw)["plans"] if floor["id"] == first)
    expect(visible["title"] == "Test floor A renamed", "Expected changed title in viewer")
    expect([marker["roomId"] for marker in visible["markers"]] == [int(room_id)], "Rename must preserve markers; room permissions still apply")
    expect(guest.request(f"floorplan_image.php?id={first}", binary=True)[0] == 401, "Floorplan image must not be public")
    code, headers, data = user.request(f"floorplan_image.php?id={first}", binary=True)
    expect(code == 200 and headers.get("Content-Type") == "image/png" and data.startswith(b"\x89PNG\r\n\x1a\n")
           and "no-store" in headers.get("Cache-Control", ""), "Expected private PNG floorplan")
    print("Floorplan: multiple uploads, rename, saved markers, revision, room permissions and private image passed", flush=True)


class BookingDetailsHTML(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.inputs = []
        self.range = {}
        self.map_back = None
        self.select = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input":
            self.inputs.append(attrs)
        elif tag == "select":
            self.select = attrs.get("name")
        elif tag == "option" and self.select and "selected" in attrs:
            self.range[self.select] = attrs.get("value")
        elif tag == "a" and "rb-back" in attrs.get("class", "").split():
            self.map_back = attrs.get("href")

    def handle_endtag(self, tag):
        if tag == "select":
            self.select = None


def smoke_flexible_bookings(command, root, env, date, guest, admin, admin_status, user, user_status, concurrent):
    db_path = env["ROOMIE_DB_PATH"]
    run(command + ["-r", """
        require 'booking_helpers.php';
        // 10:15 in Berlin during daylight-saving time, without changing the host clock.
        $now = new DateTimeImmutable('2026-06-01T08:15:00Z');
        $date = parseBookingDate('2026-06-01');
        foreach ([['09:45', '10:00'], ['10:00', '10:30'], ['10:15', '10:30']] as [$start, $end]) {
            try {
                roomieParseCustomBookingTimes($date, $start, $end, $now);
                fwrite(STDERR, 'Unexpected elapsed, ongoing, or boundary custom slot'); exit(1);
            } catch (DomainException) {
                continue;
            }
        }
        if (roomieParseCustomBookingTimes($date, '10:30', '10:45', $now) !== ['10:30', '10:45']) {
            fwrite(STDERR, 'Unexpected result for future same-day slot'); exit(1);
        }
    """], root, env)
    with closing(sqlite3.connect(db_path)) as db, db:
        ids = dict(db.execute("SELECT email, id FROM users"))
        user_id = ids["smoke-user@example.test"]
        admin_id = ids["smoke-admin@example.test"]
        colleague_id = db.execute("""
            INSERT INTO users (name, email, password, is_admin)
            SELECT 'Smoke Other Member', 'smoke-other@example.test', password, 0 FROM users WHERE id = ?
        """, (user_id,)).lastrowid
        rooms = {}
        for name, capacity, kind in (("Gap", 1, "meeting"), ("Capacity", 2, "shared-desk"),
                                     ("Own overlap", 3, "shared-desk"), ("Range", 1, "office"),
                                     ("Preset", 1, "office"), ("Race", 1, "office"),
                                     ("Restricted", 2, "fk-office")):
            rooms[name] = db.execute("INSERT INTO rooms (name, type, capacity) VALUES (?, ?, ?)",
                                     (f"QA Flex {name}", kind, capacity)).lastrowid
        db.execute("INSERT INTO permissions (user_id, room_id) VALUES (?, ?)", (colleague_id, rooms["Restricted"]))
        next_date = (dt.date.fromisoformat(date) + dt.timedelta(days=1)).isoformat()
        for who, room, on, start, end in (
            (colleague_id, "Gap", date, "09:00", "10:00:00"),
            (colleague_id, "Gap", date, "11:00:00", "17:00"),
            (colleague_id, "Capacity", date, "10:00:00", "10:30:00"),
            (admin_id, "Capacity", date, "10:30", "11:00"),
            (user_id, "Own overlap", date, "12:00", "12:30"),
            (colleague_id, "Range", next_date, "09:00", "17:00"),
        ):
            db.execute("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?)",
                       (who, rooms[room], on, start, end))

    colleague = Client(user.base_url)
    colleague_status = colleague.login("smoke-other@example.test", env["ROOMIE_SMOKE_PASSWORD"])
    user_token = user_status["csrf_token"]
    admin_token = admin_status["csrf_token"]
    colleague_token = colleague_status["csrf_token"]

    def fields(room, start="10:00", end="11:00", day=date):
        return {"selectedWorkspace": rooms[room], "start_date": day, "end_date": day,
                "time_period": "individuell", "start_time": start, "end_time": end}

    def submit(payload, actor=user, token=user_token, expected=None, description="booking"):
        code, headers, body = actor.request("process_booking.php", payload, csrf=token)
        expect(200 <= code < 500 and "application/json" in headers.get("Content-Type", ""),
               f"{description}: expected a structured booking result, got HTTP {code}")
        result = json.loads(body)
        expect(isinstance(result.get("success"), bool), f"{description}: missing booking outcome")
        if expected is not None:
            expect(result["success"] is expected, f"{description}: received {result}")
        return code, result

    for start, end in (("9:00", "10:00"), ("09:00:00", "10:00"), ("09:10", "10:00"),
                       ("08:45", "10:00"), ("16:45", "17:15"), ("11:00", "11:00"),
                       ("11:00", "10:00"), ("", "10:00")):
        submit(fields("Preset", start, end), expected=False, description=f"Reject invalid window {start!r}–{end!r}")
    malformed = fields("Preset")
    malformed["start_time[]"] = malformed.pop("start_time")
    submit(malformed, expected=False, description="Reject non-scalar time input")
    submit(dict(fields("Preset"), end_date=next_date), expected=False, description="Reject custom multi-day booking")
    berlin_clock = dt.datetime.now(ZoneInfo("Europe/Berlin"))
    past_day = (berlin_clock.date() - dt.timedelta(days=1)).isoformat()
    submit(fields("Preset", day=past_day), expected=False, description="Reject past date")
    today = berlin_clock.date().isoformat()
    if berlin_clock.time() > dt.time(9, 15):
        submit(fields("Preset", "09:00", "09:15", today), expected=False, description="Reject an ended same-day slot")
    code, _ = submit(fields("Preset"), token=None, expected=False, description="Reject booking without CSRF")
    expect(code == 403, "Missing booking CSRF token should receive HTTP 403")

    for actor, expected_can_book in ((user, None), (admin, False), (colleague, True)):
        code, _, raw = actor.request("load_grid.php")
        expect(code == 200, "Expected authenticated map-room data")
        by_id = {room["id"]: room for room in json.loads(raw)["data"]["rooms"]}
        expect(by_id[rooms["Gap"]]["can_book"] is True, "Public rooms must be bookable on the map")
        if expected_can_book is None:
            expect(rooms["Restricted"] not in by_id, "The map must not expose rooms hidden from members")
        else:
            expect(by_id[rooms["Restricted"]]["can_book"] is expected_can_book,
                   "Map eligibility must distinguish an admin's inspection rights from a booking grant")

    hidden_url = 'get_bookings.php?' + urlencode({"room_id": rooms["Restricted"], "start_date": date})
    expect(guest.request(hidden_url)[0] == 401, "Room occupancy must stay private to a signed-in session")
    expect(user.request(hidden_url)[0] == 404, "Restricted occupancy must be hidden from ungranted members")
    expect(user.request(f"room_details.php?id={rooms['Restricted']}&date={date}")[0] == 404,
           "A restricted room must not leak through room details")
    submit(fields("Restricted"), expected=False, description="Reject ungranted member booking")
    submit(fields("Restricted"), actor=admin, token=admin_token, expected=False,
           description="Admin visibility does not confer booking rights")
    submit(fields("Restricted"), actor=colleague, token=colleague_token, expected=True,
           description="A grant enables custom booking")

    gap_url = f"room_details.php?id={rooms['Gap']}&date={date}"
    code, _, html = user.request(gap_url)
    expect(code == 200 and 'id="rb-room-book"' in html and "Smoke Other Member" not in html,
           "Room details must permit the free gap without exposing other bookers")
    details = BookingDetailsHTML(html)
    periods = {entry.get("value"): entry for entry in details.inputs if entry.get("name") == "time_period"}
    expect(all(value in periods and "disabled" in periods[value] for value in ("ganzerTag", "vormittags", "nachmittags")),
           "All three presets should be unavailable on a room with only a custom gap")
    expect("individuell" in periods and "disabled" not in periods["individuell"] and "checked" in periods["individuell"]
           and details.range == {"start_time": "10:00", "end_time": "10:15"},
           "The booking form must select the earliest usable custom gap when presets are full")
    preferred = {"start_time": "10:15", "end_time": "10:45"}
    code, _, html = user.request(gap_url + "&" + urlencode({**preferred, "free": "1"}))
    linked = BookingDetailsHTML(html)
    target = urlsplit(linked.map_back or "")
    expect(code == 200 and linked.range == preferred and target.path == "raumplan.php"
           and parse_qs(target.query) == {key: [value] for key, value in {**preferred, "date": date, "free": "1"}.items()},
           "Opening room details and returning must preserve the map's date, times and free filter")
    submit(fields("Gap"), expected=True, description="Custom interval fits a gap between legacy bookings")
    submit(fields("Gap", "09:45", "10:15"), actor=admin, token=admin_token, expected=False,
           description="Overlapping a booking by 15 minutes blocks a single-place room")
    submit(fields("Own overlap", "12:15", "12:45"), expected=False,
           description="Own overlap is blocked despite spare capacity")
    submit(fields("Own overlap", "12:30", "12:45"), expected=True,
           description="Own adjacent booking is permitted")
    submit(fields("Capacity"), expected=True, description="Adjacent bookings consume one place each, not two")
    submit(fields("Capacity", "10:15", "10:30"), actor=admin, token=admin_token, expected=False,
           description="Peak concurrent capacity must be respected")
    submit(dict(fields("Range"), time_period="ganzerTag", end_date=next_date), expected=False,
           description="A blocked date must roll back the entire multi-day booking")

    expected_presets = [("ganzerTag", "09:00", "17:00"), ("vormittags", "09:00", "12:00"),
                        ("nachmittags", "13:00", "17:00")]
    for offset, (period, _, _) in enumerate(expected_presets):
        on = (dt.date.fromisoformat(date) + dt.timedelta(days=offset)).isoformat()
        submit(dict(fields("Preset", "16:45", "17:00", on), time_period=period), expected=True,
               description=f"Keep legacy {period} preset authoritative regardless of custom time fields")

    if concurrent:
        barrier = Barrier(2, timeout=5)

        def compete(actor, token):
            barrier.wait()
            return submit(fields("Race"), actor=actor, token=token, description="Race for one place")[1]["success"]

        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(compete, user, user_token)
            second = pool.submit(compete, colleague, colleague_token)
            expect(sorted((first.result(), second.result())) == [False, True],
                   "Exactly one concurrent request should win the last place")
        with closing(sqlite3.connect(db_path)) as db:
            expect(db.execute("SELECT COUNT(*) FROM bookings WHERE room_id = ?", (rooms["Race"],)).fetchone()[0] == 1,
                   "Exactly one concurrent booking should commit")

    with closing(sqlite3.connect(db_path)) as db:
        expect(db.execute("SELECT date FROM bookings WHERE user_id = ? AND room_id = ?", (user_id, rooms["Range"])).fetchall() == [],
               "A failed multi-day preset must not leave a partial booking")
        expect(db.execute("SELECT start_time, end_time FROM bookings WHERE room_id = ? ORDER BY date", (rooms["Preset"],)).fetchall()
               == [(start, end) for _, start, end in expected_presets], "Presets must persist their original hours")
        own_gap = db.execute("SELECT id, start_time, end_time FROM bookings WHERE user_id = ? AND room_id = ?",
                             (user_id, rooms["Gap"])).fetchall()
        expect(len(own_gap) == 1 and own_gap[0][1:] == ("10:00", "11:00"), "Custom window must persist exact selected times")

    code, _, raw = user.request('get_bookings.php?' + urlencode({"room_id": rooms["Gap"], "start_date": date}))
    slots = json.loads(raw)
    expect(code == 200 and len(slots) == 3 and sum("user_id" in booking for booking in slots) == 1
           and sum(booking["own"] is True for booking in slots) == 1
           and all(booking["own"] is ("user_id" in booking) for booking in slots)
           and "Smoke Other Member" not in raw, "Map occupancy must identify own slots without leaking other identities")
    code, headers, ics = user.request(f"bookings.php?download=calendar&booking_id={own_gap[0][0]}")
    expect(code == 200 and "text/calendar" in headers.get("Content-Type", ""), "Custom booking needs a calendar download")
    for label, hour in (("DTSTART", 10), ("DTEND", 11)):
        local = dt.datetime.combine(dt.date.fromisoformat(date), dt.time(hour), ZoneInfo("Europe/Berlin"))
        stamp = local.astimezone(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        expect(f"{label}:{stamp}" in ics.split("\r\n"), f"Custom ICS must preserve converted {label}")
    print("Flexible bookings: validation, presets, gap, peak capacity, own overlap, rollback, "
          "room grants, private map data and ICS passed" + ("; concurrent last place passed" if concurrent else ""), flush=True)


def smoke(php, root, scratch, expected_minor):
    env = {key: value for key, value in os.environ.items()
           if not key.startswith("ROOMIE_") and key != "PHP_CLI_SERVER_WORKERS"}
    env.update({
        "ROOMIE_DB_PATH": str(scratch / "fixture.sqlite"),
        "ROOMIE_DOCUMENT_ROOT": str(root),
        "ROOMIE_ADMIN_NAME": "Roomie Smoke Admin",
        "ROOMIE_ADMIN_EMAIL": "smoke-admin@example.test",
        "ROOMIE_SMOKE_PASSWORD": "FreshTempSmokeOnly!91",
    })
    concurrent = os.name == "posix"
    server_env = dict(env, PHP_CLI_SERVER_WORKERS="3") if concurrent else env
    command = [php] + PHP_TEST_ARGS
    php_code = """
      echo PHP_VERSION, PHP_EOL;
      if (!extension_loaded('pdo_sqlite') || (PHP_OS_FAMILY === 'Linux' && !function_exists('posix_geteuid'))) {
          fwrite(STDERR, "PDO SQLite and (on Linux) POSIX are required.\n"); exit(1);
      }
      echo 'SQLite ', (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
    """
    runtime = run(command + ["-r", php_code], root, env)
    print(runtime, flush=True)
    if expected_minor is not None:
        actual = runtime.splitlines()[0]
        expect(re.fullmatch(re.escape(expected_minor) + r"\.[0-9]+", actual) is not None,
               f"Expected stable PHP {expected_minor}.x, got {actual}")

    files = []
    for directory, children, names in os.walk(root):
        children[:] = [name for name in children if name not in SKIP_DIRS]
        files.extend(Path(directory) / name for name in names
                     if name.endswith(".php") and not (Path(directory) / name).is_symlink())
    expect(bool(files), "No PHP source files found")
    for source in sorted(files):
        run(command + ["-l", str(source)], root, env)
    print(f"PHP lint: {len(files)} files", flush=True)

    bootstrap = run(command + ["-r", "echo password_hash(getenv('ROOMIE_SMOKE_PASSWORD'), PASSWORD_DEFAULT);"], root, env)
    # The bootstrap hash only exists in isolated child process environment.
    run(command + [str(root / "init_db.php")], root, dict(env, ROOMIE_ADMIN_PASSWORD_HASH=bootstrap))
    seed = """
        require 'database.php';
        $db = getDatabaseConnection();
        $db->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 0)')
            ->execute(['Roomie Smoke User', 'smoke-user@example.test', password_hash(getenv('ROOMIE_SMOKE_PASSWORD'), PASSWORD_DEFAULT)]);
        $db->prepare('INSERT INTO rooms (name, number, type, capacity, equipment) VALUES (?, ?, ?, ?, ?)')
            ->execute(['Besprechung, Süd', 'SMOKE', 'shared-desk', 2, '[]']);
        echo $db->lastInsertId(), PHP_EOL;
        echo (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    """
    room_id, date = run(command + ["-r", seed], root, env).splitlines()
    sessions = scratch / "sessions"
    sessions.mkdir(mode=0o700)
    logfile = scratch / "server.log"
    with socket.socket() as reserve:
        reserve.bind(("127.0.0.1", 0))
        port = reserve.getsockname()[1]
    base_url = f"http://127.0.0.1:{port}/"
    with logfile.open("w", encoding="utf-8") as output:
        with subprocess.Popen(command + ["-d", f"session.save_path={sessions}", "-S",
                              f"127.0.0.1:{port}", "-t", str(root)], cwd=root, env=server_env,
                              stdout=output, stderr=subprocess.STDOUT, start_new_session=concurrent) as server:
            try:
                guest = Client(base_url)
                for _ in range(50):
                    if server.poll() is not None:
                        raise SmokeFailure("PHP HTTP server terminated before opening")
                    try:
                        signed_out = guest.status()
                        break
                    except URLError:
                        time.sleep(0.1)
                else:
                    raise SmokeFailure("PHP HTTP server did not respond")
                expect(signed_out.get("isLoggedIn") is False and "csrf_token" not in signed_out,
                       "Guest role must be logged out and omit CSRF token")
                admin = Client(base_url)
                admin_status = admin.login("smoke-admin@example.test", env["ROOMIE_SMOKE_PASSWORD"])
                expect(admin_status.get("isLoggedIn") is True and admin_status.get("isAdmin") is True,
                       "Admin role was not preserved across login")
                user = Client(base_url)
                user_status = user.login("smoke-user@example.test", env["ROOMIE_SMOKE_PASSWORD"])
                expect(user_status.get("isLoggedIn") is True and user_status.get("isAdmin") is False,
                       "Ordinary account role was not preserved across login")

                for actor, can_edit in ((admin, True), (user, False)):
                    status, _, html = actor.request("raumplan.php")
                    expect(status == 200 and 'id="roomplan-view"' in html
                           and ('id="roomplan-edit"' in html) == can_edit,
                           "Expected role-appropriate floorplan controls")
                    status, _, html = actor.request("index.php")
                    expect(status == 200 and 'id="bookingForm"' in html
                           and f'<option value="{room_id}">' in html,
                           "Expected dashboard booking form and synthetic room")

                status, headers, body = user.request("process_booking.php", {
                    "csrf_token": user_status["csrf_token"],
                    "start_date": date,
                    "end_date": date,
                    "selectedWorkspace": room_id,
                    "time_period": "vormittags",
                })
                expect(status == 200 and "application/json" in headers.get("Content-Type", "")
                       and json.loads(body).get("success") is True, f"Booking did not succeed: HTTP {status}")
                booking_id = run(command + ["-r", """
                    require 'database.php';
                    $rows = getDatabaseConnection()->query('SELECT id FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
                    if (count($rows) !== 1) { fwrite(STDERR, 'Expected exactly one booking'); exit(1); }
                    echo $rows[0];
                """], root, env)
                for actor, can_manage in ((admin, True), (user, False)):
                    status, _, html = actor.request("rooms.php")
                    expect(status == 200 and ('raumplan.php?manage=1' in html) == can_manage,
                           "Only administrators should see the room-management shortcut")
                seed_room = {"name": "Büro Verwaltung", "number": "QA-31", "type": "office",
                             "capacity": 3, "amenities": ["display", "whiteboard", "monitor"]}
                code, _, record = admin.request("add_room.php", data=json.dumps(seed_room).encode(),
                                                content_type="application/json", csrf=admin_status["csrf_token"])
                expect(code == 200 and int(json.loads(record)["roomId"]) > 0,
                       "Admins should be able to create rooms with the displayed equipment")
                seed_room["id"] = int(json.loads(record)["roomId"])
                seed_room["name"] = "Büro Verwaltung Neu"
                code, _, _ = admin.request("update_room.php", data=json.dumps(seed_room).encode(),
                                           content_type="application/json", csrf=admin_status["csrf_token"])
                expect(code == 200, "Room metadata edits should succeed through the existing API")
                status, _, html = user.request("rooms.php?q=QA-31")
                expect(status == 200 and 'Büro Verwaltung Neu' in html and 'Whiteboard' in html,
                       "New room details and equipment should be visible in the directory")
                room_name = 'Besprechung, Süd'
                status, headers, html = user.request("bookings.php")
                expect(status == 200 and room_name in html and 'Buchung stornieren' in html
                       and 'private' in headers.get("Cache-Control", ""),
                       "Owners should see their own booking and its controls")
                status, _, html = admin.request("bookings.php")
                expect(status == 200 and room_name not in html,
                       "The default booking view must not list other people's bookings")
                status, _, html = admin.request("bookings.php?scope=all")
                expect(status == 200 and room_name in html
                       and 'Buchung stornieren' not in html,
                       "The full admin view must not expose cancellation controls for others")
                status, _, html = user.request("rooms.php?q=SMOKE")
                expect(status == 200 and room_name in html, "Search must find the room number")
                status, _, html = user.request("rooms.php?q=DefinitelyNotARoom")
                expect(status == 200 and room_name not in html, "Search must filter unrelated rooms")
                status, _, html = user.request(f"room_details.php?id={room_id}&date={date}")
                expect(status == 200 and 'id="rb-room-book"' in html and 'name="csrf_token"' in html,
                       "Eligible users should get the direct, CSRF-protected booking form")
                print("App UX: own booking default, admin overview, room search and direct booking passed", flush=True)
                status, headers, ics = user.request(f"bookings.php?download=calendar&booking_id={booking_id}")
                expect(status == 200 and "text/calendar" in headers.get("Content-Type", ""),
                       f"Expected booking ICS response, got HTTP {status}")
                content = ics.split("\r\n")
                expect(content[0] == "BEGIN:VCALENDAR" and content[-2:] == ["END:VCALENDAR", ""]
                       and content.count("BEGIN:VEVENT") == 1, "Expected one complete CRLF-delimited ICS event")
                expect("SUMMARY:Besprechung\\, Süd" in content
                       and "DESCRIPTION:Roomie Smoke User" in content, "Expected escaped event metadata")
                stamp = dt.date.fromisoformat(date).strftime("%Y%m%d")
                expect(any(re.fullmatch(f"DTSTART:{stamp}T[0-9]{{6}}Z", line) for line in content),
                       "Expected UTC event time for booked date")
                expect(all(len(line.encode("utf-8")) <= 75 for line in content), "ICS line length is out of bounds")
                print("Local HTTP: authentication, floorplan roles, dashboard, booking, and ICS passed", flush=True)
                smoke_avatar(command, root, env, guest, user, user_status)
                smoke_floorplan(command, root, env, guest, admin, admin_status, user, room_id)
                smoke_flexible_bookings(command, root, env, date, guest, admin, admin_status,
                                        user, user_status, concurrent)
            finally:
                if concurrent:
                    try:
                        os.killpg(server.pid, signal.SIGTERM)
                    except ProcessLookupError:
                        pass
                else:
                    server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    if concurrent:
                        os.killpg(server.pid, signal.SIGKILL)
                    else:
                        server.kill()
                    server.wait()
                log = logfile.read_text(encoding="utf-8")
                if DIAGNOSTIC.search(log):
                    raise SmokeFailure(f"PHP emitted diagnostics during HTTP smoke:\n{log}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", help="PHP CLI executable or wrapper (default: PHP_BIN, PHP_BINARY or php)")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1],
                        help="Roomie document root (default: parent of build-files)")
    parser.add_argument("--expect-minor", metavar="MAJOR.MINOR", help="Require a stable release in this PHP series")
    args = parser.parse_args()
    php = shutil.which(args.php or os.getenv("PHP_BIN") or os.getenv("PHP_BINARY") or "php")
    if php is None:
        parser.error("PHP CLI not found: pass --php /path/to/php")
    root = args.root.resolve()
    if not (root / "init_db.php").is_file():
        parser.error("Roomie root must contain init_db.php")
    try:
        with tempfile.TemporaryDirectory(prefix="roomie-php-smoke-") as name:
            scratch = Path(name).resolve()
            expect(not scratch.is_relative_to(root), "Temporary directory must be outside Roomie document root")
            smoke(str(Path(php).resolve()), root, scratch, args.expect_minor)
    except (OSError, SmokeFailure, subprocess.SubprocessError, KeyError, ValueError) as error:
        print(f"Roomie smoke failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
