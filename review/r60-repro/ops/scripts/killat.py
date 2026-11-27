import subprocess, sys, time, glob, os
# killat.py <cwd> <seconds|glob> -- cmd...: run cmd, SIGKILL after <seconds> or once <glob> matches
cwd, when = sys.argv[1], sys.argv[2]; cmd = sys.argv[sys.argv.index('--') + 1:]
out = open(os.environ['TMPDIR'] + '/killat.out', 'w')
p = subprocess.Popen(cmd, cwd=cwd, stdout=out, stderr=subprocess.STDOUT)
t0 = time.time()
try:
    secs = float(when); pat = None
except ValueError:
    secs = None; pat = when
while p.poll() is None:
    if (secs is not None and time.time() - t0 >= secs) or (pat and glob.glob(pat)):
        time.sleep(float(os.environ.get('DELAY','0'))); p.kill(); print('killed after %.2fs; matched %s' % (time.time() - t0, glob.glob(pat)[:3] if pat else '-')); break
    time.sleep(0.005)
p.wait(); print('rc', p.returncode, 'elapsed %.2f' % (time.time() - t0))
