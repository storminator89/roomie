#!/usr/bin/env python3
import re
from pathlib import Path
import sys
target = Path(sys.argv[1]).resolve()
for p in sorted([*target.glob('*.php'), *target.glob('*.html')]):
    s = p.read_text()
    s = s.replace('<script src="https://cdn.tailwindcss.com"></script>', '<link rel="stylesheet" href="assets/roomie-tailwind.css">')
    s = re.sub(r'<script\b[^\n]+src="(?:https://cdn\.jsdelivr\.net/npm/alpinejs@3\.x\.x/dist/cdn\.min\.js|//unpkg\.com/alpinejs)"[^\n]*></script>',
        '<script defer src="assets/alpine-3.14.9.min.js"></script>', s)
    s = re.sub(r'^.*href="https://fonts\.googleapis\.com/[^"]+"[^\n]*\n', '', s, flags=re.M)
    s = re.sub(r'https://cdnjs\.cloudflare\.com/ajax/libs/font-awesome/[^/]+/css/all\.min\.css', 'assets/fontawesome/css/all.min.css', s)
    s = re.sub(r'^.*https://cdnjs\.cloudflare\.com/ajax/libs/intro\.js/[^\n]+\n', '', s, flags=re.M)
    if s != p.read_text():
        p.write_text(s)
p = target / 'app.js'
s = p.read_text()
start = "    document.addEventListener('DOMContentLoaded', (event) => {\n        const tourCompleted"
if start in s:
    left = s.index(start)
    right = s.index("    return {", left)
    replacement = """    document.addEventListener('DOMContentLoaded', () => {
        try {
            if (localStorage.getItem('tourCompleted')) return;
        } catch {
            return;
        }
        const dialog = document.createElement('dialog');
        if (typeof dialog.showModal !== 'function') return;
        dialog.className = 'max-w-sm rounded-xl p-6 shadow-xl backdrop:bg-gray-900/60';
        dialog.setAttribute('aria-labelledby', 'welcomeTitle');
        const heading = document.createElement('h2');
        heading.id = 'welcomeTitle';
        heading.className = 'text-lg font-semibold';
        heading.textContent = 'Willkommen bei Roomie!';
        const hint = document.createElement('p');
        hint.className = 'my-4 text-sm';
        hint.textContent = 'Wähle einen Raum, ein Datum und eine Uhrzeit. Klicke anschließend auf Buchen.';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'rounded bg-yellow-400 px-4 py-2 font-semibold';
        close.textContent = 'Los geht’s';
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            localStorage.setItem('tourCompleted', 'true');
            dialog.remove();
        });
        dialog.append(heading, hint, close);
        document.body.appendChild(dialog);
        dialog.showModal();
    });
"""
    p.write_text(s[:left] + replacement + s[right:])
