"""A psg.bin cut from FROM to TO with 2 calibration frames before it (the 3 tone channels at volume 15).

    python3 -I psgcal.py psg.bin FROM TO out.bin [CHANNELS]

CHANNELS (e.g. "BC"): only those are kept, the others' volumes are set to 0 (another sound of the room).

dsk2jumphp's renderer scales every render to its own peak: rendered after this full-volume reference (then
cut off), every sound keeps the level it has on the CPC, a footstep at volume 7 stays a footstep.
"""
import sys

src, a, b, out = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4]
data = open(src, 'rb').read()
ref = bytes([100, 0, 100, 0, 100, 0, 0, 0b111000, 15, 15, 15, 0, 0, 0, 0, 0])
cut = bytearray(data[a * 16:b * 16])
if len(sys.argv) > 5:
    for f in range(0, len(cut), 16):
        for k, ch in enumerate('ABC'):
            if ch not in sys.argv[5]:
                cut[f + 8 + k] = 0
open(out, 'wb').write(ref * 2 + bytes(cut))
