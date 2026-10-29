"""A psg.bin cut from FROM to TO with 2 calibration frames before it (the 3 tone channels at volume 15).

    python3 -I psgcal.py psg.bin FROM TO out.bin

dsk2jumphp's renderer scales every render to its own peak: rendered after this full-volume reference (then
cut off), every sound keeps the level it has on the CPC, a footstep at volume 7 stays a footstep.
"""
import sys

src, a, b, out = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4]
data = open(src, 'rb').read()
ref = bytes([100, 0, 100, 0, 100, 0, 0, 0b111000, 15, 15, 15, 0, 0, 0, 0, 0])
open(out, 'wb').write(ref * 2 + data[a * 16:b * 16])
