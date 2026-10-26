"""Generate assets/css/dark-auto.css: dark-mode overrides derived from every light colour used in the portal's CSS."""
import re, glob, colorsys, sys, os
import tinycss2
ROOT=sys.argv[1]
P='html[data-theme="dark"] '
HEX=re.compile(r'#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b')
RGB=re.compile(r'rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)')
NAMED={'white':'#ffffff','black':'#000000'}
def parse_hex(h):
    h=h.lstrip('#')
    if len(h)==3: h=''.join(c*2 for c in h)
    return tuple(int(h[i:i+2],16)/255 for i in (0,2,4))
def lum(rgb):
    f=lambda v: v/12.92 if v<=.03928 else ((v+.055)/1.055)**2.4
    r,g,b=map(f,rgb); return .2126*r+.7152*g+.0722*b
def to_hex(rgb): return '#%02x%02x%02x'%tuple(round(max(0,min(1,v))*255) for v in rgb)
def hls(rgb): return colorsys.rgb_to_hls(*rgb)
def dark_bg(rgb):
    h,l,s=hls(rgb)
    if s<0.12:  # neutral → greys (FB palette)
        if l>0.985: return (0x24/255,0x25/255,0x26/255)   # white → surface
        if l>0.955: return (0x2A/255,0x2B/255,0x2D/255)
        if l>0.90:  return (0x2F/255,0x30/255,0x32/255)
        return (0x3A/255,0x3B/255,0x3C/255)
    # tinted → same hue, deep & muted
    L=0.14+0.10*(1-l)*4 if l>0.8 else 0.18
    L=min(0.24,max(0.13,0.30-(l-0.80)*1.0)) if l>0.8 else 0.18
    return colorsys.hls_to_rgb(h,L,min(1,s*0.55))
def light_text(rgb):
    h,l,s=hls(rgb)
    if s<0.15: return (0xE4/255,0xE6/255,0xEB/255) if l<0.30 else (0xB0/255,0xB3/255,0xB8/255)
    return colorsys.hls_to_rgb(h,0.76,min(1,max(s,0.5)*0.9))
def dark_border(rgb):
    h,l,s=hls(rgb)
    if s<0.12: return (0x3E/255,0x40/255,0x42/255)
    return colorsys.hls_to_rgb(h,0.27,min(1,s*0.5))

def colors_in(v):
    out=[]
    for m in HEX.finditer(v): out.append((m.span(),parse_hex(m.group(0)),1.0))
    for m in RGB.finditer(v):
        a=float(m.group(4)) if m.group(4) else 1.0
        out.append((m.span(),tuple(int(m.group(i))/255 for i in (1,2,3)),a))
    return out
def remap(v,fn,cond):
    cs=colors_in(v); changed=False; res=v
    for (a,b),rgb,al in sorted(cs,key=lambda x:-x[0][0]):
        if al<0.95 or not cond(rgb): continue
        res=res[:a]+to_hex(fn(rgb))+res[b:]; changed=True
    return res if changed else None

def decl_override(name,val):
    n=name.lower()
    if n=='color' and re.fullmatch(r'\s*var\(--(maroon-dark|teal-dark)\s*(,[^)]*)?\)\s*(!important)?',val): return '#F0A3B2' if 'maroon' in val else '#67D4E8'
    if 'var(' in val or '<?' in val: return None
    if n.startswith('--') and re.search(r'pale|light|bg',n): return remap(val,dark_bg,lambda c: lum(c)>0.55)
    if n in('background','background-color'):
        if 'url(' in val and 'gradient' not in val: return None
        return remap(val,dark_bg,lambda c: lum(c)>0.55)
    if n=='color':
        return remap(val,light_text,lambda c: lum(c)<0.17)
    if n in('border','border-color','border-top','border-bottom','border-left','border-right','border-top-color','border-bottom-color','border-left-color','border-right-color','outline','box-shadow') :
        if n=='box-shadow': return None
        return remap(val,dark_border,lambda c: lum(c)>0.55)
    return None

def split_selectors(prelude):
    out=[];depth=0;cur=''
    for ch in prelude:
        if ch in '([': depth+=1
        if ch in ')]': depth-=1
        if ch==',' and depth==0: out.append(cur.strip()); cur=''
        else: cur+=ch
    if cur.strip(): out.append(cur.strip())
    return out

def process_rules(rules,emit_wrap):
    out=[]
    for r in rules:
        if r.type=='qualified-rule':
            sel=tinycss2.serialize(r.prelude).strip()
            if '<?' in sel or not sel: continue
            if re.search(r'hamburger|toggle-switch|\.slider|theme-toggle|lang-btn|splash',sel): continue
            decls=tinycss2.parse_declaration_list(r.content,skip_whitespace=True,skip_comments=True)
            ds=[]
            for d in decls:
                if d.type!='declaration': continue
                v=tinycss2.serialize(d.value).strip()
                new=decl_override(d.name,v)
                if new is not None and new!=v: ds.append(f'{d.name}:{new}')
            if ds:
                sels=split_selectors(sel)
                if any(s.startswith(('html','body',':root')) for s in sels):
                    sels=[ (P+s[4:].strip() if s.startswith('html') else s) if s.startswith('html') else P.rstrip()+' '+s for s in sels]
                    sels=[s.replace('html[data-theme="dark"] body','html[data-theme="dark"] body',1) for s in sels]
                else:
                    sels=[P+s for s in sels]
                out.append(emit_wrap(','.join(sels)+'{'+';'.join(ds)+'}'))
        elif r.type=='at-rule' and r.lower_at_keyword in('media','supports') and r.content:
            inner=tinycss2.parse_rule_list(r.content,skip_whitespace=True,skip_comments=True)
            sub=process_rules(inner,lambda x:x)
            if sub: out.append('@%s %s{\n%s\n}'%(r.lower_at_keyword,tinycss2.serialize(r.prelude).strip(),'\n'.join(sub)))
    return out

css_blobs=[]
for f in ['assets/css/style.css','assets/css/messages.css']:
    css_blobs.append((f,open(os.path.join(ROOT,f),encoding='utf-8').read()))
SNIP=re.compile(r"((?:[.#][A-Za-z_][\w\-]*|[a-z]+[.#:][\w\-]+)[^{};'\"`<>$]{0,160}?)\{([^{}]*:[^{}]*)\}")
files=[f for f in glob.glob(ROOT+'/**/*.php',recursive=True)+glob.glob(ROOT+'/assets/js/*.js') if '/lib/' not in f and '/vendor/' not in f and 'chart.umd' not in f and 'dark.' not in f]
for f in files:
    t=open(f,encoding='utf-8',errors='replace').read()
    for m in re.finditer(r'<style[^>]*>(.*?)</style>',t,re.S):
        css_blobs.append((os.path.relpath(f,ROOT),re.sub(r'<\?.*?\?>','',m.group(1),flags=re.S)))
    for m in SNIP.finditer(t):
        body=m.group(2)
        if '=>' in body or 'function' in body or '<?' in body: continue
        css_blobs.append((os.path.relpath(f,ROOT),m.group(1).strip()+'{'+body+'}'))
seen=set(); out=['/* AUTO-GENERATED by tools/gen_dark.py — dark-mode versions of every light colour in the portal CSS. Do not edit by hand. */']
for name,blob in css_blobs:
    rules=tinycss2.parse_stylesheet(blob,skip_whitespace=True,skip_comments=True)
    for line in process_rules(rules,lambda x:x):
        if line in seen: continue
        seen.add(line); out.append(line)

# ---- inline style="" overrides (hex + the rgb() form browsers re-serialise to after JS touches el.style) ----
def forms(hexv):
    h=hexv.lstrip('#'); 
    if len(h)==3: h=''.join(c*2 for c in h)
    r,g,b=(int(h[i:i+2],16) for i in (0,2,4))
    out={'#'+h, '#'+h.upper()}
    if len(set(h[i:i+2][0]==h[i:i+2][1] for i in (0,2,4)))==1 and all(h[i]==h[i+1] for i in (0,2,4)): out.add('#'+h[0]+h[2]+h[4])
    out.add('rgb(%d, %d, %d)'%(r,g,b)); out.add('rgb(%d,%d,%d)'%(r,g,b))
    return out
def inl(prop,hexes,decl,sep=(':',': ')):
    sels=[]
    for hx in hexes:
        for f in forms(hx):
            for sp in sep: sels.append(P+'[style*="%s%s%s" i]'%(prop,sp,f))
    return ','.join(sels)+'{%s}'%decl
inline=[]
BG=[(['#ffffff'],'background:#242526 !important'),
    (['#f9fafb','#f3f4f6','#f4f6f8','#f8fafc','#fafafa'],'background:#2A2B2D !important'),
    (['#fee2e2','#fef2f2'],'background:#3B1F20 !important'),
    (['#fef3c7','#fffbeb','#fef9c3'],'background:#3A2F14 !important'),
    (['#ede9fe','#f5f3ff'],'background:#2E2347 !important'),
    (['#f0fdf4','#d1fae5','#ecfdf5'],'background:#14352A !important'),
    (['#eff6ff','#dbeafe'],'background:#172A45 !important'),
    (['#f9eef0','#fdf2f4'],'background:#3A2128 !important'),
    (['#e5e7eb'],'background:#3A3B3C !important;color:#E4E6EB !important')]
for hexes,decl in BG:
    for prop in ('background','background-color'): inline.append(inl(prop,hexes,decl))
for name in ('white',):
    for prop in ('background','background-color'):
        inline.append(P+','.join(['[style*="%s%s%s" i]'%(prop,sp,name) for sp in (':',': ')]).replace(',[',','+P+'[')+'{background:#242526 !important}')
TX=[(['#1f2937','#374151','#111827','#000000'],'#E4E6EB'),(['#6b7280','#4b5563'],'#B0B3B8'),(['#991b1b','#b91c1c'],'#F28B82'),
    (['#92400e','#b45309'],'#FBBF24'),(['#065f46','#047857'],'#6EE7B7'),(['#1d4ed8','#1e40af'],'#93C5FD'),
    (['#5b21b6','#7c3aed'],'#C4B5FD'),(['#6b1a2a','#4a0f1b'],'#F0A3B2'),(['#059669'],'#34D399'),(['#dc2626','#ef4444'],'#F87171'),(['#d97706'],'#FBBF24')]
for hexes,col in TX:
    inline.append(inl('color',hexes,'color:%s !important'%col,sep=(':',': ')).replace('[style*="color','[style*=" color').replace('[style*=" color:','[style*="color:') if False else inl('color',hexes,'color:%s !important'%col))
BD=[(['#e5e7eb','#f3f4f6','#d1d5db'],'#3E4042'),(['#fecaca','#fca5a5'],'#6B2E2E'),(['#bbf7d0','#6ee7b7'],'#1F5A44'),(['#fcd34d','#fde68a'],'#6B5A1F'),(['#bfdbfe'],'#27467A'),(['#f3d2d9','#f3d0d6','#d4a0a8'],'#5A2E38')]
for hexes,col in BD:
    sels=[]
    for hx in hexes:
        for f in forms(hx):
            for kind in ('solid','dashed'): sels.append(P+'[style*="%s %s" i]'%(kind,f))
            sels.append(P+'[style*="border-color:%s" i]'%f); sels.append(P+'[style*="border-color: %s" i]'%f)
    inline.append(','.join(sels)+'{border-color:%s !important}'%col)
out.append('/* inline style="" overrides */')
out+=inline

open(os.path.join(ROOT,'assets/css/dark-auto.css'),'w',encoding='utf-8').write('\n'.join(out)+'\n')
print(len(out)-1,'rules from',len(css_blobs),'blobs')
