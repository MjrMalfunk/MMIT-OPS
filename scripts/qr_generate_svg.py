#!/usr/bin/env python3
import sys
from pathlib import Path

try:
    import qrcode
    import qrcode.image.svg
except ImportError:
    print("Missing Python package: qrcode. Install with: python3 -m pip install --user qrcode==7.3.1 --no-deps", file=sys.stderr)
    sys.exit(2)

if len(sys.argv) != 3:
    print("Usage: qr_generate_svg.py <url> <output_svg_path>", file=sys.stderr)
    sys.exit(2)

url = sys.argv[1].strip()
out_path = Path(sys.argv[2])

if not url:
    print("URL is required.", file=sys.stderr)
    sys.exit(2)

out_path.parent.mkdir(parents=True, exist_ok=True)

qr = qrcode.QRCode(
    version=None,
    error_correction=qrcode.constants.ERROR_CORRECT_Q,
    box_size=12,
    border=4,
)

qr.add_data(url)
qr.make(fit=True)

svg_img = qr.make_image(image_factory=qrcode.image.svg.SvgPathImage)

with out_path.open("wb") as f:
    svg_img.save(f)

print(str(out_path))
