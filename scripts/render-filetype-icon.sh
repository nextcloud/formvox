#!/bin/sh
# Renders the form file icon to the PNG FormPreview hands to Nextcloud; run after changing the SVG.
set -e
cd "$(dirname "$0")/.."
rsvg-convert -w 512 -h 512 img/filetypes/application-x-fvform.svg -o img/filetypes/application-x-fvform.png
