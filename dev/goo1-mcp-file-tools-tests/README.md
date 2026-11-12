# Tests for the goo1-mcp file tools (1.9)

Docker test site (WordPress php8.3-apache + MariaDB 11 + wp-cli) used while building the file tools.
Not part of the plugin ZIP (outside `src/`).

1. Clone this repo to `/home/claude/wordpress-plugins` (paths in the scripts assume it; adjust `docker-compose.yml` otherwise).
2. Copy `docker-compose.yml` to `/home/claude/testenv/` and the rest to `/home/claude/tests/`. Start `dockerd` if needed, then `docker compose up -d` in `/home/claude/testenv`.
3. Install WordPress: `docker compose exec -T cli wp core install --url=http://wp.test --title=Test --admin_user=andreas --admin_password=Admin-Pass-xyz123 --admin_email=a@example.com --skip-email`, then `wp plugin activate goo1-mcp`.
4. `docker cp setup_keys.php testenv-cli-1:/tmp/` and run `./run_all.sh`.

`t_fetch.py` needs a static server container `testenv-files` (alias `files.test`, `php -S 0.0.0.0:80 -t /srv` with files `logo.png`, `lib.txt`, `broken.txt`, `bad.json`, `big.bin`, `logo.sha`) and an mu-plugin that filters `http_request_host_is_external` to true for `files.test`.
`t_lean.py` needs an Apache snippet `<Directory …/plugins/voting/strict> AllowOverride AuthConfig Limit </Directory>` in `conf-enabled` (simulates shared hosting where `Options` in .htaccess gives 500).
`t_review.py` (regression tests for the 13 findings of the first independent review, R1–R13) and `t_review2.py` (second review, N1–N9 + minor) also needs the redirect scripts from `files-server/` in the same `/srv` folder (`to-own.php`, `to-own-dot.php`, `to-lib.php`, `loop.php`, `to-private.php`; `php -S` runs them) and the `strict` Apache snippet; R7 simulates a multisite sub-site with an `upload_dir` filter and the `UPLOADS` constant.
`t74.php`: PHP 7.4 run via `php:7.4-cli` + mysqli and the wp-cli phar copied from the cli container; pass the `WORDPRESS_DB_*` variables to that container (the official wp-config reads them).
