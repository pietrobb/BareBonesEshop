import sys, email, os, glob, html, re
from email import policy
files = sorted(glob.glob(sys.argv[1] + '/*.eml'))
sel = sys.argv[2:] 
for f in files:
    if sel and not any(s in f for s in sel): continue
    m = email.message_from_bytes(open(f,'rb').read(), policy=policy.default)
    print('=====', os.path.basename(f), '| To:', m['To'], '| Subj:', m['Subject'], '| From:', m['From'])
    for part in m.walk():
        ct = part.get_content_type()
        if part.is_multipart(): continue
        fn = part.get_filename()
        if fn: print('  [ATTACHMENT]', fn, ct, len(part.get_payload(decode=True) or b'')); continue
        if ct == 'text/plain' and len(sel)>0: print(part.get_content()[:int(os.environ.get('LEN','6000'))])
        elif ct == 'text/plain': print('  text/plain', len(part.get_content()))
        elif ct == 'text/html': print('  text/html', len(part.get_content()))
