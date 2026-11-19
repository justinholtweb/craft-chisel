# Prints the Chisel content HTML (and any flash) from a CP page response on stdin.
# Craft 6 CP pages are Inertia apps: the screen arrives as JSON in #app[data-page].
import html, json, re, sys

raw = sys.stdin.read()
m = re.search(r'<script data-page="app" type="application/json">(.*?)</script>', raw, re.S)
if not m:
    print(raw[:2000])
    sys.exit(0)
page = json.loads(m.group(1))
out = []

def walk(v):
    if isinstance(v, str):
        if 'class="chisel"' in v:
            out.append(v)
    elif isinstance(v, dict):
        for x in v.values():
            walk(x)
    elif isinstance(v, list):
        for x in v:
            walk(x)

walk(page.get('props', {}))
flash = page.get('props', {}).get('flash') or {}
for k in ('success', 'error', 'notice'):
    if flash.get(k):
        print(f'FLASH-{k}: {flash[k]}')
print('\n'.join(out))
