#!/bin/sh
# Run after 'npm ci --ignore-scripts' in this directory. No network at build time.
set -eu
cd "$(dirname "$0")"
node node_modules/tailwindcss/lib/cli.js -c ./tailwind.config.cjs -i ./tailwind-input.css -o ../assets/roomie-tailwind.css --minify
cp node_modules/alpinejs/dist/cdn.min.js ../assets/alpine-3.14.9.min.js
cp node_modules/@fortawesome/fontawesome-free/css/all.min.css ../assets/fontawesome/css/all.min.css
cp node_modules/@fortawesome/fontawesome-free/webfonts/*.woff2 ../assets/fontawesome/webfonts/
sed -E -i 's/,url\(\.\.\/webfonts\/[^)]*\.ttf\) format\("truetype"\)//g' ../assets/fontawesome/css/all.min.css
