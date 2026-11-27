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
import shlex
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
        self.time_options = {}
        self.available_until = {}
        self.map_back = None
        self.select = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input":
            self.inputs.append(attrs)
        elif tag == "select":
            self.select = attrs.get("name")
        elif tag == "option" and self.select:
            self.time_options.setdefault(self.select, {})[attrs.get("value")] = "disabled" not in attrs
            if "selected" in attrs:
                self.range[self.select] = attrs.get("value")
        elif tag == "div" and "data-available-until" in attrs:
            self.available_until = json.loads(attrs["data-available-until"])
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
        // Berlin is UTC+2 on this date. Instant presets stay bookable until (but not at) their end;
        // approval presets are already closed at their exact start.
        foreach ([
            ['06:59:59', '09:00', '12:00', true, true],
            ['07:00:00', '09:00', '12:00', true, false],
            ['09:59:59', '09:00', '12:00', true, false],
            ['10:00:00', '09:00', '12:00', false, false],
            ['10:00:01', '09:00', '12:00', false, false],
            ['14:59:59', '13:00', '17:00', true, false],
            ['15:00:00', '13:00', '17:00', false, false],
            ['15:00:00', '09:00', '17:00', false, false],
        ] as [$utc, $start, $end, $instantOpen, $approvalOpen]) {
            $clock = new DateTimeImmutable('2026-06-01T' . $utc . 'Z');
            if (roomieBookingPresetIsOpen($date, $start, $end, $clock) !== $instantOpen
                || roomieBookingPresetIsOpen($date, $start, $end, $clock, true) !== $approvalOpen
                || !roomieBookingPresetIsOpen($date->modify('+1 day'), $start, $end, $clock, true)) {
                fwrite(STDERR, 'Unexpected preset state at ' . $utc); exit(1);
            }
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
        fixture_bookings = {}
        for who, room, on, start, end, links in (
            (colleague_id, "Gap", date, "09:00", "10:00:00", "seat"),
            (colleague_id, "Gap", date, "11:00:00", "17:00", "room"),
            (colleague_id, "Capacity", date, "10:00:00", "10:30:00", "seat"),
            (admin_id, "Capacity", date, "10:30", "11:00", "both"),
            (user_id, "Own overlap", date, "12:00", "12:30", "seat"),
            (colleague_id, "Range", next_date, "09:00", "17:00", "room"),
            (colleague_id, "Restricted", date, "15:00", "16:00", "seat"),
        ):
            seat = None
            if links in ("seat", "both"):
                seat = db.execute("INSERT INTO seats (room_id, top, left_pos) VALUES (?, '0', '0')",
                                  (rooms["Race" if links == "both" else room],)).lastrowid
            fixture_bookings[room] = db.execute(
                "INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)",
                (who, rooms[room] if links != "seat" else None, seat, on, start, end)).lastrowid

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
    code, _, raw = user.request('get_bookings.php?' + urlencode({"start_date": date}))
    expect(code == 200 and all(item["room_id"] != rooms["Restricted"] for item in json.loads(raw)),
           "Bulk availability may not leak a restricted room through a legacy seat")
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
    expect(html.index('id="rb-room-booking-title"') < html.index('id="rb-room-occupancy-title"'),
           "The primary booking panel must precede the potentially long occupancy list")
    details = BookingDetailsHTML(html)
    periods = {entry.get("value"): entry for entry in details.inputs if entry.get("name") == "time_period"}
    expect(all(value in periods and "disabled" in periods[value] for value in ("ganzerTag", "vormittags", "nachmittags")),
           "All three presets should be unavailable on a room with only a custom gap")
    expect("individuell" in periods and "disabled" not in periods["individuell"] and "checked" in periods["individuell"]
           and details.range == {"start_time": "10:00", "end_time": "10:15"},
           "The booking form must select the earliest usable custom gap when presets are full")
    expect({time for time, enabled in details.time_options["start_time"].items() if enabled}
           == {"10:00", "10:15", "10:30", "10:45"}
           and details.available_until == {time: "11:00" for time in ("10:00", "10:15", "10:30", "10:45")}
           and not details.time_options["end_time"]["11:15"],
           "The custom picker must allow only a continuous free window even with legacy seconds")
    preferred = {"start_time": "10:15", "end_time": "10:45"}
    code, _, html = user.request(gap_url + "&" + urlencode({**preferred, "free": "1"}))
    linked = BookingDetailsHTML(html)
    target = urlsplit(linked.map_back or "")
    expect(code == 200 and linked.range == preferred and target.path == "raumplan.php"
           and parse_qs(target.query) == {key: [value] for key, value in {**preferred, "date": date, "free": "1"}.items()},
           "Opening room details and returning must preserve the map's date, times and free filter")
    exact_preset = {"start_time": "09:00", "end_time": "12:00"}
    available_url = f"room_details.php?id={rooms['Gap']}&date={date}"
    code, _, html = user.request(available_url + "&" + urlencode(exact_preset))
    exact_blocked = BookingDetailsHTML(html)
    expect(code == 200 and not any("checked" in entry for entry in exact_blocked.inputs
           if entry.get("name") == "time_period") and "gewählte Zeitraum ist nicht verfügbar" in html,
           "An unavailable linked preset must not preselect an alternative")
    occupied = {"start_time": "10:45", "end_time": "11:15"}
    code, _, html = user.request(gap_url + "&" + urlencode({**occupied, "free": "1"}))
    linked = BookingDetailsHTML(html)
    expect(code == 200 and linked.range == details.range
           and "Der im Raumplan gewählte Zeitraum ist nicht verfügbar." in html
           and parse_qs(urlsplit(linked.map_back or "").query)["end_time"] == ["11:15"],
           "A deep-linked unavailable interval must not be selected or silently replaced")
    code, _, raw = user.request('get_monthly_bookings.php?' + urlencode({
        "room_id": rooms["Own overlap"], "year": date[:4], "month": int(date[5:7]),
    }))
    expect(code == 200 and json.loads(raw) == [date], "A seat-only reservation must appear in monthly occupancy")
    for room, status, days in (("Capacity", 200, [date]), ("Race", 200, []), ("Restricted", 404, None)):
        code, _, raw = user.request('get_monthly_bookings.php?' + urlencode({
            "room_id": rooms[room], "year": date[:4], "month": int(date[5:7]),
        }))
        expect(code == status and (days is None or json.loads(raw) == days),
               f"Monthly occupancy must preserve scope and distinct dates: {room}")
    code, _, raw = user.request('get_bookings.php?' + urlencode({"room_id": rooms["Race"], "start_date": date}))
    expect(code == 200 and json.loads(raw) == [],
           "An explicit room must take precedence over a seat in a different room")
    code, _, html = user.request('bookings.php?' + urlencode({"date": date}))
    expect(code == 200 and 'QA Flex Own overlap' in html
           and f'download=calendar&amp;booking_id={fixture_bookings["Own overlap"]}' in html,
           "The owner must see a seat-only booking and its calendar action")
    code, _, calendar = user.request(f'bookings.php?download=calendar&booking_id={fixture_bookings["Own overlap"]}')
    expect(code == 200 and "SUMMARY:QA Flex Own overlap" in calendar, "Seat-only ICS must use the effective room name")
    code, _, _ = admin.request("update_room.php", content_type="application/json", csrf=admin_token,
                               data=json.dumps({"id": rooms["Own overlap"], "name": "QA Flex Own overlap",
                                   "number": "", "type": "shared-desk", "capacity": 2, "amenities": []}).encode())
    expect(code == 409, "Reducing capacity must also respect a seat-only future booking")
    code, _, html = user.request(f"room_details.php?id={rooms['Own overlap']}&date={date}")
    own_picker = BookingDetailsHTML(html)
    expect(code == 200 and own_picker.available_until.get("11:45") == "12:00"
           and "12:00" not in own_picker.available_until and own_picker.available_until.get("12:30") == "17:00",
           "The picker must exclude the viewer's own overlap even if the room has space")
    code, _, html = user.request(f"room_details.php?id={rooms['Capacity']}&date={date}")
    expect(code == 200 and BookingDetailsHTML(html).available_until.get("10:00") == "17:00",
           "Available spare capacity across adjacent bookings must remain selectable")
    submit(fields("Gap"), expected=True, description="Custom interval fits a gap between legacy bookings")
    submit(fields("Gap", "09:45", "10:15"), actor=admin, token=admin_token, expected=False,
           description="Overlapping a booking by 15 minutes blocks a single-place room")
    _, own_conflict = submit(fields("Own overlap", "12:15", "12:45"), expected=False,
                             description="Own seat-only overlap is blocked despite spare capacity")
    expect("bereits gebucht" in own_conflict.get("error", ""), "Expect own overlap, not a capacity rejection")
    submit(fields("Own overlap", "12:30", "12:45"), expected=True,
           description="Own adjacent booking is permitted")
    submit(fields("Capacity"), expected=True,
           description="A double-linked reservation counts once and adjacent reservations do not accumulate")
    _, capacity_conflict = submit(fields("Capacity", "10:15", "10:30"), actor=admin, token=admin_token, expected=False,
                                  description="A third overlapping booking must fail when one place is seat-only")
    expect("Kapazität" in capacity_conflict.get("error", "") and capacity_conflict.get("code") == "ROOM_CAPACITY",
           "Expect typed capacity, not an own-booking rejection")
    code, _, raw = user.request('get_bookings.php?' + urlencode({"room_id": rooms["Capacity"], "start_date": date}))
    capacity_rows = json.loads(raw)
    expect(code == 200 and len(capacity_rows) == 3 and all(row["room_id"] == rooms["Capacity"] for row in capacity_rows),
           "Availability should return the legacy, double-linked and current bookings each once")
    code, _, html = admin.request(f"room_details.php?id={rooms['Capacity']}&date={date}")
    expect(code == 200 and "10:15" not in BookingDetailsHTML(html).available_until,
           "Details should not offer a slot when effective occupancy reaches capacity for a non-overlapping viewer")
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
    print("Flexible bookings: validation, preset clock boundaries, legacy seat occupancy, peak capacity, "
          "own overlap/adjacency, rollback, room grants, private map data and ICS passed"
          + ("; concurrent last place passed" if concurrent else ""), flush=True)


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
        own = booking(who, None, date, "09:00:00", "10:00:00", main_seat)
        conflicting = booking(other, None, next_date, "10:15", "11:15", seat(main))
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
    expect(code == 200 and "Verschieben" in html and {key: value for key, value in own_forms.get(own, {}).items() if key != "original_snapshot"}
           == {key: str(value) for key, value in original(own).items()}
           and re.fullmatch("[0-9a-f]{64}", own_forms[own]["original_snapshot"]),
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


class RoomChangeHTML(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.active, self.fields, self.options = False, {}, set()
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.active = attrs.get("id") == "rb-change-room-form"
        elif self.active and tag == "input" and attrs.get("type") == "hidden":
            self.fields[attrs["name"]] = attrs["value"]
        elif self.active and tag == "option" and attrs.get("value"):
            self.options.add(int(attrs["value"]))

    def handle_endtag(self, tag):
        if tag == "form":
            self.active = False


def smoke_room_change(command, root, env, date, guest, admin, admin_status, user, user_status, concurrent):
    db_path = env["ROOMIE_DB_PATH"]
    today = dt.datetime.now(ZoneInfo("Europe/Berlin")).date().isoformat()
    with closing(sqlite3.connect(db_path)) as db, db:
        users = dict(db.execute("SELECT email,id FROM users"))
        who, other = [users[f"smoke-{role}@example.test"] for role in ("user", "admin")]

        def room(label, kind="office", capacity=1):
            return db.execute("INSERT INTO rooms (name,type,capacity) VALUES (?,?,?)",
                              ("QA Room Change " + label, kind, capacity)).lastrowid

        def book(room_id, owner=who, begins="10:00", ends="11:00", seat=None, on=date):
            return db.execute("INSERT INTO bookings (room_id,user_id,date,start_time,end_time,seat_id) "
                              "VALUES (?,?,?,?,?,?)", (room_id, owner, on, begins, ends, seat)).lastrowid

        source, good, race, dynamic, second, blocked, busy, mandatory = (
            room(s) for s in ("Source", "Good", "Race", "Dynamic", "Second", "Blocked", "Busy", "Needs approval"))
        restricted = room("Hidden", "fk-office")
        group = room("Group")
        own_target = room("Own overlap", capacity=2)
        bid = book(source)
        race_booking = book(source, begins="11:15", ends="12:00")
        race_booking2 = book(source, begins="12:15", ends="13:00")
        book(good, other, "09:00", "10:00")  # Adjacency must be allowed.
        book(busy, other, "10:45", "11:00")  # A partial interval cannot be offered.
        book(own_target)
        someone_elses = book(second, other, "13:00", "14:00")
        seat = db.execute("INSERT INTO seats (room_id,top,left_pos) VALUES (?,'0','0')", (source,)).lastrowid
        seat_booking = book(source, begins="14:00", ends="15:00", seat=seat)
        started = book(source, begins="00:00", ends="09:00", on=today)
        approval_origin = book(source, begins="15:00", ends="15:30")
        db.execute("INSERT INTO booking_requests (user_id,room_id,date,start_time,end_time,status,resolved_at,booking_id) "
                   "VALUES (?,?,?,?,?,'approved',CURRENT_TIMESTAMP,?)",
                   (who, source, date, "15:00", "15:30", approval_origin))
        series_booking = book(source, begins="15:30", ends="16:00")
        series_id = db.execute("INSERT INTO booking_series (user_id) VALUES (?)", (who,)).lastrowid
        db.execute("INSERT INTO booking_series_members (booking_id,series_id) VALUES (?,?)", (series_booking, series_id))
        db.execute("INSERT INTO room_booking_approval_policies VALUES (?)", (mandatory,))
        db.execute("INSERT INTO room_blocks (room_id,date,start_time,end_time) VALUES (?,?,'10:15','10:30')",
                   (blocked, date))

    run(command + ["-r", """
        require 'database.php';
        require 'entra_groups.php';
        $db = getDatabaseConnection();
        roomieEntraSchema($db);
        roomieEntraGroupSchema($db);
        $db->exec("INSERT INTO roomie_entra_booking_groups (object_id,label,tenant_id) VALUES ('synthetic-room-change','QA','tenant')");
        $db->prepare("INSERT INTO roomie_entra_room_groups (room_id,group_id) VALUES (?,?)")->execute(
            [(int) $argv[1], (int) $db->lastInsertId()]);
    """, str(group)], root, env)

    endpoint = "change_booking_room.php"

    def route(id_):
        return f"{endpoint}?id={id_}"

    def get(id_):
        code, headers, html = user.request(route(id_))
        expect(code == 200 and headers.get("Cache-Control") == "private, no-store", "Expected private room change page")
        return RoomChangeHTML(html), html

    def state(id_):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT room_id,user_id,date,start_time,end_time,seat_id FROM bookings WHERE id=?", (id_,)).fetchone()

    listed, html = get(bid)
    forbidden = {source, blocked, busy, mandatory, restricted, group, own_target}
    expect({good, race, second, dynamic} <= listed.options and not listed.options & forbidden,
           "Room change offers only directly bookable, unblocked targets for the full booking duration")
    expect("QA Room Change Hidden" not in html, "Unauthorized room names must not be included")
    expect(guest.request(route(bid))[0] == 303 and admin.request(route(bid))[0] == 404
           and user.request(route(someone_elses))[0] == 404, "Only the signed-in booking owner can see room changes")
    for id_ in (seat_booking, started, approval_origin, series_booking):
        expect(user.request(route(id_))[0] == 409, "Seat, started, approval-origin and series bookings are excluded")

    baseline = state(bid)

    def target(id_, **changes):
        return dict(listed.fields, target_room_id=id_, **changes)

    expect(user.request(endpoint, target(good, csrf_token=""))[0] == 403, "Missing move CSRF must reject")
    expect(admin.request(endpoint, target(good, csrf_token=admin_status["csrf_token"]))[0] == 404,
           "Admin is not authorized to move a booking owned by another user")
    for id_ in sorted(forbidden):
        code, _, text = user.request(endpoint, target(id_))
        expect(code == 409 and "Deine Buchung bleibt bestehen" in text and state(bid) == baseline,
               f"Unavailable target {id_} must not release the source")
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("INSERT INTO bookings (room_id,user_id,date,start_time,end_time) VALUES (?,?,?,'10:00','11:00')",
                   (dynamic, other, date))
    expect(user.request(endpoint, target(dynamic))[0] == 409 and state(bid) == baseline,
           "A target filled after reading the options must not release the source")

    cal = f"bookings.php?download=calendar&booking_id={bid}"
    before_ics = user.request(cal)[2]
    stale_clock = MoveFormsHTML(user.request(f"bookings.php?edit={bid}")[2]).forms[bid]
    changed = user.request(endpoint, target(good))
    expect(changed[0] == 303 and changed[1].get("Location") == f"bookings.php?focus={bid}#booking-{bid}"
           and state(bid) == (good, *baseline[1:]), "The same owned booking must move in place without schedule changes")
    code, _, html = user.request(f"bookings.php?focus={bid}")
    expect(code == 200 and "Raum gewechselt." in html and "QA Room Change Good" in html and not MoveFormsHTML(html).opened,
           "Redirect must confirm the move without opening date/time inputs")
    after_ics = user.request(cal)[2]
    get_uid = lambda value: re.search(r"(?m)^UID:([^\r\n]+)", value).group(1)
    expect("SUMMARY:QA Room Change Good" in after_ics and get_uid(before_ics) == get_uid(after_ics),
           "Updated calendar export must retain the same event UID")
    expect(user.request("reschedule_booking.php", dict(stale_clock, date=date, start_time="09:00", end_time="09:30"))[0] == 409
           and state(bid) == (good, *baseline[1:]), "A stale time-only form cannot act on the newly selected room")

    refreshed, _ = get(bid)
    expect(user.request(endpoint, dict(refreshed.fields, target_room_id=source))[0] == 303
           and state(bid) == baseline, "A fresh form may move back to the old room")
    expect(user.request(endpoint, target(good))[0] == 409 and state(bid) == baseline,
           "Returning to the same source must not make an old move form replayable")

    if concurrent:
        second_session = Client(user.base_url)
        second_status = second_session.login("smoke-user@example.test", env["ROOMIE_SMOKE_PASSWORD"])

        def parallel(a, b):
            barrier = Barrier(2, timeout=5)
            def begin(action):
                barrier.wait()
                return action()
            with ThreadPoolExecutor(max_workers=2) as pool:
                first_job, second_job = pool.submit(begin, a), pool.submit(begin, b)
                return first_job.result(), second_job.result()

        existing, _ = get(race_booking)
        a, b = parallel(lambda: user.request(endpoint, dict(existing.fields, target_room_id=good))[0],
                        lambda: second_session.request(endpoint, dict(existing.fields, target_room_id=second,
                                                      csrf_token=second_status["csrf_token"]))[0])
        expect(sorted((a, b)) == [303, 409] and state(race_booking)[0] == (good if a == 303 else second),
               "Concurrent room changes using one source form must have exactly one winner")

        existing, _ = get(race_booking2)
        fields = {"csrf_token": admin_status["csrf_token"], "selectedWorkspace": race, "time_period": "individuell",
                  "start_date": date, "end_date": date, "start_time": "12:15", "end_time": "13:00"}
        moved, created = parallel(lambda: user.request(endpoint, dict(existing.fields, target_room_id=race))[0],
                                  lambda: admin.request("process_booking.php", fields))
        expect(moved in (303, 409) and json.loads(created[2]).get("success") is (moved == 409)
               and state(race_booking2)[0] == (race if moved == 303 else source),
               "Move and new booking must contend for the same last place without losing the source")

    print("Room change: visibility, approval/ACL/group/block/capacity, owner/CSRF, stable booking and ICS, stale and concurrent writes passed",
          flush=True)


def smoke_room_watches(command, root, env, date, guest, admin, admin_status, user, user_status):
    db_path = env["ROOMIE_DB_PATH"]
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("DROP TABLE room_watches")  # Synthetic old installation.
        users = dict(db.execute("SELECT email,id FROM users"))
        owner, blocker = [users[f"smoke-{role}@example.test"] for role in ("user", "admin")]

        def add_room(name, kind="office"):
            return db.execute("INSERT INTO rooms (name,type,capacity) VALUES (?,?,1)", (name, kind)).lastrowid

        atlas = add_room("QA Watch Atlas")
        restricted = add_room("QA Watch Private", "fk-office")
        approval = add_room("QA Watch Approval")
        db.execute("INSERT INTO permissions (room_id,user_id) VALUES (?,?)", (restricted, owner))
        db.execute("INSERT INTO room_booking_approval_policies VALUES (?)", (approval,))
        conflict = db.execute("INSERT INTO bookings (room_id,user_id,date,start_time,end_time) "
                              "VALUES (?,?,?,'10:00','10:30')", (atlas, blocker, date)).lastrowid
        db.execute("INSERT INTO room_blocks (room_id,date,start_time,end_time) VALUES (?,?,'10:30','11:00')", (atlas, date))
        db.execute("INSERT INTO room_blocks (room_id,date,start_time,end_time) VALUES (?,?,'10:00','11:00')", (restricted, date))

    endpoint = "room_watch_action.php"
    worker = command + ["build-files/room-watch-worker.php"]
    run(worker, root, env)  # Backward-compatible when the optional schema is absent.
    fields = {"action": "subscribe", "room_id": atlas, "date": date, "start_time": "10:00",
              "end_time": "11:00", "csrf_token": user_status["csrf_token"]}
    route = f"room_details.php?id={atlas}&date={date}&start_time=10%3A00&end_time=11%3A00"

    def rows():
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT id,room_id,notified_at FROM room_watches ORDER BY id").fetchall()

    def bookings():
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT id, room_id,user_id,date,start_time,end_time FROM bookings ORDER BY id").fetchall()

    expect("Bei Freiwerden melden" in user.request(route)[2], "A busy exact future interval offers its watch")
    expect(guest.request(endpoint, fields)[0] == 303 and user.request(endpoint, dict(fields, csrf_token=""))[0] == 403,
           "Watch mutation needs a signed-in account and CSRF")
    expect(user.request(endpoint, dict(fields, date="2020-01-01"))[1].get("Location") == "index.php#rm-room-watches"
           and user.request(endpoint, dict(fields, room_id=approval))[1].get("Location") == "index.php#rm-room-watches",
           "Past dates and approval-only rooms cannot be subscribed")
    baseline = bookings()
    first = user.request(endpoint, fields)
    expect(first[0] == 303 and first[1].get("Location") == route + "#rb-room-watch"
           and "Frei-Meldung aktiv." in user.request(route)[2], "A valid watch returns to its original exact interval")
    prior = rows()
    expect(len(prior) == 1 and prior[0][1:] == (atlas, None), "Only one pending watch was inserted")
    watch_id = prior[0][0]
    expect(user.request(endpoint, fields)[0] == 303 and rows() == prior and bookings() == baseline,
           "Repeated subscribe is idempotent and never reserves anything")
    foreign = {"action": "remove", "watch_id": watch_id, "csrf_token": admin_status["csrf_token"]}
    expect(admin.request(endpoint, foreign)[0] == 303 and rows() == prior
           and 'rm-room-watches-title' not in admin.request("index.php")[2], "Other users cannot read or dismiss a watch")

    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("DELETE FROM bookings WHERE id=?", (conflict,))
    run(worker, root, env)
    expect(rows() == prior and "Wartet auf freien Zeitraum" in user.request("index.php")[2],
           "Partial availability with a maintenance block does not emit a notice")
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("DELETE FROM room_blocks WHERE room_id=?", (atlas,))
    baseline = bookings()
    page = user.request("index.php")[2]  # No cron required for the signed-in owner.
    ready = rows()
    expect(len(ready) == 1 and ready[0][2] is not None and "War frei" in page and "Verfügbarkeit prüfen" in page,
           "Opening the dashboard evaluates the full interval and shows the private notice")
    run(worker, root, env)
    expect(rows() == ready and bookings() == baseline, "Repeated checks do not emit twice or change bookings")

    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("INSERT INTO bookings (room_id,user_id,date,start_time,end_time) VALUES (?,?,?,'10:45','11:00')",
                   (atlas, blocker, date))
    expect("War frei" in user.request("index.php")[2] and "gewählte Zeitraum ist nicht verfügbar" in user.request(route)[2],
           "A notice is historical and the link checks the current interval")
    removed = user.request(endpoint, dict(foreign, csrf_token=user_status["csrf_token"]))
    expect(removed[0] == 303 and rows() == [], "Owner can dismiss a notice")

    expect(user.request(endpoint, dict(fields, room_id=restricted))[0] == 303 and len(rows()) == 1,
           "Granted members may watch a restricted room")
    run(command + ["-r", """
        require 'database.php';
        require 'entra_groups.php';
        $db = getDatabaseConnection();
        roomieEntraSchema($db);
        roomieEntraGroupSchema($db);
        $db->exec("INSERT INTO roomie_entra_booking_groups (object_id,label,tenant_id) VALUES ('synthetic-room-watch','QA','tenant')");
        $db->prepare("INSERT INTO roomie_entra_room_groups (room_id,group_id) VALUES (?,?)")
            ->execute([(int) $argv[1], (int) $db->lastInsertId()]);
        $db->prepare("DELETE FROM room_blocks WHERE room_id = ?")->execute([(int) $argv[1]]);
    """, str(restricted)], root, env)
    run(worker, root, env)
    expect(rows() == [] and "QA Watch Private" not in user.request("index.php")[2],
           "Entra group revocation prevents a now-free interval from notifying")
    expect(user.request(endpoint, dict(fields, room_id=restricted))[1].get("Location") == "index.php#rm-room-watches"
           and rows() == [], "A direct POST cannot bypass Entra room policy")
    print("Room watches: lazy schema, owner/CSRF, exact full-slot capacity and blocks, dashboard fallback, worker idempotency, dismissal and group denial passed",
          flush=True)


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
        seat = db.execute("INSERT INTO seats (room_id, top, left_pos) VALUES (?, '0', '0')",
                          (rooms["Other members"],)).lastrowid
        db.execute("INSERT INTO bookings (user_id, seat_id, date, start_time, end_time) VALUES (?, ?, ?, '09:00', '12:00')",
                   (admin_id, seat, days[1]))
        db.executemany("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, ?, ?)", (
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


def smoke_room_block_upgrade(command, root, env, scratch):
    existing = scratch / "pre-booking-upgrade.sqlite"
    new_tables = ("room_blocks", "booking_requests", "room_booking_approval_policies")
    with closing(sqlite3.connect(env["ROOMIE_DB_PATH"])) as source, closing(sqlite3.connect(existing)) as copy:
        expect(all(source.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
                   for table in new_tables), "Fresh setup must install the maintenance and approvals tables")
        source.backup(copy)
        for table in new_tables:
            copy.execute(f"DROP TABLE {table}")
        copy.execute("""INSERT INTO bookings (user_id, room_id, date, start_time, end_time)
            VALUES ((SELECT MIN(id) FROM users), (SELECT MIN(id) FROM rooms), '2030-06-17', '10:00:00', '11:00:00')""")
        copy.commit()
        before = {table: copy.execute(f"SELECT * FROM {table} ORDER BY id").fetchall()
                  for table in ("users", "rooms", "bookings")}
    existing.chmod(0o600)
    for _ in range(2):
        run(command + [str(root / "schema_hardening.php")], root, dict(env, ROOMIE_DB_PATH=str(existing)))
    with closing(sqlite3.connect(existing)) as db:
        expect(all(db.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0] == 0 for table in new_tables),
               "Upgrade creates empty maintenance and approval tables without enabling any room policy")
        expect(db.execute("PRAGMA foreign_key_check").fetchall() == [], "Upgrade must preserve referential integrity")
        expect(before == {table: db.execute(f"SELECT * FROM {table} ORDER BY id").fetchall() for table in before},
               "Reapplying the additive upgrade must preserve users, rooms and their bookings exactly")
    print("Booking schema: fresh installation, additive upgrade with approval default off and safe repeat passed", flush=True)


def smoke_room_blocks(env, date, guest, admin, admin_status, user, user_status, concurrent):
    db_path = env["ROOMIE_DB_PATH"]
    endpoint = "admin_room_blocks.php"
    later = (dt.date.fromisoformat(date) + dt.timedelta(days=1)).isoformat()
    second_week = (dt.date.fromisoformat(date) + dt.timedelta(days=7)).isoformat()
    with closing(sqlite3.connect(db_path)) as db, db:
        users = dict(db.execute("SELECT email, id FROM users"))
        user_id = users["smoke-user@example.test"]
        other_id = users["smoke-other@example.test"]
        rooms = {}
        for label, kind in (("Open", "office"), ("Occupied", "office"), ("Restricted", "fk-office"),
                            ("Series", "office"), ("Race", "office")):
            rooms[label] = db.execute("INSERT INTO rooms (name, type, capacity) VALUES (?, ?, 2)",
                                      (f"QA Block {label}", kind)).lastrowid
        occupied_seat = db.execute("INSERT INTO seats (room_id, top, left_pos) VALUES (?, '0', '0')",
                                   (rooms["Occupied"],)).lastrowid
        occupied = db.execute("INSERT INTO bookings (user_id, seat_id, date, start_time, end_time) VALUES (?, ?, ?, '10:15:00', '10:45:00')",
                              (other_id, occupied_seat, date)).lastrowid
        move_id = db.execute("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, '15:00', '16:00')",
                             (user_id, rooms["Open"], date)).lastrowid

    def rows(table, room):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute(f"SELECT id, date, start_time, end_time FROM {table} WHERE room_id = ? ORDER BY date, start_time, id",
                              (room,)).fetchall()

    def create(room, day=date, start="10:00", end="11:00", csrf=admin_status["csrf_token"]):
        return {"action": "create", "room_id": room, "date": day, "start_time": start, "end_time": end,
                "kind": "maintenance", "csrf_token": csrf}

    def add(room, day=date):
        code, headers, html = admin.request(endpoint, create(room, day))
        expect(code == 303 and (headers.get("Location") or "").startswith(endpoint),
               f"Admin should be able to create a maintenance block: HTTP {code}")
        entries = rows("room_blocks", room)
        expect(len(entries) == 1 and entries[0][1:] == (day, "10:00", "11:00"), "Maintenance block should persist its exact window")
        return entries[0][0]

    def submit(room, start="10:15", end="10:45", *, first=date, last=None, period="individuell"):
        code, _, raw = user.request("process_booking.php", {"csrf_token": user_status["csrf_token"],
            "selectedWorkspace": room, "time_period": period, "start_date": first, "end_date": last or first,
            "start_time": start, "end_time": end})
        expect(code == 200, f"Booking outcome should have existing HTTP 200 JSON contract, got {code}")
        return json.loads(raw)

    def preview(room):
        code, _, html = user.request("series_booking.php", {"stage": "preview", "csrf_token": user_status["csrf_token"],
            "room_id": room, "date": date, "start_time": "10:15", "end_time": "10:45", "count": 2})
        expect(code == 200, f"Maintenance-aware preview should render status rows, got {code}")
        return SeriesHTML(html).forms.get("rb-series-book"), html

    open_room = rooms["Open"]
    expect(user.request(endpoint, create(open_room, csrf=user_status["csrf_token"]))[0] == 403,
           "Regular members may not create a room block")
    expect(admin.request(endpoint, create(open_room, csrf=""))[0] == 403, "Maintenance changes must require CSRF")
    expect(rows("room_blocks", open_room) == [], "Rejected requests must not create a block")
    code, _, html = admin.request(endpoint)
    expect(code == 200 and 'Sperr' in html, "Administrators need an accessible maintenance management page")
    code, _, conflict_html = admin.request(endpoint, create(rooms["Occupied"]))
    expect(code == 409 and "Smoke Other Member" not in conflict_html, "An overlapping existing booking cannot be displaced or disclosed")
    with closing(sqlite3.connect(db_path)) as db:
        persisted = db.execute("SELECT room_id, seat_id, date, start_time, end_time FROM bookings WHERE id = ?",
                               (occupied,)).fetchone()
    expect(persisted == (None, occupied_seat, date, "10:15:00", "10:45:00")
           and rows("room_blocks", rooms["Occupied"]) == [], "Rejecting maintenance must preserve the legacy reservation")

    series_room = rooms["Series"]
    stale, _ = preview(series_room)
    expect(stale is not None, "Before a block is created both dates must be available")
    block_id = add(open_room, later)
    expect(admin.request(endpoint, create(open_room, later, "10:45", "11:15"))[0] == 409
           and len(rows("room_blocks", open_room)) == 1, "A second overlapping maintenance block must not overwrite or duplicate the first")
    add(rooms["Restricted"])
    add(series_room, second_week)

    scoped = {"room_id": open_room, "start_date": later}
    code, _, raw = user.request("get_bookings.php?" + urlencode({**scoped, "include_blocks": 1}))
    expect(code == 200 and json.loads(raw) == [{"room_id": open_room, "date": later, "start_time": "10:00",
                                             "end_time": "11:00", "blocked": True}],
           "Authorized map users receive the blocked interval, but no block ID, type, admin identity or other metadata")
    code, _, raw = user.request("get_bookings.php?" + urlencode(scoped))
    expect(code == 200 and json.loads(raw) == [], "Legacy feeds must not be changed unless blocks are requested")
    expect(guest.request("get_bookings.php?include_blocks=1")[0] == 401, "Blocks remain private to signed-in users")
    code, _, raw = user.request("get_bookings.php?" + urlencode({"start_date": date, "include_blocks": 1}))
    expect(code == 200 and not any(row.get("room_id") == rooms["Restricted"] for row in json.loads(raw)),
           "Bulk availability cannot expose blocked intervals of a hidden room")
    code, _, html = user.request(f"room_details.php?id={open_room}&date={later}")
    expect(code == 200 and "Gesperrt" in html and "Roomie Smoke Admin" not in html and endpoint not in html,
           "Room detail must show a neutral closed interval, not the administrator or private management link")
    picker = BookingDetailsHTML(html)
    expect(picker.available_until.get("09:45") == "10:00" and "10:00" not in picker.available_until
           and picker.available_until.get("11:00") == "17:00",
           "A custom interval may touch but must not cross a room closure")
    code, _, admin_html = admin.request(f"room_details.php?id={open_room}&date={later}")
    expect(code == 200 and f'{endpoint}?room_id={open_room}&amp;date={later}' in admin_html,
           "Administrators can reach the room's maintenance management from details")

    for result in (submit(open_room, first=later), submit(open_room, first=later, period="vormittags"),
                   submit(open_room, first=date, last=later, period="vormittags")):
        expect(result.get("success") is False and "gesperrt" in result.get("error", "").lower(),
               "Custom, preset and multi-day writes must reject a maintenance overlap")
    expect(rows("bookings", open_room) == [(move_id, date, "15:00", "16:00")],
           "A block on the second day must leave the attempted first-day preset unwritten")

    code, _, _ = user.request("reschedule_booking.php", {"booking_id": move_id, "date": later, "start_time": "10:15",
        "end_time": "10:45", "original_date": date, "original_start_time": "15:00", "original_end_time": "16:00",
        "csrf_token": user_status["csrf_token"]})
    expect(code == 409 and rows("bookings", open_room) == [(move_id, date, "15:00", "16:00")],
           "A blocked reschedule target must leave the existing booking untouched")
    code, _, _ = user.request("series_booking.php", stale)
    expect(code == 409 and rows("bookings", series_room) == [], "A stale series proof must not write earlier dates when the later one is blocked")
    new, blocked_html = preview(series_room)
    expect(new is None and 'id="rb-series-results"' in blocked_html and ("Gesperrt" in blocked_html or "Belegt" in blocked_html),
           "Fresh series preview must explain the block without a confirmation form")

    for start, end in (("09:45", "10:00"), ("11:00", "11:15")):
        expect(submit(open_room, start, end, first=later).get("success") is True, "Bookings touching either block edge must succeed")
    remove = {"action": "remove", "room_id": open_room, "block_id": block_id, "csrf_token": admin_status["csrf_token"]}
    expect(user.request(endpoint, dict(remove, csrf_token=user_status["csrf_token"]))[0] == 403,
           "Only administrators can remove a block")
    expect(admin.request(endpoint, dict(remove, csrf_token=""))[0] == 403, "Removing a block requires CSRF")
    expect(admin.request(endpoint, dict(remove, room_id=series_room))[0] == 404 and len(rows("room_blocks", open_room)) == 1,
           "A block may be removed only through its matching room")
    expect(admin.request(endpoint, remove)[0] == 303 and rows("room_blocks", open_room) == [],
           "Authorized removal should remove only the named maintenance interval")
    expect(submit(open_room, first=later).get("success") is True, "Removing the block restores bookability inside its old interval")

    if concurrent:
        race = rooms["Race"]
        barrier = Barrier(2, timeout=5)

        def start_together(action):
            barrier.wait()
            return action()

        with ThreadPoolExecutor(max_workers=2) as pool:
            block = pool.submit(start_together, lambda: admin.request(endpoint, create(race))[0])
            booking = pool.submit(start_together, lambda: submit(race).get("success"))
            result, booked = block.result(), booking.result()
        expect(result in (303, 409) and booked is (result == 409),
               "Concurrent block and booking at the same time must be mutually exclusive")
        expect(len(rows("bookings", race)) + len(rows("room_blocks", race)) == 1, "Only one concurrent write must persist")

    print("Room blocks: authorization/CSRF, privacy, occupied protection, single/preset/move/series, adjacency and removal passed"
          + ("; concurrent block vs booking passed" if concurrent else ""), flush=True)


def smoke_booking_approvals(env, scratch, date, guest, admin, admin_status, concurrent):
    db_path = env["ROOMIE_DB_PATH"]
    admin_token = admin_status["csrf_token"]
    action_path = "booking_request_action.php"
    queue_path = "admin_booking_requests.php"
    now = dt.datetime.now(ZoneInfo("Europe/Berlin")).date()
    next_date = (dt.date.fromisoformat(date) + dt.timedelta(days=1)).isoformat()
    mail_capture = scratch / "mail-capture.txt"

    def outbox_size():
        return mail_capture.stat().st_size if mail_capture.exists() else 0

    with closing(sqlite3.connect(db_path)) as db, db:
        ids = {}
        for label, name in (("owner", "Roomie Approval Owner"), ("peer", "Roomie Approval Peer")):
            ids[label] = db.execute("""
                INSERT INTO users (name, email, password, is_admin)
                SELECT ?, ?, password, 0 FROM users WHERE email = 'smoke-user@example.test'
            """, (name, f"smoke-approval-{label}@example.test")).lastrowid
        rooms = {}
        for label, capacity, kind, required in (
            ("Default", 1, "office", False), ("Queue", 1, "meeting", True),
            ("Restricted", 1, "fk-office", True), ("Block after request", 1, "office", True),
            ("Seat after request", 1, "office", True),
            ("Expired", 1, "office", True), ("Horizon", 1, "office", True),
            ("Race", 1, "office", True), ("Stale series", 1, "office", False),
            ("Move", 1, "office", True), ("Toggle", 1, "office", False),
        ):
            room = db.execute("INSERT INTO rooms (name, type, capacity) VALUES (?, ?, ?)",
                              (f"QA Approval {label}", kind, capacity)).lastrowid
            rooms[label] = room
            if required:
                db.execute("INSERT INTO room_booking_approval_policies (room_id) VALUES (?)", (room,))
        db.execute("INSERT INTO permissions (user_id, room_id) VALUES (?, ?)", (ids["owner"], rooms["Restricted"]))
        move_id = db.execute("INSERT INTO bookings (user_id, room_id, date, start_time, end_time) VALUES (?, ?, ?, '15:00', '16:00')",
                             (ids["owner"], rooms["Move"], date)).lastrowid

    owner, peer = Client(admin.base_url), Client(admin.base_url)
    owner_status = owner.login("smoke-approval-owner@example.test", env["ROOMIE_SMOKE_PASSWORD"])
    peer_status = peer.login("smoke-approval-peer@example.test", env["ROOMIE_SMOKE_PASSWORD"])
    owner_token, peer_token = owner_status["csrf_token"], peer_status["csrf_token"]

    def request_booking(room, *, actor=owner, token=owner_token, day=date, end_day=None,
                        start="10:15", end="10:45", period="individuell", intent=None, expected=200):
        fields = {"csrf_token": token, "selectedWorkspace": room, "start_date": day, "end_date": end_day or day,
                  "time_period": period, "start_time": start, "end_time": end}
        if intent is not None:
            fields["approval_request"] = intent
        code, _, data = actor.request("process_booking.php", fields)
        expect(code == expected, f"Booking path should answer HTTP {expected}, got {code}")
        return json.loads(data)

    def pending(room, *, actor=owner, token=owner_token, window=("10:15", "10:45")):
        result = request_booking(room, actor=actor, token=token, start=window[0], end=window[1])
        expect(result.get("success") is True and result.get("pending") is True and result.get("status") == "pending"
               and int(result.get("request_id") or 0) > 0, f"Protected room creates a request, not a booking: {result}")
        return int(result["request_id"])

    def snapshot(request_id):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT date, start_time, end_time, status, booking_id FROM booking_requests WHERE id = ?",
                              (request_id,)).fetchone()

    def schedule(room):
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT id, user_id, date, start_time, end_time FROM bookings WHERE room_id = ? ORDER BY id", (room,)).fetchall()

    def decide(request_id, operation="approve", *, actor=admin, token=admin_token):
        code, headers, _ = actor.request(action_path, {"csrf_token": token, "request_id": request_id, "action": operation})
        if code == 303 and actor is not guest:
            expected = "bookings.php?request=cancelled" if operation == "cancel" else f"{queue_path}?request={operation}d"
            expect(headers.get("Location") == expected, f"{operation} should redirect to {expected}, got {headers.get('Location')}")
        return code

    def policy(room, required, *, actor=admin, token=admin_token):
        code, headers, _ = actor.request(action_path, {"csrf_token": token, "action": "policy", "room_id": room,
            "date": date, "required": required})
        if code == 303:
            to = urlsplit(headers.get("Location", ""))
            expect(to.path == "room_details.php" and parse_qs(to.query).get("approval") == [str(required)],
                   "Policy changes must return to the room and confirm the new flag")
        return code

    expect(guest.request(queue_path)[0] in (302, 303, 401, 403), "Unauthenticated visitors cannot access the queue")
    expect(owner.request(queue_path)[0] == 403, "Member cannot access the administrator queue")
    default = request_booking(rooms["Default"], actor=peer, token=peer_token)
    expect(default.get("success") is True and default.get("pending") is not True and len(schedule(rooms["Default"])) == 1,
           "Approval is disabled by default; the existing direct booking flow must still work")
    if concurrent:
        expect(outbox_size() > 0 and b"To: smoke-approval-peer@example.test" in mail_capture.read_bytes(),
               "Transport stub must observe the regular confirmation so checking pending sends is meaningful")
    mail_before = outbox_size()

    queue = rooms["Queue"]
    code, _, raw = owner.request("load_grid.php")
    map_rooms = {entry["id"]: entry for entry in json.loads(raw)["data"]["rooms"]}
    expect(code == 200 and map_rooms[queue].get("requires_approval") is True
           and map_rooms[rooms["Default"]].get("requires_approval") is False,
           "Map must distinguish request-only rooms from default direct-booking rooms")
    code, _, html = owner.request(f"room_details.php?id={queue}&date={date}")
    expect(code == 200 and "Anfrage senden" in html, "Protected room booking must explain it is only an approval request")
    req = pending(queue)
    duplicate = request_booking(queue)
    expect((duplicate.get("success") is False or int(duplicate.get("request_id") or 0) == req),
           "Repeating the same pending request cannot create a distinct booking request")
    with closing(sqlite3.connect(db_path)) as db:
        expect(db.execute("SELECT COUNT(*) FROM booking_requests WHERE room_id = ? AND user_id = ?", (queue, ids["owner"])).fetchone()[0] == 1,
               "Duplicate submission must persist just one pending request")
    other = pending(queue, actor=peer, token=peer_token)
    expect(schedule(queue) == [] and snapshot(req)[3:] == ("pending", None) and snapshot(other)[3:] == ("pending", None),
           "Two pending requests for a one-place room must not reserve that place")
    code, _, raw = owner.request("get_bookings.php?" + urlencode({"room_id": queue, "start_date": date, "include_blocks": 1}))
    expect(code == 200 and json.loads(raw) == [], "Pending requests are not exposed as occupancy")
    expect(owner.request(f"bookings.php?download=calendar&booking_id={req}")[0] == 404,
           "A pending request cannot be exported as a calendar booking")
    code, _, html = owner.request("bookings.php")
    request_section = html.split('id="rb-booking-requests"', 1)[-1].split("</section>", 1)[0]
    expect(code == 200 and 'id="rb-booking-requests"' in html and "QA Approval Queue" in request_section
           and "Ausstehend" in request_section and "Anfrage zurückziehen" in request_section and "Roomie Approval Peer" not in html
           and "download=calendar" not in request_section and "Bestätigung erneut senden" not in request_section,
           "Owner sees only own pending requests, with withdraw but no calendar or email action")
    code, _, html = admin.request(queue_path)
    expect(code == 200 and all(value in html for value in ("Roomie Approval Owner", "Roomie Approval Peer", "Genehmigen", "Ablehnen")),
           "Admin review queue displays pending requests and explicit decisions")
    expect(outbox_size() == mail_before, "Pending creation, deduplication and queue/availability reads must not send email")
    expect(decide(req, actor=owner, token=owner_token) == 403, "Requestor cannot approve their own request")
    expect(decide(req, actor=guest, token="") in (302, 303, 401, 403), "Guest cannot approve a request")
    expect(decide(req, token="") == 403, "Approval must require CSRF")
    expect(decide(req, operation="cancel", actor=peer, token=peer_token) == 404, "Peers cannot withdraw others' requests")
    expect(decide(req, operation="cancel", actor=owner, token="") == 403, "Withdrawal must require CSRF")
    expect(decide(req, operation="cancel", actor=owner, token=owner_token) == 303 and snapshot(req)[3:] == ("cancelled", None),
           "Owner can withdraw a pending request without creating a booking")
    expect(decide(req) == 409 and schedule(queue) == [], "Withdrawn request cannot become a booking")
    expect(outbox_size() == mail_before, "Withdrawing an unconfirmed request must not send a booking or cancellation email")

    new_req = pending(queue)
    expect(decide(new_req) == 303 and snapshot(new_req)[3] == "approved", "Admin can approve a still-available request")
    booked = schedule(queue)
    expect(len(booked) == 1 and booked[0][0] == snapshot(new_req)[4] and booked[0][1:] == (ids["owner"], date, "10:15", "10:45"),
           "Approval creates and links exactly one confirmed booking for the original requester")
    expect(owner.request(f"bookings.php?download=calendar&booking_id={booked[0][0]}")[0] == 200,
           "The resulting booking, unlike its request, owns the calendar file")
    expect(decide(new_req) == 409 and decide(other) == 409 and snapshot(other)[3:] == ("pending", None) and schedule(queue) == booked,
           "Review replay and capacity now taken by another approved request must not duplicate bookings")
    expect(decide(other, operation="decline") == 303 and snapshot(other)[3:] == ("declined", None) and schedule(queue) == booked,
           "Decline affects only the request, not the confirmed booking")

    restricted = rooms["Restricted"]
    req = pending(restricted)
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("DELETE FROM permissions WHERE user_id = ? AND room_id = ?", (ids["owner"], restricted))
    expect(decide(req) == 403 and snapshot(req)[3:] == ("pending", None) and schedule(restricted) == [],
           "A stale request cannot be approved after the requester's room grant is revoked")
    expect(decide(req, operation="cancel", actor=owner, token=owner_token) == 303,
           "Owner can still withdraw after losing the room grant")

    blocked = rooms["Block after request"]
    req = pending(blocked)
    status, _, _ = admin.request("admin_room_blocks.php", {"csrf_token": admin_token, "action": "create", "room_id": blocked,
        "date": date, "start_time": "10:00", "end_time": "11:00", "kind": "maintenance"})
    expect(status == 303, "Adding a block after an otherwise valid pending request should succeed")
    expect(decide(req) == 409 and snapshot(req)[3:] == ("pending", None) and schedule(blocked) == [],
           "Approval must recheck maintenance added after request submission")

    seat_room = rooms["Seat after request"]
    req = pending(seat_room)
    with closing(sqlite3.connect(db_path)) as db, db:
        seat = db.execute("INSERT INTO seats (room_id, top, left_pos) VALUES (?, '0', '0')", (seat_room,)).lastrowid
        db.execute("INSERT INTO bookings (user_id, seat_id, date, start_time, end_time) VALUES (?, ?, ?, '10:15', '10:45')",
                   (ids["peer"], seat, date))
    expect(decide(req) == 409 and snapshot(req)[3:] == ("pending", None) and schedule(seat_room) == [],
           "Approving a pending request must recheck capacity taken by a seat-only booking")
    expect(request_booking(seat_room).get("success") is False, "New approval requests must count seat-only bookings")

    for room, invalid in ((rooms["Expired"], now - dt.timedelta(days=1)), (rooms["Horizon"], now + dt.timedelta(days=91))):
        req = pending(room)
        # Synthetic persisted request ages/out-of-policy dates; never alter the system clock.
        with closing(sqlite3.connect(db_path)) as db, db:
            db.execute("UPDATE booking_requests SET date=? WHERE id=?", (invalid.isoformat(), req))
        expect(decide(req) == 409 and snapshot(req)[3:] == ("pending", None) and schedule(room) == [],
               "Approval must revalidate a request's live timing and horizon")

    out = request_booking(rooms["Move"], end_day=next_date, period="vormittags")
    expect(out.get("success") is False, "Protected rooms must reject multi-day booking rather than reserve several days")
    code, _, _ = owner.request("reschedule_booking.php", {"csrf_token": owner_token, "booking_id": move_id,
        "original_date": date, "original_start_time": "15:00", "original_end_time": "16:00", "date": date,
        "start_time": "14:00", "end_time": "15:00"})
    expect(code == 409 and schedule(rooms["Move"]) == [(move_id, ids["owner"], date, "15:00", "16:00")],
           "An already-confirmed booking cannot bypass protection by rescheduling")

    def series_preview(room):
        status, _, html = owner.request("series_booking.php", {"stage": "preview", "room_id": room, "count": 2,
            "date": date, "start_time": "11:00", "end_time": "12:00", "csrf_token": owner_token})
        return status, SeriesHTML(html).forms.get("rb-series-book")

    expect(series_preview(queue) == (409, None), "Protected room cannot offer a series commit proof")
    stale_room = rooms["Stale series"]
    status, stale = series_preview(stale_room)
    expect(status == 200 and stale, "Fixture: unprotected room offers a valid series proof")
    with closing(sqlite3.connect(db_path)) as db, db:
        db.execute("INSERT INTO room_booking_approval_policies (room_id) VALUES (?)", (stale_room,))
        expect(db.execute("SELECT COUNT(*) FROM booking_requests WHERE room_id = ?", (rooms["Move"],)).fetchone()[0] == 0,
               "Rejected multi-day and move paths must not create a pending request either")
    expect(owner.request("series_booking.php", stale)[0] == 409 and not schedule(stale_room),
           "A preview issued before enabling approval cannot reserve any series date after activation")

    toggle = rooms["Toggle"]
    expect(policy(toggle, 1, actor=owner, token=owner_token) == 403 and policy(toggle, 1, token="") == 403,
           "Only administrators with CSRF can turn approval on or off")
    expect(policy(toggle, 1) == 303, "Admin can enable approval for a room")
    stale_direct = request_booking(toggle, intent="0")
    expect(stale_direct.get("pending") is True and schedule(toggle) == [],
           "An older direct-booking form cannot skip a newly activated room policy")
    req = int(stale_direct["request_id"])
    deletion, _, _ = admin.request("delete_room.php", data=json.dumps({"roomId": toggle}).encode(),
                                  content_type="application/json", csrf=admin_token)
    expect(deletion == 409 and snapshot(req)[3:] == ("pending", None), "Room deletion cannot discard an open request")
    expect(policy(toggle, 0) == 409 and snapshot(req)[3:] == ("pending", None) and schedule(toggle) == [],
           "Turning approval off cannot silently discard or approve unresolved requests")
    expect(decide(req, operation="cancel", actor=owner, token=owner_token) == 303 and policy(toggle, 0) == 303,
           "After the last request is resolved, admin can restore direct booking")
    stale_outbox = outbox_size()
    stale_request = request_booking(toggle, intent="1", expected=409)
    expect(stale_request.get("success") is False and schedule(toggle) == [] and stale_outbox == outbox_size(),
           "An older request-only form must not accidentally create a booking after the policy is turned off")
    direct = request_booking(toggle)
    expect(direct.get("success") is True and direct.get("pending") is not True,
           "Room policy disabled explicitly restores direct booking")

    if concurrent:
        race = rooms["Race"]
        first = pending(race)
        second = pending(race, actor=peer, token=peer_token)
        other_admin = Client(admin.base_url)
        other_status = other_admin.login("smoke-admin@example.test", env["ROOMIE_SMOKE_PASSWORD"])
        barrier = Barrier(2, timeout=5)

        def approve_together(request_id, actor, token):
            barrier.wait()
            return decide(request_id, actor=actor, token=token)

        with ThreadPoolExecutor(max_workers=2) as pool:
            left = pool.submit(approve_together, first, admin, admin_token)
            right = pool.submit(approve_together, second, other_admin, other_status["csrf_token"])
            results = left.result(), right.result()
        active = schedule(race)
        expect(sorted(results) == [303, 409] and len(active) == 1,
               "Two independent admin sessions must not approve competing last-place requests")
        winner, loser = (first, second) if results[0] == 303 else (second, first)
        expect(snapshot(winner)[3:] == ("approved", active[0][0]) and snapshot(loser)[3:] == ("pending", None),
               "Approval and booking insert must commit together and leave the losing request unchanged")

    print("Booking approvals: default off, role and CSRF, pending privacy/no ICS or email, dedup, owner withdrawal, "
          "approve/decline, stale ACL/block/capacity/time/horizon, move/series/multi-day protection passed"
          + ("; concurrent decisions passed" if concurrent else ""), flush=True)


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
    server_env = dict(env)
    server_args = []
    if concurrent:
        # Capture transactional email locally. Never invoke a system MTA or transmit a message.
        capture = scratch / "capture-mail.sh"
        capture.write_text('#!/bin/sh\ncat >> "$ROOMIE_SMOKE_MAIL_CAPTURE"\n', encoding="utf-8")
        capture.chmod(0o700)
        server_env.update(PHP_CLI_SERVER_WORKERS="3", ROOMIE_MAIL_FROM="smoke-sender@example.test",
                          ROOMIE_SMOKE_MAIL_CAPTURE=str(scratch / "mail-capture.txt"))
        server_args += ["-d", f"sendmail_path={shlex.quote(str(capture))}"]
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
    smoke_room_block_upgrade(command, root, env, scratch)
    sessions = scratch / "sessions"
    sessions.mkdir(mode=0o700)
    logfile = scratch / "server.log"
    with socket.socket() as reserve:
        reserve.bind(("127.0.0.1", 0))
        port = reserve.getsockname()[1]
    base_url = f"http://127.0.0.1:{port}/"
    with logfile.open("w", encoding="utf-8") as output:
        with subprocess.Popen(command + server_args + ["-d", f"session.save_path={sessions}", "-S",
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
                expect(json.loads(body).get("booking_id") == int(booking_id),
                       "Confirmed single booking must include the calendar link ID")
                status, _, dashboard = user.request("index.php")
                expect(status == 200 and 'raumplan.php?view=list&amp;free=1&amp;' in dashboard
                       and 'Freien Raum finden' in dashboard and 'start_time=' in dashboard,
                       "Dashboard must open the list filtered to a suggested free interval")
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
                smoke_room_change(command, root, env, date, guest, admin, admin_status, user, user_status, concurrent)
                smoke_room_watches(command, root, env, date, guest, admin, admin_status, user, user_status)
                smoke_room_blocks(env, date, guest, admin, admin_status, user, user_status, concurrent)
                smoke_booking_approvals(env, scratch, date, guest, admin, admin_status, concurrent)
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
