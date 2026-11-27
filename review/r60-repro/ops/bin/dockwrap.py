import sys, os, subprocess
tool = sys.argv[1]
args = []
result = None
for a in sys.argv[2:]:
    if a.startswith('--defaults-extra-file=') or a.startswith('--defaults-file='):
        for line in open(a.split('=', 1)[1], encoding='utf-8'):
            line = line.strip()
            if '=' not in line or line.startswith('['):
                continue
            k, v = line.split('=', 1)
            v = v.strip()
            if len(v) >= 2 and v[0] == '"' and v[-1] == '"':
                v = v[1:-1].replace('\\"', '"').replace('\\n', '\n').replace('\\r', '\r').replace('\\t', '\t').replace('\\\\', '\\')
            args.append('--%s=%s' % (k.strip(), v))
    elif a.startswith('--result-file='):
        result = a.split('=', 1)[1]
    else:
        args.append(a)
cmd = ['docker', 'exec', '-i', 'bbe-mysql', tool] + args
if result is None:
    sys.exit(subprocess.call(cmd))
with open(result, 'wb') as out:
    rc = subprocess.call(cmd, stdout=out)
sys.exit(rc)
