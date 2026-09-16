import pymupdf as fitz
d=fitz.open('bg.pdf'); p=d[3]
drs=p.get_drawings()
def f(v): return f"{v:.2f}".rstrip('0').rstrip('.')
def path_d(dr, ox, oy):
    out=[]; cur=None
    for it in dr['items']:
        k=it[0]
        if k=='l':
            a,b=it[1],it[2]
            if cur is None or abs(cur.x-a.x)>1e-3 or abs(cur.y-a.y)>1e-3: out.append(f"M{f(a.x-ox)} {f(a.y-oy)}")
            out.append(f"L{f(b.x-ox)} {f(b.y-oy)}"); cur=b
        elif k=='c':
            a,b,c,e=it[1:5]
            if cur is None or abs(cur.x-a.x)>1e-3 or abs(cur.y-a.y)>1e-3: out.append(f"M{f(a.x-ox)} {f(a.y-oy)}")
            out.append(f"C{f(b.x-ox)} {f(b.y-oy)} {f(c.x-ox)} {f(c.y-oy)} {f(e.x-ox)} {f(e.y-oy)}"); cur=e
        elif k=='re':
            r=it[1]; out.append(f"M{f(r.x0-ox)} {f(r.y0-oy)}H{f(r.x1-ox)}V{f(r.y1-oy)}H{f(r.x0-ox)}Z"); cur=None
        elif k=='qu':
            q=it[1]; out.append(f"M{f(q.ul.x-ox)} {f(q.ul.y-oy)}L{f(q.ur.x-ox)} {f(q.ur.y-oy)}L{f(q.lr.x-ox)} {f(q.lr.y-oy)}L{f(q.ll.x-ox)} {f(q.ll.y-oy)}Z"); cur=None
    if dr.get('closePath'): out.append('Z')
    return ''.join(out)
def el(i, ox, oy, fill, cls=''):
    dr=drs[i]; dd=path_d(dr,ox,oy)
    c=f' class="{cls}"' if cls else ''
    if dr['type']=='s':
        return f'<path{c} d="{dd}" fill="none" stroke="{fill}" stroke-width="{f(dr["width"])}" stroke-linecap="round" stroke-linejoin="round"/>'
    rule=' fill-rule="evenodd"' if dr.get('even_odd') else ''
    return f'<path{c} d="{dd}" fill="{fill}"{rule}/>'
import json
info={i:(drs[i]['type'],drs[i].get('width'),drs[i].get('lineCap'),drs[i].get('lineJoin'),drs[i].get('even_odd'),drs[i].get('closePath')) for i in (7,8,9,10,11,12)}
print(info)
BLUE='#5C8BFF'; NAVY='#111829'
# symbol bbox incl stroke
sw=drs[7]['width']
sx0=min(drs[i]['rect'].x0 for i in range(7,12))-sw/2; sy0=min(drs[i]['rect'].y0 for i in range(7,12))-sw/2
sx1=max(drs[i]['rect'].x1 for i in range(7,12))+sw/2; sy1=max(drs[i]['rect'].y1 for i in range(7,12))+sw/2
W=sx1-sx0; H=sy1-sy0
def symbol(color, micro=False, pad=0):
    ids=[7,8] if micro else [7,8,9,10,11]
    return ''.join(el(i, sx0-pad, sy0-pad, color) for i in ids)
open('symbol.svg','w').write(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(W)} {f(H)}">{symbol(BLUE)}</svg>')
open('symbol-current.svg','w').write(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(W)} {f(H)}">{symbol("currentColor")}</svg>')
# micro: bubble only; bbox of 7,8
mx0=drs[7]['rect'].x0-sw/2; my0=drs[7]['rect'].y0-sw/2; mx1=drs[7]['rect'].x1+sw/2; my1=drs[7]['rect'].y1+sw/2
mW=mx1-mx0; mH=my1-my0
micro=''.join(el(i,mx0,my0,BLUE) for i in (7,8))
open('symbol-micro.svg','w').write(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(mW)} {f(mH)}">{micro}</svg>')
# full lockups
lx0=sx0; ly0=min(sy0, drs[12]['rect'].y0)
lx1=max(drs[i]['rect'].x1 for i in range(12,21)); ly1_nt=max(sy1, drs[16]['rect'].y1)
ly1_t=max(ly1_nt, max(drs[i]['rect'].y1 for i in range(21,37)))
def lockup(resep, foto, tag, symc, tagline=True):
    parts=[symbol_part(symc)]
    parts+= [el(i,lx0,ly0,resep) for i in range(12,17)]
    parts+= [el(i,lx0,ly0,foto) for i in range(17,21)]
    if tagline: parts+= [el(i,lx0,ly0,tag) for i in range(21,37)]
    h=(ly1_t if tagline else ly1_nt)-ly0
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {f(lx1-lx0)} {f(h)}">{"".join(parts)}</svg>'
def symbol_part(c): return ''.join(el(i,lx0,ly0,c) for i in range(7,12))
open('logo-light-tagline.svg','w').write(lockup(NAVY,BLUE,NAVY,BLUE,True))
open('logo-light.svg','w').write(lockup(NAVY,BLUE,NAVY,BLUE,False))
open('logo-dark-tagline.svg','w').write(lockup('#EEF2FB',BLUE,'#EEF2FB',BLUE,True))
open('logo-dark.svg','w').write(lockup('#EEF2FB',BLUE,'#EEF2FB',BLUE,False))
json.dump({'W':W,'H':H,'lw':lx1-lx0,'lh_t':ly1_t-ly0,'lh':ly1_nt-ly0},open('dims.json','w'))
print(W,H,lx1-lx0,ly1_t-ly0)
