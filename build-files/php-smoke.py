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


class MoveFormsHTML(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.fields = None
        self.forms = {}
        self.opened = set()
        self.details = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "details":
            self.details.append(attrs)
        elif tag == "form" and attrs.get("action") == "reschedule_booking.php" and attrs.get("method", "").lower() == "post":
            self.fields = {}
        elif tag == "input" and self.fields is not None and attrs.get("type") == "hidden" and attrs.get("name"):
            self.fields[attrs["name"]] = attrs.get("value")

    def handle_endtag(self, tag):
        if tag == "details" and self.details:
            self.details.pop()
        elif tag == "form" and self.fields is not None:
            if "booking_id" in self.fields:
                booking_id = int(self.fields["booking_id"])
                self.forms[booking_id] = self.fields
                open_classes = {name for detail in self.details if "open" in detail for name in detail.get("class", "").split()}
                if {"rb-more", "rb-move"} <= open_classes:
                    self.opened.add(booking_id)
            self.fields = None


def smoke_reschedule(env, date, guest, admin, admin_status, user, user_status, concurrent):
    db_path = env["ROOMIE_DB_PATH"]
    berlin = ZoneInfo("Europe/Berlin")
    now = dt.datetime.now(berlin)
    today = now.date().isoformat()
    next_date = (dt.date.fromisoformat(date) + dt.timedelta(days=1)).isoformat()
    past_day = (now.date() - dt.timedelta(days=1)).isoformat()

    with closing(sqlite3.connect(db_path)) as db, db:
        accounts = dict(db.execute("SELECT email, id FROM users"))
        who = accounts["smoke-user@example.test"]
        other = accounts["smoke-admin@example.test"]

        def room(name, capacity, kind="office"):
            return db.execute("INSERT INTO rooms (name, type, capacity) VALUES (?, ?, ?)",
                              (f"QA Move {name}", kind, capacity)).lastrowid

        def seat(where):
            return db.execute("INSERT INTO seats (room_id, top, left_pos) VALUES (?, '0', '0')", (where,)).lastrowid

        def booking(owner, where, on, start, end, assigned=None):
            return db.execute("INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)",
                              (owner, where, assigned, on, start, end)).lastrowid

        main = room("Main", 1)
        main_seat = seat(main)
        own = booking(who, main, date, "09:00:00", "10:00:00", main_seat)
        conflicting = booking(other, main, next_date, "10:15", "11:15")
        ended = booking(who, main, past_day, "09:00", "10:00")
        started = booking(who, main, today, "00:00", "23:59:59") if now.time() > dt.time() else None
        restricted = room("Restricted", 2, "fk-office")
        revoked = booking(who, restricted, date, "14:00", "15:00")
        shared = room("Shared assigned seat", 2, "shared-desk")
        shared_seat = seat(shared)
        assigned_booking = booking(who, shared, date, "09:00", "10:00", shared_seat)
        booking(other, shared, next_date, "10:00", "11:00", shared_seat)
        race = room("Race", 1)
        stale_race = booking(who, race, date, "09:00", "10:00")
        last_place = booking(who, race, date, "12:00", "13:00")

    def current(booking_id):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT id, user_id, room_id, seat_id, date, start_time, end_time FROM bookings WHERE id = ?",
                              (booking_id,)).fetchone()

    def calendar(booking_id):
        code, headers, raw = user.request(f"bookings.php?download=calendar&booking_id={booking_id}")
        expect(code == 200 and "text/calendar" in headers.get("Content-Type", ""), "Moved booking keeps its calendar link")
        return {key: value for key, _, value in (line.partition(":") for line in raw.split("\r\n"))}

    def original(booking_id):
        row = current(booking_id)
        return {"booking_id": booking_id, "original_date": row[4], "original_start_time": row[5], "original_end_time": row[6],
                "csrf_token": user_status["csrf_token"]}

    def propose(snapshot, on=next_date, start="13:15", end="14:45"):
        return {**snapshot, "date": on, "start_time": start, "end_time": end}

    def move(payload, expected, description, actor=user):
        code, headers, html = actor.request("reschedule_booking.php", payload)
        expect(code == expected, f"{description}: expected HTTP {expected}, got {code}")
        if code == 303:
            expect(headers.get("Location") == ("login.php" if actor is guest else "bookings.php?moved=1"),
                   f"{description}: incorrect redirect")
        return html

    code, _, html = user.request("bookings.php")
    forms = MoveFormsHTML(html)
    own_forms = forms.forms
    expect(not forms.opened, "Move disclosures should stay closed until the owner opens them or retries")
    expect(code == 200 and "Verschieben" in html and own_forms.get(own) == {key: str(value) for key, value in original(own).items()},
           "Future owner booking must show move form with its exact original snapshot and CSRF")
    expect(conflicting not in own_forms and ended not in own_forms and started not in own_forms,
           "No move form for someone else's, past, or already started booking")
    code, _, html = admin.request("bookings.php?scope=all")
    admin_forms = MoveFormsHTML(html).forms
    expect(code == 200 and conflicting in admin_forms and own not in admin_forms,
           "Admin overview must not give permissions to move a different account's booking")
    code, headers, _ = user.request("reschedule_booking.php?booking_id=" + str(own))
    expect(code == 405 and headers.get("Allow") == "POST", "Rescheduling must be a POST-only mutation")

    snapshot = own_forms[own]
    before = current(own)
    before_ics = calendar(own)
    move(propose(snapshot), 303, "Guest cannot move a booking", guest)
    move(dict(propose(snapshot), csrf_token=""), 403, "Missing CSRF must reject move")
    move(dict(propose(snapshot), csrf_token=admin_status["csrf_token"]), 404, "Admin cannot move another member's booking", admin)
    move(propose(original(revoked)), 403, "Revoked room grant must reject move")
    move(propose(original(ended)), 409, "Ended booking cannot move")
    if started is not None:
        move(propose(original(started)), 409, "Already started booking cannot move")
    move(propose(original(assigned_booking), next_date, "10:15", "10:45"), 409,
         "Preserved seat cannot move onto a booking of the same seat even with room capacity")
    move(propose(snapshot, date, "09:00", "10:00"), 400, "Normalized no-op should not move booking")
    move(propose(snapshot, next_date, "08:45", "09:15"), 400, "Target outside allowed hours must reject")
    conflict_html = move(propose(snapshot, next_date, "10:30", "10:45"), 409, "Occupied target must reject move")
    retry_path = f"bookings.php?edit={own}"
    expect(f'href="{retry_path}#booking-{own}"' in conflict_html, "Conflict response must offer to return to the booking")
    expect(current(own) == before, "All rejected moves must leave the original booking and seat untouched")
    expect(current(assigned_booking)[4:] == (date, "09:00", "10:00"), "Rejected seat move must leave original slot untouched")

    overlap = propose(snapshot, date, "09:30", "10:15")
    move(overlap, 303, "Own original overlap must not block a move")
    move(overlap, 409, "Identical form replay must not write again")
    move(propose(snapshot), 409, "Stale form must not overwrite a previously moved booking")
    expect(current(own) == (*before[:4], date, "09:30", "10:15"), "In-place move must preserve user, room, ID, seat and shorter window")
    code, _, html = user.request(retry_path)
    retry = MoveFormsHTML(html)
    expect(code == 200 and retry.opened == {own} and retry.forms[own]["original_start_time"] == "09:30",
           "Retry link must open only this owned booking and load the current slot snapshot")
    move(propose(retry.forms[own]), 303, "Owner can move the booking to a new date from a refreshed form")
    expect(current(own) == (*before[:4], next_date, "13:15", "14:45"), "Move must update the same booking row in place")
    code, _, html = user.request("bookings.php?moved=1")
    expect(code == 200 and "Buchung verschoben" in html and "13:15–14:45" in html,
           "Bookings list must confirm the move and show the new interval")
    code, _, old_raw = user.request("get_bookings.php?" + urlencode({"room_id": main, "start_date": date}))
    expect(code == 200 and not any(row.get("id") == own for row in json.loads(old_raw)), "Original slot must be freed")
    code, _, new_raw = user.request("get_bookings.php?" + urlencode({"room_id": main, "start_date": next_date}))
    moved_rows = [row for row in json.loads(new_raw) if row.get("id") == own]
    expect(code == 200 and len(moved_rows) == 1 and moved_rows[0]["start_time"] == "13:15" and moved_rows[0]["end_time"] == "14:45",
           "Moved slot must be visible once with updated hours")
    after_ics = calendar(own)
    expect(before_ics["UID"] == after_ics["UID"], "Moved calendar event must retain its stable UID")
    for label, clock in (("DTSTART", dt.time(13, 15)), ("DTEND", dt.time(14, 45))):
        local = dt.datetime.combine(dt.date.fromisoformat(next_date), clock, berlin)
        expected_utc = local.astimezone(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        expect(after_ics[label] == expected_utc and before_ics[label] != expected_utc, f"Moved calendar must update {label}")

    if concurrent:
        parallel_session = Client(user.base_url)
        parallel_status = parallel_session.login("smoke-user@example.test", env["ROOMIE_SMOKE_PASSWORD"])

        def parallel(a, b):
            barrier = Barrier(2, timeout=5)

            def start(operation):
                barrier.wait()
                return operation()

            with ThreadPoolExecutor(max_workers=2) as pool:
                first = pool.submit(start, a)
                second = pool.submit(start, b)
                return first.result(), second.result()

        fresh = original(stale_race)
        first = propose(fresh, next_date, "09:00", "09:30")
        second = propose(dict(fresh, csrf_token=parallel_status["csrf_token"]), next_date, "09:30", "10:00")
        a, b = parallel(lambda: user.request("reschedule_booking.php", first)[0],
                        lambda: parallel_session.request("reschedule_booking.php", second)[0])
        expect(sorted((a, b)) == [303, 409], "Concurrent moves of one original booking must have exactly one winner")
        winner = first if a == 303 else second
        expect(current(stale_race)[4:] == (winner["date"], winner["start_time"], winner["end_time"]),
               "The losing move cannot overwrite the winner")

        move_fields = propose(original(last_place), next_date, "15:00", "16:00")
        new_fields = {"csrf_token": admin_status["csrf_token"], "selectedWorkspace": race, "time_period": "individuell",
                      "start_date": next_date, "end_date": next_date, "start_time": "15:00", "end_time": "16:00"}
        moved, booked = parallel(lambda: user.request("reschedule_booking.php", move_fields)[0],
                                 lambda: admin.request("process_booking.php", new_fields))
        expect(moved in (303, 409) and booked[0] == 200 and json.loads(booked[2]).get("success") is (moved == 409),
               "A simultaneous move and new booking cannot both claim the last place")
        with closing(sqlite3.connect(db_path)) as db:
            count = db.execute("SELECT COUNT(*) FROM bookings WHERE room_id = ? AND date = ? AND start_time = '15:00'",
                               (race, next_date)).fetchone()[0]
        expect(count == 1, "Exactly one booking must occupy the contested target")
        if moved == 409:
            expect(current(last_place)[4:] == (date, "12:00", "13:00"), "A losing move still owns its old slot")

    print("Reschedule: role, CSRF, started/expired, grant/seat/capacity, stale forms, unchanged row identity, and updated calendar passed"
          + ("; both concurrent races passed" if concurrent else ""), flush=True)


class SeriesHTML(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = {}
        self.actions = {}
        self.links = []
        self.dates = []
        self.form = None
        self.select = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.form = attrs.get("id")
            if self.form:
                self.forms[self.form] = {}
                self.actions[self.form] = attrs.get("action")
        elif tag == "input" and self.form and attrs.get("name"):
            self.forms[self.form][attrs["name"]] = attrs.get("value")
        elif tag == "select":
            self.select = attrs.get("name")
        elif tag == "option" and self.form and self.select and "selected" in attrs:
            self.forms[self.form][self.select] = attrs.get("value")
        elif tag == "a" and attrs.get("href"):
            self.links.append(attrs["href"])
        elif tag == "time" and attrs.get("datetime"):
            self.dates.append(attrs["datetime"])

    def handle_endtag(self, tag):
        if tag == "form":
            self.form = None
        elif tag == "select":
            self.select = None


def smoke_series(command, root, env, date, guest, admin, admin_status, user, user_status, concurrent):
    run(command + ["-r", """
        require 'series_booking.php';
        require_once 'booking_calendar.php';
        foreach ([
            ['2026-03-20', '2026-03-22', '2026-03-29', '081500', '071500'],
            ['2026-10-15', '2026-10-18', '2026-10-25', '071500', '081500'],
        ] as [$clock, $first, $second, $firstUtc, $secondUtc]) {
            $now = new DateTimeImmutable($clock . 'T14:30:00Z');
            $plan = roomieSeriesPlan(['room_id' => '1', 'date' => $first, 'count' => '2',
                'start_time' => '09:15', 'end_time' => '10:45'], $now);
            if ($plan['dates'] !== [$first, $second] || $plan['start'] !== '09:15' || $plan['end'] !== '10:45') {
                fwrite(STDERR, 'Weekly local times or days shifted across daylight-saving transition'); exit(1);
            }
            $items = [];
            foreach ($plan['dates'] as $i => $on) {
                $items[] = ['id' => $i + 1, 'date' => $on, 'start_time' => $plan['start'], 'end_time' => $plan['end'],
                            'room_name' => 'DST Room', 'user_name' => 'Fixture User'];
            }
            $ical = roomieCalendar($items);
            foreach ([[$first, $firstUtc], [$second, $secondUtc]] as [$on, $utc]) {
                if (!str_contains($ical, 'DTSTART:' . str_replace('-', '', $on) . 'T' . $utc . 'Z')) {
                    fwrite(STDERR, 'Calendar did not apply the occurrence date’s DST offset'); exit(1);
                }
            }
        }
    """], root, env)
    db_path = env["ROOMIE_DB_PATH"]
    endpoint = "series_booking.php"
    start_day = dt.date.fromisoformat(date)
    berlin = ZoneInfo("Europe/Berlin")
    today = dt.datetime.now(berlin).date()
    start, end = "10:15", "11:30"

    def occurrences(first, n):
        return [(first + dt.timedelta(weeks=offset)).isoformat() for offset in range(n)]

    days = occurrences(start_day, 3)
    with closing(sqlite3.connect(db_path)) as db, db:
        ids = dict(db.execute("SELECT email, id FROM users"))
        user_id = ids["smoke-user@example.test"]
        admin_id = ids["smoke-admin@example.test"]
        quota_id = db.execute("""
            INSERT INTO users (name, email, password, is_admin)
            SELECT 'Smoke Series Quota', 'smoke-quota@example.test', password, 0 FROM users WHERE id = ?
        """, (user_id,)).lastrowid
        rooms = {}
        for name, capacity, kind in (("Clean", 1, "office"), ("Other members", 2, "shared-desk"),
                                     ("Own", 3, "shared-desk"), ("Restricted", 1, "fk-office"),
                                     ("Recheck", 1, "office"), ("Race", 1, "office"),
                                     ("Quota", 1, "office"), ("Quota existing", 1, "office"),
                                     ("DST", 1, "office")):
            rooms[name] = db.execute("INSERT INTO rooms (name, type, capacity) VALUES (?, ?, ?)",
                                     (f"QA Series {name}", kind, capacity)).lastrowid
        db.executemany("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?)", (
            (admin_id, rooms["Other members"], days[1], "09:00", "12:00"),
            (ids["smoke-other@example.test"], rooms["Other members"], days[1], "10:00", "11:00"),
            (user_id, rooms["Own"], days[1], "10:00", "11:00"),
        ))
        db.executemany("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, '09:00', '10:00')",
                       ((quota_id, rooms["Quota existing"], (start_day + dt.timedelta(days=n)).isoformat()) for n in range(58)))

    def rows(room, owner=None):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT date, start_time, end_time FROM bookings WHERE room_id = ? "
                              "AND (? IS NULL OR user_id = ?) ORDER BY date, start_time, id", (room, owner, owner)).fetchall()

    token = user_status["csrf_token"]
    admin_token = admin_status["csrf_token"]

    def preview(room, *, actor=user, code=200, csrf=token, first=date, window=(start, end), count=3, bookable=True, path=endpoint):
        status, headers, html = actor.request(path, {"stage": "preview", "csrf_token": csrf,
            "room_id": room, "date": first, "start_time": window[0], "end_time": window[1], "count": count})
        expect(status == code, f"Series preview {room}: expected HTTP {code}, got {status}")
        if status == 200:
            expect("no-store" in headers.get("Cache-Control", ""), "Series previews with proof must not be cached")
        document = SeriesHTML(html)
        form = document.forms.get("rb-series-book")
        if not bookable:
            expect(form is None, "A blocked or invalid preview must not offer a commit form")
            return html
        expect(form is not None and form.get("stage") == "book" and form.get("csrf_token") == csrf
               and re.fullmatch(r"[0-9a-f]{64}", form.get("preview_token") or ""), "Valid preview must carry a session-bound proof")
        want_dates = occurrences(dt.date.fromisoformat(first), int(count))
        expect(document.dates == want_dates, "Preview must show every weekly occurrence in chronological order")
        return form

    def commit(form, expected, description, *, actor=user, path=endpoint):
        status, headers, _ = actor.request(path, form)
        expect(status == expected, f"{description}: expected HTTP {expected}, got {status}")
        if status == 303:
            expect(headers.get("Location") == ("login.php" if actor is guest else "bookings.php?series=1"),
                   f"{description}: incorrect redirect")

    room = rooms["Clean"]
    link_query = {"room_id": room, "date": date, "start_time": start, "end_time": end}
    code, _, html = user.request("room_details.php?" + urlencode({"id": room, "date": date, "start_time": start, "end_time": end}))
    expect(code == 200 and "Wöchentlich buchen" in html and any(urlsplit(link).path == endpoint
           and parse_qs(urlsplit(link).query) == {key: [str(value)] for key, value in link_query.items()}
           for link in SeriesHTML(html).links), "Room details must offer a prefilled weekly-series link")
    entry = endpoint + "?" + urlencode(link_query)
    code, _, html = user.request(entry)
    document = SeriesHTML(html)
    form = document.forms.get("rb-series-preview")
    form_action = document.actions.get("rb-series-preview")
    expect(code == 200 and form and form.get("stage") == "preview" and form.get("csrf_token") == token
           and all(form.get(key) == str(value) for key, value in link_query.items())
           and form_action == endpoint + "?" + urlencode({"room_id": room}),
           "Series entry must present a CSRF-protected preview form fixed to the prefilled room and times")
    status, headers, _ = guest.request(entry)
    expect(status in (302, 303) and headers.get("Location") == "login.php", "Series flow must require login")
    commit({"stage": "book", "preview_token": "0" * 64, "csrf_token": token, **link_query, "count": 3}, 409,
           "Direct commit with fabricated proof cannot create a series")
    expect(rows(room) == [], "Direct stage=book must not write without a matching preview")

    for invalid in (1, 13, "2.5"):
        preview(room, code=400, count=invalid, bookable=False)
    preview(room, code=400, window=("10:07", "11:30"), bookable=False)
    preview(room, code=400, window=("10:15", "10:00"), bookable=False)
    preview(room, code=400, first=(today + dt.timedelta(days=84)).isoformat(), count=2, bookable=False)
    preview(room, code=403, csrf="", bookable=False)
    preview(room, count=12)
    expect(rows(room) == [], "Even a maximum-length preview must not write a booking")

    for kind in ("Other members", "Own"):
        existing = rows(rooms[kind])
        html = preview(rooms[kind], bookable=False)
        expect('id="rb-series-results"' in html and "Belegt" in html and "Frei" in html,
               "Occupied series preview should identify both free and blocked dates instead of offering a partial commit")
        expect(rows(rooms[kind]) == existing, "A conflicting preview must not change any existing occurrence")

    restricted = rooms["Restricted"]
    preview(restricted, code=403, bookable=False)
    preview(restricted, actor=admin, csrf=admin_token, code=403, bookable=False)
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("INSERT INTO permissions (user_id, room_id) VALUES (?, ?)", (user_id, restricted))
    granted = preview(restricted)
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("DELETE FROM permissions WHERE user_id = ? AND room_id = ?", (user_id, restricted))
    commit(granted, 403, "Grant revoked after preview must block commit")
    expect(rows(restricted) == [], "No dates may be written after access is revoked")

    def single(room, on):
        return admin.request("process_booking.php", {"selectedWorkspace": room, "start_date": on, "end_date": on,
            "time_period": "individuell", "start_time": start, "end_time": end, "csrf_token": admin_token})

    recheck = rooms["Recheck"]
    recheck_form = preview(recheck)
    status, _, raw = single(recheck, days[-1])
    expect(status == 200 and json.loads(raw).get("success") is True, "A different viewer should be able to claim a date after preview")
    commit(recheck_form, 409, "New last-day conflict must roll back entire series")
    expect(rows(recheck, user_id) == [] and rows(recheck, admin_id) == [(days[-1], start, end)],
           "A conflict on the last date must leave no earlier occurrences and preserve the other booking")

    quota = Client(user.base_url)
    quota_status = quota.login("smoke-quota@example.test", env["ROOMIE_SMOKE_PASSWORD"])
    quota_token = quota_status["csrf_token"]
    quota_room = rooms["Quota"]
    quota_form = preview(quota_room, actor=quota, csrf=quota_token, count=2)
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, '09:00', '10:00')",
                   (quota_id, rooms["Quota existing"], (start_day + dt.timedelta(days=58)).isoformat()))
    commit(quota_form, 409, "Quota exceeded after preview must reject whole series", actor=quota)
    preview(quota_room, actor=quota, csrf=quota_token, count=2, bookable=False)
    expect(rows(quota_room) == [], "A series cannot exceed the active bookings quota")

    clean = preview(room, path=form_action)
    proof_to_reject = preview(room, count=2)
    commit(dict(proof_to_reject, room_id=rooms["Recheck"], date=days[1], start_time="13:00", end_time="14:00", count=2),
           400, "A mismatched plan must not be accepted with another preview's proof")
    expect(rows(room) == [] and rows(recheck, user_id) == [], "Neither preview nor mismatched plan should write bookings")
    commit(dict(clean, csrf_token=admin_token), 409, "A preview proof must not authorize another account's session", actor=admin)
    commit(dict(clean, csrf_token=""), 403, "Confirmation must require a valid CSRF token")
    if concurrent:
        barrier = Barrier(2, timeout=5)
        race = rooms["Race"]
        race_form = preview(race)

        def start_together(action):
            barrier.wait()
            return action()

        # A separate user can claim the last occurrence while the first confirms the series.
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(start_together, lambda: user.request(endpoint, race_form)[0])
            second = pool.submit(start_together, lambda: single(race, days[-1]))
            outcome, single_reply = first.result(), second.result()
        expect(outcome in (303, 409) and single_reply[0] == 200 and json.loads(single_reply[2]).get("success") is (outcome == 409),
               "Exactly one competing last-place operation should succeed")
        expect(rows(race, user_id) == ([(on, start, end) for on in days] if outcome == 303 else []),
               "Concurrent series confirmation must create all dates or none")

    commit(clean, 303, "Confirm exactly the previously previewed series", path=form_action)
    expect(rows(room) == [(on, start, end) for on in days], "Saved series must match the displayed dates and hours")
    commit(clean, 409, "Replaying a consumed preview proof cannot duplicate bookings")
    expect(len(rows(room)) == 3, "A successful series creates exactly one reservation per occurrence")
    code, _, html = user.request("bookings.php?series=1")
    expect(code == 200 and "Buchungsserie gespeichert" in html and "QA Series Clean" in html,
           "Successful series redirect must show confirmation and the new bookings")

    # Run the full HTTP+ICS DST case whenever a Berlin offset change is inside the real 90-day horizon.
    dst_tested = False
    for offset in range(1, 84):
        first = today + dt.timedelta(days=offset)
        second = first + dt.timedelta(weeks=1)
        before = dt.datetime.combine(first, dt.time.fromisoformat(start), berlin)
        after = dt.datetime.combine(second, dt.time.fromisoformat(start), berlin)
        if before.utcoffset() == after.utcoffset():
            continue
        dst = rooms["DST"]
        form = preview(dst, first=first.isoformat(), count=2)
        commit(form, 303, "Book two weekly instances across a Berlin clock change")
        expect(rows(dst) == [(on, start, end) for on in occurrences(first, 2)],
               "Stored local time must not shift across daylight-saving transition")
        with closing(sqlite3.connect(db_path)) as db:
            ids = [row[0] for row in db.execute("SELECT id FROM bookings WHERE room_id = ? ORDER BY date", (dst,))]
        for booking_id, at in zip(ids, (before, after)):
            status, _, ics = user.request(f"bookings.php?download=calendar&booking_id={booking_id}")
            utc = at.astimezone(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
            expect(status == 200 and f"DTSTART:{utc}" in ics.split("\r\n"), "Each ICS instance must use its own DST offset")
        dst_tested = True
        break
    print("Weekly series: preview/commit, CSRF, template proof, quota, capacity, permissions, replay, rollback, booking list and DST planner passed"
          + ("; concurrent last-seat race passed" if concurrent else "")
          + ("; Berlin DST HTTP+ICS passed" if dst_tested else "; HTTP DST transition outside 90-day window"), flush=True)


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
                smoke_reschedule(env, date, guest, admin, admin_status, user, user_status, concurrent)
                smoke_series(command, root, env, date, guest, admin, admin_status, user, user_status, concurrent)
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
