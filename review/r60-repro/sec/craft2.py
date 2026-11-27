"""craft2.py ORIG_TAR OUT_TAR META_JSON SPEC_JSON
SPEC_JSON: {"add": [[name, data_str, type('f'|'l'|'h'|'d'), linkname]], "sources": {...}}"""
import sys, tarfile, io, json, hashlib
orig, out, meta_path, spec = sys.argv[1:5]
spec = json.loads(spec)
src = tarfile.open(orig, 'r:')
members = [(m, src.extractfile(m).read() if m.isfile() else None) for m in src.getmembers()]
buf = io.BytesIO()
tf = tarfile.open(fileobj=buf, mode='w', format=tarfile.PAX_FORMAT if spec.get('pax') else tarfile.USTAR_FORMAT)
drop = set(spec.get('drop', []))
for m, data in members:
    if m.name in drop: continue
    if data is None: tf.addfile(m)
    else:
        if m.name in spec.get('replace', {}):
            data = spec['replace'][m.name].encode()
            m.size = len(data)
        tf.addfile(m, io.BytesIO(data))
extra = -len(drop)
for name, data, t, link in spec.get('add', []):
    ti = tarfile.TarInfo(name); ti.mode = 0o644
    if t == 'f':
        b = data.encode('utf-8', 'surrogateescape'); ti.size = len(b); tf.addfile(ti, io.BytesIO(b))
    elif t == 'd':
        ti.type = tarfile.DIRTYPE; ti.mode = 0o755; tf.addfile(ti)
    else:
        ti.type = tarfile.SYMTYPE if t == 'l' else tarfile.LNKTYPE; ti.linkname = link; tf.addfile(ti)
    if t != 'd': extra += 1
tf.close()
data = buf.getvalue()
open(out, 'wb').write(data)
meta = json.load(open(meta_path))
meta['sha256'] = hashlib.sha256(data).hexdigest(); meta['size_bytes'] = len(data)
meta['entries'] = int(meta['entries']) + extra
meta['sources'].update(spec.get('sources', {}))
json.dump(meta, open(meta_path, 'w'), indent=4)
