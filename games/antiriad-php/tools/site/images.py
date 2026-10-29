"""Site pictures from the game's screenshots: pixel art scaled by whole factors, nearest neighbour.

    python3 -I images.py <screenshots dir> <game dir>
"""
import sys
from PIL import Image

src, game = sys.argv[1], sys.argv[2]
site = f'{game}/site/images'


def load(name):
    return Image.open(f'{src}/{name}.png').convert('RGB')


def scaled(img, k):
    return img.resize((img.width * k, img.height * k), Image.NEAREST)


# the screenshots, x2 (640 x 400), as PNG
for name in ('title', 'jungle', 'suit', 'caves', 'ruins', 'base'):
    scaled(load(name), 2).save(f'{site}/{name}.png', optimize=True)

# sprites cut from the game: Tal and the suit, x4, for the page's header
sheet = Image.open(f'{game}/assets/sprites/sprites-11.png').convert('RGBA')
import json
sprites = json.load(open(f'{game}/assets/data/world.json'))['sprites']
for name, n in (('sprite-tal', 9), ('sprite-suit', 101), ('sprite-armoured', 22)):
    s = sprites[n]
    scaled(sheet.crop((s['x'], s['y'], s['x'] + s['w'], s['y'] + s['h'])), 4).save(f'{site}/{name}.png', optimize=True)

# share card 1200 x 630 and favicon
og = Image.new('RGB', (1200, 630), (0, 0, 0))
title = scaled(load('title'), 2)
game_shot = scaled(load('suit'), 2)
og.paste(title.crop((0, 0, 640, 400)).resize((560, 350), Image.NEAREST), (20, 140))
og.paste(game_shot.resize((560, 350), Image.NEAREST), (620, 140))
og.paste(scaled(load('title'), 2).crop((0, 0, 640, 110)).resize((1200, 120), Image.NEAREST), (0, 10))
og.save(f'{site}/og.png', optimize=True)
fav = sheet.crop((sprites[101]['x'], sprites[101]['y'], sprites[101]['x'] + 24, sprites[101]['y'] + 41))
icon = Image.new('RGBA', (48, 48), (0, 0, 0, 255))
icon.paste(fav.resize((24, 41), Image.NEAREST), (12, 3), fav)
icon.save(f'{site}/favicon.png')

# the directory's picture (16:9): a screen of the game, Tal under the empty armour
thumb = Image.new('RGB', (640, 360), (0, 0, 0))
thumb.paste(load('armour').crop((0, 16, 640, 376)), (0, 0))   # the shot is already x2: a 16:9 window of it
thumb.save(f'{game}/assets/thumbnail.png', optimize=True)
icon.save(f'{game}/assets/icon.png')
