const DAYS = /^\d{4}-\d{2}-\d{2}$/;
const EDIT_LIMIT = 20;
const isRevision = value => typeof value === 'string' && /^(0|[1-9]\d{0,18})$/.test(value);
const PERIODS = ['ganzerTag', 'vormittags', 'nachmittags'];

function positiveId(value) {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    const number = Number(value);
    return /^[1-9]\d*$/.test(String(value)) && Number.isSafeInteger(number) ? number : null;
}

function decodeList(raw) {
    if (Array.isArray(raw)) return raw;
    if (typeof raw !== 'string') return [];
    try {
        const data = JSON.parse(raw);
        return Array.isArray(data) ? data : [];
    } catch {
        return [];
    }
}

function parseDimension(value) {
    const n = positiveId(value);
    return n && n <= EDIT_LIMIT ? n : null;
}

export function normalizeRoomplan(response, availableFavorites = []) {
    const rooms = [];
    const seenRooms = new Set();
    for (const raw of Array.isArray(response?.rooms) ? response.rooms : []) {
        const id = positiveId(raw?.id);
        if (!id || seenRooms.has(id)) continue;
        seenRooms.add(id);
        const ordinal = raw.grid_position;
        const position = (typeof ordinal === 'number' || typeof ordinal === 'string') && /^(0|[1-9]\d*)$/.test(String(ordinal))
            && Number(ordinal) < EDIT_LIMIT * EDIT_LIMIT ? Number(ordinal) : null;
        rooms.push({
            id,
            name: String(raw.name ?? ''),
            number: String(raw.number ?? ''),
            type: String(raw.type ?? ''),
            capacity: Math.max(0, Math.min(500, Number.parseInt(raw.capacity, 10) || 0)),
            amenities: decodeList(raw.equipment).filter(v => typeof v === 'string').slice(0, 32),
            position
        });
    }
    const config = response?.gridConfig;
    const r = parseDimension(config?.rows);
    const c = parseDimension(config?.cols);
    const maxPosition = rooms.reduce((value, room) => Math.max(value, room.position ?? -1), -1);
    const cols = r && c ? c : maxPosition >= 120 ? EDIT_LIMIT : 6;
    const rows = r && c ? r : Math.max(4, Math.ceil((maxPosition + 1) / cols));
    const rawLayout = decodeList(config?.room_layout);
    const consistent = rawLayout.length === rows * cols;
    const used = new Set();
    const layout = Array(rows * cols).fill(null);
    if (consistent) {
        for (const [index, item] of rawLayout.entries()) {
            const id = positiveId(item);
            if (id && seenRooms.has(id) && !used.has(id)) {
                used.add(id);
                layout[index] = id;
            }
        }
    } else {
        for (const {id, position} of rooms) {
            if (position !== null && position < layout.length && layout[position] === null) layout[position] = id;
        }
    }
    const positions = new Map(layout.flatMap((id, index) => id === null ? [] : [[id, index]]));
    for (const room of rooms) room.position = positions.get(room.id) ?? null;
    const favorites = [...new Set(decodeList(availableFavorites).map(positiveId).filter(id => seenRooms.has(id)))];
    const revision = isRevision(response?.revision) ? response.revision : null;
    return { rooms, rows, cols, layout, favorites, revision };
}

export function createRoomplanApi({csrf, fetcher = globalThis.fetch} = {}) {
    async function request(path, {body, asJson = false, signal} = {}) {
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['X-CSRF-Token'] = csrf ?? '';
        if (asJson) headers['Content-Type'] = 'application/json';
        let response;
        try {
            response = await fetcher(path, {
                method: body === undefined ? 'GET' : 'POST',
                body: asJson ? JSON.stringify(body) : body,
                headers,
                signal,
                credentials: 'same-origin',
                cache: 'no-store'
            });
        } catch (error) {
            if (error?.name === 'AbortError') throw error;
            throw new Error('Verbindung unterbrochen. Bitte erneut versuchen.');
        }
        if (response.status === 401) throw new Error('Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.');
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.success === false || (typeof payload.error === 'string' && !Array.isArray(payload))) {
            throw new Error(payload?.error || payload?.message || 'Die Aktion war nicht möglich. Bitte erneut versuchen.');
        }
        return payload;
    }
    const jsonWrite = (path, body) => request(path, {body, asJson: true});
    return {
        async loadFloorplans({signal} = {}) {
            const payload = await request('floorplan_list.php', {signal});
            if (!Array.isArray(payload.plans)) throw new Error('Grundrisse konnten nicht geladen werden.');
            return payload.plans.filter(floor => positiveId(floor.id) && positiveId(floor.width)
                && positiveId(floor.height) && isRevision(floor.revision) && Array.isArray(floor.markers));
        },
        floorplanImageUrl(id) {
            if (!positiveId(id)) throw new Error('Grundriss nicht gefunden.');
            return 'floorplan_image.php?' + new URLSearchParams({id});
        },
        async uploadFloorplan({title, image}) {
            const body = new FormData();
            body.append('title', title);
            body.append('image', image);
            const payload = await request('floorplan_upload.php', {body});
            const id = positiveId(payload.id);
            if (!id) throw new Error('Upload konnte nicht bestätigt werden. Bitte neu laden.');
            return id;
        },
        async saveFloorMarkers(data) {
            if (!positiveId(data.id) || !isRevision(data.revision)) throw new Error('Bitte den Grundriss neu laden.');
            const payload = await jsonWrite('floorplan_markers.php', data);
            if (!isRevision(payload.revision)) throw new Error('Speichern konnte nicht bestätigt werden. Bitte neu laden.');
            return payload.revision;
        },
        async loadPlan({signal} = {}) {
            const [plan, favorites] = await Promise.all([
                request('load_grid.php', {signal}),
                request('get_user_favorites.php', {signal})
            ]);
            if (!plan.data || !Array.isArray(plan.data.rooms)) throw new Error('Raumplan konnte nicht geladen werden.');
            return normalizeRoomplan(plan.data, favorites.favorites);
        },
        async loadBookings(date, {signal} = {}) {
            if (!DAYS.test(date)) throw new Error('Bitte ein Datum wählen.');
            const search = new URLSearchParams({start_date: date, end_date: date});
            const payload = await request('get_bookings.php?' + search, {signal});
            if (!Array.isArray(payload)) throw new Error('Belegung konnte nicht geladen werden.');
            return payload;
        },
        async toggleFavorite(roomId) {
            const payload = await request('update_favorite.php', {body: new URLSearchParams({favorite_room_id: roomId})});
            return payload.is_favorite === true;
        },
        async book({roomId, date, period}) {
            if (!positiveId(roomId) || !DAYS.test(date) || !PERIODS.includes(period)) throw new Error('Bitte Raum, Datum und Zeitspanne prüfen.');
            const payload = await request('process_booking.php', {body: new URLSearchParams({
                selectedWorkspace: roomId, start_date: date, end_date: date, time_period: period
            })});
            return {message: payload.message};
        },
        async saveLayout(layout) {
            // Never downgrade a new editor session to an unlocked legacy write.
            if (!isRevision(layout.revision)) throw new Error('Layout-Version fehlt. Bitte Editor neu öffnen.');
            const payload = await jsonWrite('save_grid.php', layout);
            if (!isRevision(payload.revision)) throw new Error('Layout gespeichert, Versionsbestätigung fehlt. Bitte Editor neu öffnen.');
            return payload.revision;
        },
        async createRoom(room) {
            const result = await jsonWrite('add_room.php', room);
            const id = positiveId(result.roomId);
            if (!id) throw new Error('Raum konnte nicht bestätigt werden. Bitte neu laden.');
            return id;
        },
        async updateRoom(room) {
            await jsonWrite('update_room.php', room);
        },
        async deleteRoom(roomId) {
            await jsonWrite('delete_room.php', {roomId});
        }
    };
}
