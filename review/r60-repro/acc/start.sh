#!/bin/bash
A=/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/acc
export TMPDIR=$A/tmp
declare -A P=([sk]=18631 [hu]=18633 [de]=18634)
for l in sk hu de; do
 pgrep -f "php -S 127.0.0.1:${P[$l]}" >/dev/null || (cd $A/app-$l && TMPDIR=$A/tmp setsid nohup php -S 127.0.0.1:${P[$l]} router.php >> $A/php-$l.log 2>&1 &)
done
pgrep -f "smtpsink.py 18639" >/dev/null || setsid nohup python3 $A/scripts/smtpsink.py 18639 $A/mail > $A/smtp.log 2>&1 &
