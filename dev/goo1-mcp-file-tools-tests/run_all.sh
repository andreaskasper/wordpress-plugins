#!/bin/sh
cd /home/claude/tests
./reset.sh
docker cp t_access.php testenv-cli-1:/tmp/t_access.php >/dev/null
echo "== access"; docker exec testenv-cli-1 wp eval-file /tmp/t_access.php 2>&1 | tail -5
./reset.sh >/dev/null
echo "== tools"; python3 -c "exec(open('t_tools.py').read()); print('pass',passes[0],'fail',len(fails))"
echo "== verify"; python3 t_verify.py
echo "== more"; python3 t_more.py
./reset.sh >/dev/null; echo "== fetch"; python3 t_fetch.py
./reset.sh >/dev/null; echo "== lean"; python3 t_lean.py
./reset.sh >/dev/null; echo "== regress"; python3 t_regress.py
./reset.sh >/dev/null; echo "== review"; python3 t_review.py
