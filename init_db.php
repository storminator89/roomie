<?php
try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabelle für Benutzer (mit is_admin Feld)
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        is_admin INTEGER DEFAULT 0,
        profile_image VARCHAR(255),
        notifications BOOLEAN DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabelle für Räume (mit capacity und equipment Feld)
    $db->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        capacity INTEGER DEFAULT 0,
        is_favorite INTEGER DEFAULT 0,
        equipment TEXT
    )");

    // Tabelle für Sitzplätze
    $db->exec("CREATE TABLE IF NOT EXISTS seats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        top TEXT NOT NULL,
        left_pos TEXT NOT NULL,
        FOREIGN KEY (room_id) REFERENCES rooms(id)
    )");

    // Tabelle für Buchungen
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        room_id INTEGER,
        seat_id INTEGER,
        date TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (room_id) REFERENCES rooms(id),
        FOREIGN KEY (seat_id) REFERENCES seats(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    room_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id)
)");

    // Beispieldaten einfügen
    $db->exec("INSERT OR IGNORE INTO rooms (name, type, capacity, equipment) VALUES 
        ('2. OG R15 Academy', 'spez-abt-buero', 4, '[\"wifi\",\"docking-station\"]'),
        ('2. OG Warschau', 'meeting', 10, '[\"wifi\"]'),     
        ('2. OG Casablanca', 'meeting', 8, '[\"wifi\",\"whiteboard\",\"projector\"]'),
        ('2. OG R11', 'shared-desk', 4, '[\"wifi\",\"docking-station\"]'),
        ('2. OG R12', 'fk-office', 4, '[\"wifi\",\"docking-station\"]'),
        ('3. OG Mannheim', 'shared-desk', 12, '[\"wifi\",\"docking-station\"]')");

    $db->exec("INSERT OR IGNORE INTO seats (room_id, top, left_pos) VALUES 
        (5, '20%', '20%'),
        (5, '20%', '80%'),
        (5, '80%', '20%'),
        (5, '80%', '80%'),
        (6, '20%', '20%'),
        (6, '20%', '80%'),
        (6, '80%', '20%'),
        (6, '80%', '80%')");

    // Beispielbenutzer einfügen (Passwort: 'password')
    $hashedPassword = password_hash('password', PASSWORD_DEFAULT);
    $db->exec("INSERT OR IGNORE INTO users (name, email, password, is_admin) VALUES 
        ('Max Mustermann', 'max@example.com', '$hashedPassword', 0),
        ('Erika Musterfrau', 'erika@example.com', '$hashedPassword', 0),
        ('Admin User', 'admin@example.com', '$hashedPassword', 1)");

    // Beispielbuchungen einfügen
    $db->exec("INSERT OR IGNORE INTO bookings (user_id, room_id, seat_id, date, start_time, end_time) VALUES 
        (1, 5, 1, '2024-07-10', '09:00', '17:00'),
        (2, 6, 5, '2024-07-11', '10:00', '16:00')");

    echo "Datenbank wurde initialisiert und mit Beispieldaten gefüllt.";
} catch (PDOException $e) {
    echo "Fehler bei der Datenbankinitialisierung: " . $e->getMessage();
}
