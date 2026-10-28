#!/usr/bin/env python3
"""Draws assets/icon.png (the UnDash tile logo) with the standard library only. Run once; the PNG is committed."""
import zlib, struct, pathlib

N, SS = 128, 4                                  # size and supersampling for smooth corners
BG, FG = (241, 90, 44), (255, 255, 255)
def rr(x, y, x0, y0, x1, y1, r):                # inside a rounded rectangle?
    if not (x0 <= x <= x1 and y0 <= y <= y1): return False
    cx = min(max(x, x0 + r), x1 - r); cy = min(max(y, y0 + r), y1 - r)
    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r
s = N / 64
tiles = [(12, 12, 30, 36, 1.0), (34, 12, 52, 24, .85), (34, 28, 52, 52, 1.0), (12, 40, 30, 52, .85)]
rows = []
for py in range(N):
    row = bytearray([0])
    for px in range(N):
        acc = [0, 0, 0, 0]
        for sy in range(SS):
            for sx in range(SS):
                x = (px + (sx + .5) / SS) / s; y = (py + (sy + .5) / SS) / s
                if not rr(x, y, 0, 0, 64, 64, 14): continue
                c, a = BG, 1.0
                for t in tiles:
                    if rr(x, y, t[0], t[1], t[2], t[3], 4):
                        c = tuple(round(BG[i] + (FG[i] - BG[i]) * t[4]) for i in range(3))
                acc[0] += c[0]; acc[1] += c[1]; acc[2] += c[2]; acc[3] += 255
        n = SS * SS
        a = acc[3] / n
        row += bytes([round(acc[0] / max(1, acc[3] / 255)) if a else 0, round(acc[1] / max(1, acc[3] / 255)) if a else 0, round(acc[2] / max(1, acc[3] / 255)) if a else 0, round(a)])
    rows.append(bytes(row))
def chunk(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', N, N, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b''.join(rows), 9)) + chunk(b'IEND', b'')
out = pathlib.Path(__file__).resolve().parent.parent / 'assets' / 'icon.png'
out.write_bytes(png); print('wrote', out, len(png), 'bytes')
