# Seeds the throw-away test database, links a device, opens a cashier session and drives the till in headless Chrome.
# Usage: bash tests/browser/till_test.sh <out dir> <scenario.txt>   [MAXPCT=10] [PREFIX=x]
#   <out dir>      where the screenshots (<PREFIX>*.png), cookie jars and the Chrome profile go (e.g. the session scratchpad)
#   <scenario.txt> a piece of JavaScript from tests/browser/scenarios/, spliced into till_drive.mjs (helpers: ev, waitFor, shot, type, click, shown, log, send, sleep)
# Needs: XAMPP MySQL running, Google Chrome, node 18+. Uses the test database only (retail_pos_test).
S="$1"; SCENARIO="$2"; HERE="$(cd "$(dirname "$0")" && pwd)"; cd "$HERE/../.." || exit 1
[ -n "$S" ] && [ -f "$SCENARIO" ] || { echo "usage: till_test.sh <out dir> <scenario.txt>"; exit 2; }
mkdir -p "$S"
PHP=/c/xampp/php/php.exe; B="http://127.0.0.1:8191/index.php"; OUT="$S/till"; mkdir -p "$OUT"; rm -f "$OUT"/${PREFIX:-[0-9]}*.png
export RETAIL_POS_ENV=test

$PHP -r 'require "tests/bootstrap.php"; test_db_reset();' || exit 1
$PHP bin/seed-demo.php --yes | tail -1
$PHP -r 'require "app/bootstrap.php"; (new App\Models\User())->setPin(1, "2468"); echo "admin PIN set\n";'
$PHP "$HERE/set_max.php"

($PHP -S 127.0.0.1:8191 -t public >/dev/null 2>&1 &)
sleep 2
tok() { curl -s -b "$1" -c "$1" "$B?r=$2" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

A="$S/admin.jar"; C="$S/cashier.jar"; rm -f "$A" "$C"
T=$(tok "$A" auth/login); curl -s -b "$A" -c "$A" -o /dev/null -d "_token=$T&username=admin&password=admin-pass-123" "$B?r=auth/login"
T=$(tok "$A" registers);  curl -s -b "$A" -c "$A" -o /dev/null -w "bind register 2: %{http_code}\n" -d "_token=$T&id=2" "$B?r=registers/bind"

T=$(tok "$C" auth/login); curl -s -b "$C" -c "$C" -o /dev/null -d "_token=$T&username=sara&password=password123" "$B?r=auth/login"
grep "rpos_device" "$A" >> "$C"
T=$(tok "$C" sessions/open); curl -s -b "$C" -c "$C" -o /dev/null -w "cashier opens a session: %{http_code}\n" -d "_token=$T&opening_usd=50&opening_lbp=0" "$B?r=sessions/open"
curl -s -b "$C" -o /dev/null -w "till page: %{http_code}\n" "$B?r=pos"

rm -rf "$S/chrome-cdp"
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu --no-sandbox --hide-scrollbars --remote-debugging-port=9333 --user-data-dir="$S/chrome-cdp" about:blank >/dev/null 2>&1 &
# the driver = the boilerplate with the scenario spliced in place of its default steps
$PHP -r '$t = file_get_contents($argv[1]); $a = strpos($t, "// SCENARIO"); $b = strpos($t, "// END SCENARIO"); file_put_contents($argv[3], substr($t, 0, $a) . file_get_contents($argv[2]) . "\n" . substr($t, $b));' "$HERE/till_drive.mjs" "$SCENARIO" "$S/till_drive.run.mjs"
node "$S/till_drive.run.mjs" "$C" "$OUT"; RC=$?

for port in 8191 9333; do for pid in $(netstat -ano | grep ":$port" | grep LISTEN | awk '{print $5}' | sort -u); do taskkill //F //PID "$pid" >/dev/null 2>&1; done; done
rm -f "$A" "$C"
$PHP -r 'require "app/bootstrap.php"; $p = App\Core\Database::pdo(); print_r($p->query("SELECT s.invoice_no, u.username, s.subtotal_usd, s.discount_usd, s.total_usd, (SELECT SUM(line_discount_usd) FROM sale_items i WHERE i.sale_id = s.id) AS line_discounts FROM sales s JOIN users u ON u.id = s.user_id ORDER BY s.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)); print_r($p->query("SELECT action, details FROM audit_log WHERE action LIKE \"pin.%\" ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));'
ls "$OUT"; exit $RC
