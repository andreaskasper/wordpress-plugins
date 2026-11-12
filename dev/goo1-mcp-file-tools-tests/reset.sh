#!/bin/sh
docker exec testenv-cli-1 wp plugin deactivate voting >/dev/null 2>&1
docker exec testenv-wp-1 sh -c '
cd /var/www/html/wp-content
rm -rf plugins/voting plugins/other plugins/symplug uploads/2026 uploads/wf uploads/elementor updraft outside-target
mkdir -p plugins/voting/data plugins/voting/includes plugins/other uploads/2026/11 updraft
echo "<?php // plugin" > plugins/voting/voting.php
echo "a@b.c" > plugins/voting/data/valid-emails.txt
echo "t,n" > plugins/voting/data/votes.csv
echo "{\"a\":1}" > plugins/voting/data/dancers.json
echo secret > plugins/voting/.env
echo x > plugins/voting/wp-config.php.bak
echo dump > plugins/voting/backup.sql
ln -sfn /etc plugins/voting/etclink
ln -sfn ../other plugins/voting/otherlink
ln -sfn /var/www/html/wp-content/plugins/other plugins/symplug
echo z > updraft/x.zip
chown -R www-data:www-data plugins/voting plugins/other uploads updraft
'
docker exec testenv-cli-1 wp db query "TRUNCATE wp_goo1_mcp_backups; TRUNCATE wp_goo1_mcp_audit_log;" >/dev/null
docker exec testenv-cli-1 wp eval-file /tmp/setup_keys.php >/dev/null && docker exec testenv-cli-1 cat /tmp/keys.json > /home/claude/tests/keys.json
echo reset-done
