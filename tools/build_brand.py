"""Raster favicon companions for the project's code-native SVG emblems."""
from pathlib import Path
from PIL import Image, ImageDraw
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
DEST=ROOT/'public/assets/brand'
for name in ('dialogue','dao-admin'):
    svg=ET.parse(DEST/f'{name}.svg').getroot()
    fills=[node.attrib['fill'] for node in svg if 'fill' in node.attrib]
    background,light,dark=fills[:3]
    scale=32
    image=Image.new('RGBA',(64*scale,64*scale))
    draw=ImageDraw.Draw(image)
    def box(values):return tuple(round(v*scale) for v in values)
    draw.rounded_rectangle(box((0,0,64,64)),radius=17*scale,fill=background)
    draw.ellipse(box((9,9,55,55)),fill=light)
    # Right hemisphere, then the two interlocking lobes.
    dark_circle=Image.new('RGBA',image.size);d=ImageDraw.Draw(dark_circle)
    d.ellipse(box((9,9,55,55)),fill=dark)
    mask=Image.new('L',image.size);ImageDraw.Draw(mask).rectangle(box((32,0,64,64)),fill=255)
    image.alpha_composite(Image.composite(dark_circle,Image.new('RGBA',image.size),mask))
    draw=ImageDraw.Draw(image)
    draw.ellipse(box((20.5,32,43.5,55)),fill=dark)
    draw.ellipse(box((20.5,9,43.5,32)),fill=light)
    draw.ellipse(box((28,16.5,36,24.5)),fill=dark)
    draw.ellipse(box((28,39.5,36,47.5)),fill=light)
    for size in (32,180,192,512):
        image.resize((size,size),Image.Resampling.LANCZOS).save(DEST/f'{name}-{size}.png')
    image.resize((256,256),Image.Resampling.LANCZOS).save(DEST/f'{name}.ico',format='ICO',sizes=[(16,16),(32,32),(48,48),(64,64)])
(ROOT/'public/favicon.ico').write_bytes((DEST/'dialogue.ico').read_bytes())
print('Built site and Dao favicons, touch icons, and app icons.')
