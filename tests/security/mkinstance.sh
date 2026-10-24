#!/bin/bash
# Frische Instanz aus Quellcode $1 unter Apache (Port 8097) aufbauen und installieren. $2 = Zusatzargumente für den Installer
SRC=${1:-/home/claude/web}
APP=/home/claude/work/sec/app
if [ -f /home/claude/work/sec/apache/apache.pid ]; then kill $(cat /home/claude/work/sec/apache/apache.pid) 2>/dev/null; sleep 1; fi
rm -rf $APP && cp -a $SRC $APP && rm -f $APP/config.php
find $APP -name '*.pdf' -path '*storage*' -delete 2>/dev/null
touch $APP/install.unlock
chown -R www-data:www-data $APP
timeout 20 mysql -uroot -e "DROP DATABASE IF EXISTS rechnung_sec; CREATE DATABASE rechnung_sec CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'rg'@'localhost' IDENTIFIED BY 'geheim123'; GRANT ALL ON rechnung_sec.* TO 'rg'@'localhost'; FLUSH PRIVILEGES;"
: > /home/claude/work/sec/apache/error.log; : > /home/claude/work/sec/apache/php_error.log
apache2 -f /home/claude/work/sec/apache/apache2.conf -k start
for i in $(seq 1 15); do curl -s -o /dev/null http://127.0.0.1:8097/install.php && break; sleep 1; done
timeout 120 curl -s -X POST http://127.0.0.1:8097/install.php \
 -d action=install -d db_host=127.0.0.1 -d db_name=rechnung_sec -d db_user=rg -d db_pass=geheim123 \
 -d admin_user=kai -d admin_pass=SehrGeheim12345 -d admin_pass2=SehrGeheim12345 -d test_mode=1 \
 -d company_name='Test IT-Service' -d company_owner='Kai Test' -d company_street='Musterstr. 1' -d company_zip=26871 -d company_city=Papenburg -d company_country=DE \
 -d company_email=rechnung@example.de -d company_phone='+49 4961 123' -d company_tax_number='12/345/67890' -d company_iban='DE89 3704 0044 0532 0130 00' -d company_bic=COBADEFFXXX -d company_bank=Commerzbank \
 -d invoice_prefix=RE -d payment_days=14 -d smtp_host=127.0.0.1 -d smtp_port=2525 -d smtp_secure=none -d mail_from=rechnung@example.de $2 \
 | python3 -I -c "import sys,re,html; t=sys.stdin.read(); m=re.search(r'<h1>(.*?)</h1>',t); print('Installer:', html.unescape(m.group(1)) if m else t[:200])"
ls $APP/config.php 2>&1 | tail -1
