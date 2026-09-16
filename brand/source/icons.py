import re, cairosvg, io
from PIL import Image
NAVY='#111829'
def inner(svgfile, stroke=None):
    s=open(svgfile).read()
    vb=[float(x) for x in re.search(r'viewBox="([^"]+)"',s).group(1).split()]
    body=re.search(r'<svg[^>]*>(.*)</svg>',s,re.S).group(1)
    if stroke: body=body.replace('stroke-width="12.37"',f'stroke-width="{stroke}"')
    return vb[2],vb[3],body
def square(svgfile, size, pad, bg=NAVY, rx=0, stroke=None):
    w,h,body=inner(svgfile,stroke)
    # add stroke slack
    avail=size*(1-2*pad); sc=avail/max(w,h)
    tx=(size-w*sc)/2; ty=(size-h*sc)/2
    rect=f'<rect width="{size}" height="{size}" rx="{rx}" fill="{bg}"/>' if bg else ''
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}">{rect}<g transform="translate({tx:.2f} {ty:.2f}) scale({sc:.4f})">{body}</g></svg>'
def png(svg, px, path):
    cairosvg.svg2png(bytestring=svg.encode(), write_to=path, output_width=px, output_height=px)
fav=square('symbol-micro.svg',128,.16,rx=30,stroke=15)
open('out/favicon.svg','w').write(fav)
imgs=[]
for px in (16,32,48):
    b=cairosvg.svg2png(bytestring=fav.encode(),output_width=px,output_height=px)
    imgs.append(Image.open(io.BytesIO(b)).convert('RGBA'))
imgs[2].save('out/favicon.ico',sizes=[(16,16),(32,32),(48,48)],append_images=imgs[:2])
png(fav,32,'out/favicon-32.png')
app=square('symbol.svg',1024,.20)
png(app,180,'out/apple-touch-icon.png')
for px in (192,512,1024): png(app,px,f'out/icon-{px}.png')
print('ok')
