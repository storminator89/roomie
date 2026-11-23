'use strict';

(() => {
    const link = document.getElementById('rq-scan-link');
    const symbol = document.getElementById('rq-symbol');
    const print = document.getElementById('rq-print-button');
    const status = document.getElementById('rq-status');
    try {
        // The server rendered the exact payload; keep it byte-for-byte and
        // never substitute the page's origin or send it to a QR service.
        const qr = qrcodegen.QrCode.encodeText(link.getAttribute('href'), qrcodegen.QrCode.Ecc.MEDIUM);
        const quiet = 4;
        const size = qr.size + quiet * 2;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'QR-Code für diesen Raum');
        svg.setAttribute('shape-rendering', 'crispEdges');
        const background = document.createElementNS(ns, 'rect');
        background.setAttribute('width', String(size));
        background.setAttribute('height', String(size));
        background.setAttribute('fill', '#fff');
        const squares = document.createElementNS(ns, 'path');
        let path = '';
        for (let y = 0; y < qr.size; y++) {
            for (let x = 0; x < qr.size; x++) {
                if (qr.getModule(x, y)) path += `M${x + quiet},${y + quiet}h1v1h-1z`;
            }
        }
        squares.setAttribute('d', path);
        squares.setAttribute('fill', '#000');
        svg.append(background, squares);
        symbol.append(svg);
        print.addEventListener('click', () => window.print());
        print.hidden = false;
    } catch {
        status.textContent = 'QR-Code nicht verfügbar. Der Link bleibt nutzbar.';
    }
})();
