#!/bin/sh
# Run after 'npm ci --ignore-scripts' in this directory. No network at build time.
set -eu
cd "$(dirname "$0")"
node node_modules/tailwindcss/lib/cli.js -c ./tailwind.config.cjs -i ./tailwind-input.css -o ../assets/roomie-tailwind.css --minify
