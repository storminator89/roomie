#!/usr/bin/env python3
"""Exercise Roomie room photos against a temporary SQLite DB and local PHP server."""

from base64 import b64decode
from contextlib import closing
import json
import os
from pathlib import Path
import runpy
import shutil
import socket
import sqlite3
import struct
import subprocess
import tempfile
import time
from urllib.error import URLError


ROOT = Path(__file__).resolve().parents[1]
SHARED = runpy.run_path(str(Path(__file__).with_name("php-smoke.py")))
Client, expect, run = (SHARED[name] for name in ("Client", "expect", "run"))
PASSWORD = "Synthetic Roomie Photo password 468!"
MAX_BYTES = 5 * 1024 * 1024


def smoke(php, scratch):
    db_path = scratch / "roomie.sqlite"
    sessions = scratch / "sessions"
    sessions.mkdir(mode=0o700)
    env = dict(os.environ, ROOMIE_DB_PATH=str(db_path), ROOMIE_DOCUMENT_ROOT=str(ROOT),
               ROOMIE_ADMIN_NAME="Room Photo Admin", ROOMIE_ADMIN_EMAIL="photos-admin@example.test")
    env.pop("PHP_CLI_SERVER_WORKERS", None)
    env.pop("ROOMIE_MAIL_FROM", None)
    command = [php] + SHARED["PHP_TEST_ARGS"]

    def cli(code):
        return run(command + ["-r", code], ROOT, env)

    def fixture(encode, width, height, color):
        return b64decode(cli(f"$im=imagecreatetruecolor({width},{height}); imagefill($im,0,0,{color});"
                             f"ob_start(); {encode}($im); echo base64_encode(ob_get_clean());"))

    def table_exists():
        with closing(sqlite3.connect(db_path)) as db:
            return db.execute("SELECT 1 FROM sqlite_master WHERE name='room_photos'").fetchone() is not None

    codecs = json.loads(cli("echo json_encode(function_exists('gd_info') ? gd_info() : []);"))
    can_exif = cli("echo (int) function_exists('exif_read_data');") == "1"
    can_webp = codecs.get("WebP Support", False)
    expect(codecs.get("JPEG Support") and codecs.get("PNG Support"), "Tests need GD with JPEG and PNG support")
    password_hash = cli(f"echo password_hash('{PASSWORD}', PASSWORD_DEFAULT);")
    run(command + [str(ROOT / "init_db.php")], ROOT, dict(env, ROOMIE_ADMIN_PASSWORD_HASH=password_hash))
    with closing(sqlite3.connect(db_path)) as db, db:
        user_id = db.execute("INSERT INTO users (name,email,password) VALUES (?,?,?)",
                             ("Room Photo User", "photos-user@example.test", password_hash)).lastrowid
        room = db.execute("INSERT INTO rooms (name,type) VALUES ('Photo Room','office')").lastrowid
        restricted = db.execute("INSERT INTO rooms (name,type) VALUES ('Restricted Photo Room','fk-office')").lastrowid

    png = fixture("imagepng", 2000, 1000, 0x377847)
    jpeg = fixture("imagejpeg", 640, 480, 0x644893)
    comment = b"Synthetic room photo metadata should not be stored"
    jpeg = jpeg[:2] + b"\xff\xfe" + struct.pack(">H", len(comment) + 2) + comment + jpeg[2:]
    # An ordinary 3×2 WebP fixture remains usable to test the missing-codec response.
    webp = b64decode("UklGRigAAABXRUJQVlA4IBwAAABQAQCdASoDAAIAAUAmJaQABAAAAP7857OHkAAA")
    exif = b"Exif\x00\x00" + struct.pack(">2sHIHHHIHHI", b"MM", 42, 8, 1, 0x112, 3, 1, 6, 0, 0)
    jpeg_rotated = jpeg[:2] + b"\xff\xe1" + struct.pack(">H", len(exif) + 2) + exif + jpeg[2:]
    wide = fixture("imagepng", 4097, 1, 0x388536)

    with socket.socket() as reserve:
        reserve.bind(("127.0.0.1", 0))
        address = f"127.0.0.1:{reserve.getsockname()[1]}"
    guest, admin, user = (Client(f"http://{address}/") for _ in range(3))
    endpoint = lambda room_id: f"room_photo.php?id={room_id}"

    def write(client, room_id, action, data=None, csrf=None):
        boundary = "RoomiePhotoHttpTestBoundary"
        body = (f'--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\n{action}\r\n').encode()
        if data is not None:
            body += (f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="fixture.data"\r\n'
                     'Content-Type: application/octet-stream\r\n\r\n').encode() + data + b"\r\n"
        body += f"--{boundary}--\r\n".encode()
        code, _, response = client.request(endpoint(room_id), data=body, csrf=csrf,
                                           content_type=f"multipart/form-data; boundary={boundary}")
        return code, json.loads(response)

    def read(client, room_id, code):
        received, headers, data = client.request(endpoint(room_id), binary=True)
        expect(received == code, f"Expected photo HTTP {code}, got {received}")
        expect(headers.get("Cache-Control") == "private, no-store"
               and headers.get("X-Content-Type-Options") == "nosniff"
               and headers.get("Cross-Origin-Resource-Policy") == "same-origin", "Expected image privacy headers")
        if code == 200:
            expect(headers.get("Content-Type") == "image/jpeg" and headers.get("Content-Disposition") is None
                   and data.startswith(b"\xff\xd8\xff") and data.endswith(b"\xff\xd9"), "Expected private normalized JPEG")
        return data

    def dimensions(data):
        output = subprocess.check_output(command + ["-r", "echo json_encode(array_slice("
                                                         "getimagesizefromstring(stream_get_contents(STDIN)),0,2));"],
                                         cwd=ROOT, env=env, input=data, timeout=10)
        return json.loads(output)

    logfile = scratch / "server.log"
    with logfile.open("w+") as log, subprocess.Popen(
        command + ["-d", f"session.save_path={sessions}", "-d", "upload_max_filesize=6M",
                   "-d", "post_max_size=7M", "-S", address, "-t", str(ROOT)],
        cwd=ROOT, env=env, stdout=log, stderr=subprocess.STDOUT,
    ) as server:
        try:
            for _ in range(50):
                try:
                    guest.status()
                    break
                except URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError("PHP HTTP server not ready")
            token = admin.login(env["ROOMIE_ADMIN_EMAIL"], PASSWORD)["csrf_token"]
            user_token = user.login("photos-user@example.test", PASSWORD)["csrf_token"]
            read(guest, room, 401)
            read(user, room, 404)
            expect(write(admin, room, "remove", csrf=token) == (200, {"success": True, "hasPhoto": False}),
                   "Removal of absent photo should be harmless")
            expect(write(user, room, "upload", png, user_token)[0] == 403
                   and write(admin, room, "upload", png)[0] == 403, "Writes must require admin and CSRF")
            expect(write(admin, room, "upload", b'<svg xmlns="http://www.w3.org/2000/svg"/>', token)[0] == 422,
                   "SVG must not be accepted")
            expect(write(admin, room, "upload", png[:72], token)[0] == 422
                   and write(admin, room, "upload", wide, token)[0] == 422, "Damaged or oversized dimensions must be rejected")
            expect(write(admin, room, "upload", b"x" * (MAX_BYTES + 1), token)[0] == 413, "Byte limit must be enforced")
            expect(not table_exists(), "Reads, no-op removal and invalid writes must not initialize storage")
            expect(write(admin, room, "upload", png, token) == (200, {"success": True, "hasPhoto": True}),
                   "Expected valid PNG upload")
            original = read(user, room, 200)
            expect(dimensions(original) == [1600, 800], "Expected bounded size, preserving aspect ratio")
            expect(write(admin, room, "upload", jpeg_rotated, token)[0] == 200, "Expected valid JPEG replacement")
            replaced = read(user, room, 200)
            expect(replaced != original and comment not in replaced and b"Exif\x00\x00" not in replaced
                   and dimensions(replaced) == ([480, 640] if can_exif else [640, 480]),
                   "Expected changed image without original metadata, oriented if supported")
            expect(write(admin, restricted, "upload", webp, token)[0] == (200 if can_webp else 422),
                   "Expected WebP upload when supported, otherwise explicit rejection")
            if not can_webp:
                expect(write(admin, restricted, "upload", png, token)[0] == 200, "Expected restricted room photo upload")
            read(admin, restricted, 200)
            read(user, restricted, 404)
            read(guest, restricted, 401)
            with closing(sqlite3.connect(db_path)) as db, db:
                db.execute("INSERT INTO permissions (user_id,room_id) VALUES (?,?)", (user_id, restricted))
                expect(db.execute("SELECT typeof(image_jpeg) FROM room_photos").fetchall() == [("blob",), ("blob",)],
                       "Expected one BLOB per room in the private SQLite DB")
            read(user, restricted, 200)
            with closing(sqlite3.connect(db_path)) as db, db:
                db.execute("DELETE FROM permissions WHERE room_id=?", (restricted,))
            read(user, restricted, 404)
            expect(write(admin, room, "remove", csrf=token) == (200, {"success": True, "hasPhoto": False}),
                   "Expected removal of existing photo")
            read(user, room, 404)
            code, _, result = admin.request("delete_room.php", data=json.dumps({"roomId": restricted}).encode(),
                                            content_type="application/json", csrf=token)
            expect(code == 200 and json.loads(result)["success"], "Expected ordinary admin room deletion")
            with closing(sqlite3.connect(db_path)) as db, db:
                expect(db.execute("SELECT room_id FROM room_photos").fetchall() == [], "Room deletion must cascade")
                db.execute("UPDATE users SET is_admin=0 WHERE email=?", (env["ROOMIE_ADMIN_EMAIL"],))
            expect(write(admin, room, "upload", png, token)[0] == 403, "Revoked admin role must prevent uploads")
        finally:
            server.terminate()
            server.wait(timeout=5)
        log.seek(0)
        diagnostics = log.read()
        expect(not SHARED["DIAGNOSTIC"].search(diagnostics), f"Unexpected PHP diagnostics:\n{diagnostics}")
    print("Room photos: formats, size, metadata, replacement, headers, ACL changes, removal and room deletion passed"
          + f" (WebP {'enabled' if can_webp else 'not compiled'}, EXIF {'enabled' if can_exif else 'not compiled'})")


if __name__ == "__main__":
    php = shutil.which(os.environ.get("PHP_BIN", "php"))
    if php is None:
        raise SystemExit("Set PHP_BIN to a PHP CLI with PDO SQLite and GD")
    with tempfile.TemporaryDirectory(prefix="roomie-photo-smoke-") as directory:
        smoke(str(Path(php).resolve()), Path(directory))
