<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\CustomerImport;
use App\Einvoice;
use App\Invoices;
use App\Letterhead;
use App\Articles;
use App\Attachments;
use App\Logo;
use App\PdfDesign;
use App\Mailer;
use App\RateLimit;
use App\Theme;
use App\Recurring;

$p = $_GET['p'] ?? 'invoices';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

// --- Anmeldung -----------------------------------------------------------
const LOGIN_MAX = 5;           // Fehlversuche je IP ...
const LOGIN_WINDOW = 900;      // ... innerhalb von 15 Minuten
const LOGIN_MAX_GLOBAL = 40;   // Fehlversuche insgesamt (verteilte Angriffe)

// Sitzung nach Passwort-/Benutzerwechsel oder nach 12 Stunden ungültig
if (!empty($_SESSION['auth'])) {
    if (!hash_equals(auth_fingerprint(), (string)($_SESSION['fp'] ?? '')) || time() - (int)($_SESSION['t0'] ?? 0) > 12 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
        csrf();
    }
}

if (empty($_SESSION['auth'])) {
    $error = null;
    if ($post) {
        $ipKey = 'login:' . RateLimit::ip();
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? '')) || ($_SESSION['csrf'] ?? '') === '') {
            $error = 'Das Formular ist abgelaufen. Bitte erneut versuchen.';
        } elseif (RateLimit::blocked($ipKey, LOGIN_MAX, LOGIN_WINDOW) || RateLimit::blocked('login:all', LOGIN_MAX_GLOBAL, LOGIN_WINDOW)) {
            http_response_code(429);
            $error = 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.';
        } else {
            $userOk = hash_equals(env('APP_USER', 'admin'), (string)($_POST['user'] ?? ''));
            $passOk = password_verify((string)($_POST['pass'] ?? ''), env('APP_PASSWORD_HASH'));
            if ($userOk && $passOk) {
                RateLimit::clear($ipKey);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                $_SESSION['fp'] = auth_fingerprint();
                $_SESSION['t0'] = time();
                csrf();
                redirect(BASE);
            }
            RateLimit::fail($ipKey, LOGIN_WINDOW);
            RateLimit::fail('login:all', LOGIN_WINDOW);
            sleep(1);
            $error = 'Anmeldung fehlgeschlagen.';
        }
    }
    render('login', ['error' => $error, 'bare' => true]);
    exit;
}

if ($post && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('Die Anfrage war zu groß für den Server (post_max_size ' . ini_get('post_max_size') . ', Datei-Limit ' . ini_get('upload_max_filesize') . '). Bitte kleinere Dateien wählen.');
    redirect(safe_target($_SERVER['REQUEST_URI'] ?? ''));
}
if ($post) {
    csrf_check();
}
if ($p === 'logout') {
    if ($post) {
        $_SESSION = [];
        session_destroy();
    }
    redirect(BASE);
}

try {
    switch ($p) {
        case 'customers':
            render('customers', ['customers' => db()->query('SELECT * FROM customers ORDER BY name')->fetchAll()]);
            break;

        case 'customer_import':
            if (isset($_GET['template'])) {
                $csv = CustomerImport::templateCsv();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="kunden-vorlage.csv"');
                header('Content-Length: ' . strlen($csv));
                // Geprüft: feste Vorlage ohne Benutzereingabe
                // nosemgrep
                echo $csv;
                exit;
            }
            $tmpDir = __DIR__ . '/storage/tmp';
            $cleanup = static function () use ($tmpDir): void {
                $imp = $_SESSION['import'] ?? null;
                if (is_array($imp) && preg_match('/^import-[0-9a-f]{24}\.csv$/D', (string)($imp['file'] ?? ''))) {
                    // Geprüft: Dateiname stammt aus der eigenen Sitzung und ist auf [0-9a-f] geprüft
                    // nosemgrep
                    @unlink($tmpDir . '/' . $imp['file']);
                }
                unset($_SESSION['import']);
                foreach (glob($tmpDir . '/import-*.csv') ?: [] as $f) {
                    if (filemtime($f) < time() - 3600) {
                        // nosemgrep
                        @unlink($f);   // Reste älter als eine Stunde
                    }
                }
            };
            $imp = $_SESSION['import'] ?? null;
            $file = null;
            if (is_array($imp) && preg_match('/^import-[0-9a-f]{24}\.csv$/D', (string)($imp['file'] ?? '')) && time() - (int)($imp['time'] ?? 0) < 3600 && is_file($tmpDir . '/' . $imp['file'])) {
                $file = $tmpDir . '/' . $imp['file'];
            }
            $a = $post ? (is_string($_POST['action'] ?? '') ? (string)$_POST['action'] : '') : '';
            if ($post && $a === 'cancel') {
                $cleanup();
                flash('Import abgebrochen.');
                redirect(BASE . '?p=customers');
            }
            if ($post && $a === 'upload') {
                $up = $_FILES['csv'] ?? null;
                $err = (int)($up['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new RuntimeException('Die Datei ist zu groß (höchstens ' . (int)(CustomerImport::MAX_BYTES / 1048576) . ' MB).');
                }
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($up['tmp_name'] ?? ''))) {
                    throw new RuntimeException('Bitte eine CSV-Datei auswählen.');
                }
                if ((int)($up['size'] ?? 0) > CustomerImport::MAX_BYTES) {
                    throw new RuntimeException('Die Datei ist zu groß (höchstens ' . (int)(CustomerImport::MAX_BYTES / 1048576) . ' MB).');
                }
                $text = CustomerImport::decode((string)file_get_contents((string)$up['tmp_name'], false, null, 0, CustomerImport::MAX_BYTES + 1));
                $dl = is_string($_POST['delimiter'] ?? '') ? (string)$_POST['delimiter'] : 'auto';
                $delim = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t", 'pipe' => '|'][$dl] ?? CustomerImport::detectDelimiter($text);
                CustomerImport::parse($text, $delim);   // prüft Aufbau und Grenzen
                if (!is_dir($tmpDir)) {
                    mkdir($tmpDir, 0775, true);
                }
                $cleanup();
                $name = 'import-' . bin2hex(random_bytes(12)) . '.csv';
                if (file_put_contents($tmpDir . '/' . $name, $text, LOCK_EX) === false) {
                    throw new RuntimeException('Die Datei konnte nicht zwischengespeichert werden (Schreibrechte auf storage/?).');
                }
                @chmod($tmpDir . '/' . $name, 0600);
                $_SESSION['import'] = ['file' => $name, 'delim' => $delim, 'orig' => mb_substr(clean(basename((string)($up['name'] ?? 'datei.csv'))), 0, 80), 'time' => time()];
                redirect(BASE . '?p=customer_import');
            }
            $view = ['step' => 'upload'];
            if ($file !== null) {
                $rows = CustomerImport::parse((string)file_get_contents($file), (string)$imp['delim']);
                $hasHeader = $post ? isset($_POST['has_header']) : true;
                $cols = 0;
                foreach (array_slice($rows, 0, 300) as $r) {
                    $cols = max($cols, count($r));
                }
                $header = $hasHeader ? array_shift($rows) : array_map(static fn($i) => 'Spalte ' . ($i + 1), range(0, max(0, $cols - 1)));
                $cols = max($cols, count($header));
                $mode = ($post && ($_POST['mode'] ?? '') === 'update') ? 'update' : 'skip';
                $mapErrors = [];
                if ($post && ($a === 'preview' || $a === 'import')) {
                    [$map, $mapErrors] = CustomerImport::checkMap($_POST['map'] ?? [], $cols);
                } else {
                    $map = $hasHeader ? CustomerImport::autoMap($header) : array_fill(0, $cols, '');
                    $map = array_pad($map, $cols, '');
                }
                $plan = null;
                if (!$mapErrors) {
                    $plan = CustomerImport::plan($rows, $map, $mode, $hasHeader ? 2 : 1);
                    if ($post && $a === 'import') {
                        if (($plan['counts']['new'] + $plan['counts']['update']) === 0) {
                            $mapErrors[] = 'Es gibt keine importierbaren Zeilen.';
                        } else {
                            $res = CustomerImport::apply($plan, $map);
                            $cleanup();
                            flash($res['new'] . ' Kunde(n) angelegt, ' . $res['update'] . ' aktualisiert, ' . $plan['counts']['duplicate'] . ' als Duplikat übersprungen, ' . $plan['counts']['error'] . ' fehlerhafte Zeile(n) nicht importiert.');
                            redirect(BASE . '?p=customers');
                        }
                    }
                }
                $view = ['step' => 'preview', 'orig' => (string)($imp['orig'] ?? ''), 'header' => $header, 'rows' => $rows, 'cols' => $cols, 'map' => $map, 'mode' => $mode,
                         'hasHeader' => $hasHeader, 'plan' => $plan, 'errors' => $mapErrors];
            }
            render('customer_import', $view);
            break;

        case 'customer_edit':
            $id = (int)($_GET['id'] ?? 0);
            if ($post) {
                $v = [];
                $max = ['name' => 190, 'contact' => 190, 'email' => 190, 'street' => 190, 'zip' => 20, 'city' => 120, 'country' => 2, 'vat_id' => 30, 'buyer_reference' => 100];
                foreach ($max as $k => $len) {
                    $x = is_string($_POST[$k] ?? '') ? clean($_POST[$k] ?? '') : '';
                    if (mb_strlen($x) > $len) {
                        throw new RuntimeException('Das Feld „' . $k . '“ ist zu lang (höchstens ' . $len . ' Zeichen).');
                    }
                    $v[$k] = $x === '' ? null : $x;
                }
                $v['country'] = strtoupper($v['country'] ?? 'DE');
                if (!$v['name'] || !$v['street'] || !$v['zip'] || !$v['city']) {
                    throw new RuntimeException('Name, Straße, PLZ und Ort sind Pflichtfelder.');
                }
                if (!preg_match('/^[A-Z]{2}$/', $v['country'])) {
                    throw new RuntimeException('Das Land muss ein zweistelliger Ländercode sein (z. B. DE).');
                }
                if ($v['email'] !== null && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Die E-Mail-Adresse des Kunden ist ungültig.');
                }
                if ($v['vat_id'] !== null && !preg_match('/^[A-Za-z0-9 .\-\/]{1,30}$/', $v['vat_id'])) {
                    throw new RuntimeException('Die USt-IdNr. enthält unzulässige Zeichen.');
                }
                if ($id) {
                    db()->prepare('UPDATE customers SET name=:name, contact=:contact, email=:email, street=:street, zip=:zip, city=:city, country=:country, vat_id=:vat_id, buyer_reference=:buyer_reference WHERE id=:id')
                        ->execute($v + ['id' => $id]);
                } else {
                    db()->prepare('INSERT INTO customers (name, contact, email, street, zip, city, country, vat_id, buyer_reference) VALUES (:name, :contact, :email, :street, :zip, :city, :country, :vat_id, :buyer_reference)')
                        ->execute($v);
                    $id = (int)db()->lastInsertId();
                }
                flash('Kunde gespeichert.');
                redirect(($_GET['next'] ?? '') === 'invoice_edit' ? BASE . '?p=invoice_edit&customer=' . $id : BASE . '?p=customers');
            }
            $c = null;
            if ($id) {
                $st = db()->prepare('SELECT * FROM customers WHERE id = ?');
                $st->execute([$id]);
                $c = $st->fetch() ?: null;
            }
            render('customer_form', ['c' => $c]);
            break;

        case 'articles':
            render('articles', ['articles' => Articles::all()]);
            break;

        case 'article_edit':
            $id = (int)($_GET['id'] ?? 0);
            $a = $id ? Articles::find($id) : null;
            if ($id && !$a) {
                throw new RuntimeException('Artikel nicht gefunden.');
            }
            if ($post) {
                if (($_POST['action'] ?? '') === 'delete' && $a) {
                    Articles::delete($id);
                    flash('Artikel gelöscht. Bereits erstellte Rechnungen bleiben unverändert.');
                    redirect(BASE . '?p=articles');
                }
                if (($_POST['action'] ?? '') === 'duplicate' && $a) {
                    $newId = Articles::duplicate($id);
                    flash('Kopie angelegt – bitte Bezeichnung und Preis anpassen.');
                    redirect(BASE . '?p=article_edit&id=' . $newId);
                }
                $new = Articles::save($id ?: null, Articles::validate($_POST));
                flash('Artikel gespeichert.');
                redirect(BASE . '?p=articles');
            }
            render('article_form', ['a' => $a]);
            break;

        case 'design':
            if ($post) {
                $a = $_POST['action'] ?? 'save';
                if ($a === 'reset') {
                    Theme::reset();
                    flash('Eigenes CSS entfernt – das Standard-Design ist wieder aktiv.');
                } else {
                    Theme::save(Theme::validate($_POST['css'] ?? ''));
                    flash('Gespeichert. Das eigene CSS gilt jetzt für alle Seiten.');
                }
                redirect(BASE . '?p=design');
            }
            render('design', ['css' => Theme::get()]);
            break;

        case 'pdf_design':
            $errors = [];
            $notice = '';
            $d = PdfDesign::current();
            if ($post) {
                $a = isset($_POST['template']) ? 'template' : (is_string($_POST['action'] ?? '') ? (string)$_POST['action'] : 'save');
                [$form, $formErrors] = PdfDesign::fromForm($_POST);
                if ($a === 'template') {
                    $tpl = PdfDesign::template(is_string($_POST['template'] ?? '') ? (string)$_POST['template'] : '');
                    if ($tpl === null) {
                        $errors[] = 'Unbekannte Vorlage.';
                        $d = $form;
                    } else {
                        // Vorlage ändert nur das Aussehen; Zusatztexte und Schalter aus dem Formular bleiben
                        $d = array_merge($form, array_intersect_key($tpl, array_flip(['font', 'size', 'accent', 'table', 'sender', 'title_accent'])));
                        $notice = 'Vorlage „' . PdfDesign::TEMPLATES[(string)$_POST['template']]['label'] . '“ geladen – noch nicht gespeichert. Mit „Vorschau“ ansehen, mit „Speichern“ übernehmen.';
                    }
                } elseif ($a === 'reset') {
                    Config::save(['PDF_DESIGN' => '']);
                    flash('PDF-Design auf das klassische Standard-Aussehen zurückgesetzt.');
                    redirect(BASE . '?p=pdf_design');
                } elseif ($a === 'preview' || $a === 'save') {
                    $d = $form;
                    if (!$formErrors && $a === 'preview') {
                        $bytes = Einvoice::pdf(Letterhead::sampleInvoice($form));
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: inline; filename="design-vorschau.pdf"');
                        header('Cache-Control: no-store');
                        header('Content-Length: ' . strlen($bytes));
                        // Geprüft: erzeugte PDF-Daten mit festem Content-Type (kein HTML)
                        // nosemgrep
                        echo $bytes;
                        exit;
                    }
                    if (!$formErrors) {
                        Config::save(['PDF_DESIGN' => PdfDesign::toJson($form)]);
                        flash('PDF-Design gespeichert. Es gilt für neu ausgestellte Rechnungen und Entwürfe; bereits ausgestellte Rechnungen bleiben unverändert.');
                        redirect(BASE . '?p=pdf_design');
                    }
                    $errors = $formErrors;
                }
            }
            $lg = Logo::info();
            render('pdf_design', ['d' => $d, 'errors' => $errors, 'notice' => $notice, 'logoColor' => $lg ? PdfDesign::suggestFromImage($lg['path']) : null]);
            break;

        case 'recurring':
            if ($post) {
                $a = $_POST['action'] ?? '';
                if ($a === 'run_due') {
                    $res = Recurring::runDue();
                    $bad = count(array_filter($res, static fn($r) => $r['status'] === 'fehler'));
                    flash(!$res ? 'Es war nichts fällig.' : count($res) . ' Rechnung(en) erzeugt' . ($bad ? ', davon ' . $bad . ' mit Fehlern (siehe Liste).' : '.'));
                } elseif ($a === 'cron_token') {
                    Config::save(['CRON_TOKEN' => bin2hex(random_bytes(20))]);
                    flash('Neues Cron-Token erzeugt. Bitte den Cronjob-Aufruf (HTTP) anpassen.');
                } elseif ($a === 'cron_token_off') {
                    Config::save(['CRON_TOKEN' => '']);
                    flash('Aufruf per HTTP deaktiviert (die Kommandozeile funktioniert weiterhin).');
                }
                redirect(BASE . '?p=recurring');
            }
            render('recurring', ['rows' => Recurring::all()]);
            break;

        case 'recurring_edit':
            $id = (int)($_GET['id'] ?? 0);
            $rec = $id ? Recurring::find($id) : null;
            if ($id && !$rec) {
                throw new RuntimeException('Vorlage nicht gefunden.');
            }
            if ($post) {
                if (($_POST['action'] ?? '') === 'delete' && $rec) {
                    Recurring::delete($id);
                    flash('Vorlage gelöscht. Bereits erzeugte Rechnungen bleiben unverändert.');
                    redirect(BASE . '?p=recurring');
                }
                Recurring::save($id ?: null, $_POST);
                flash('Vorlage gespeichert.');
                redirect(BASE . '?p=recurring');
            }
            render('recurring_form', [
                'r' => $rec,
                'customers' => db()->query('SELECT id, name, email FROM customers ORDER BY name')->fetchAll(),
                'articles' => Articles::all(),
            ]);
            break;

        case 'invoice_edit':
            $id = (int)($_GET['id'] ?? 0);
            $inv = $id ? Invoices::find($id) : null;
            if ($id && (!$inv || $inv['status'] !== 'draft')) {
                throw new RuntimeException('Nur Entwürfe können bearbeitet werden.');
            }
            if ($post) {
                if (!(int)($_POST['customer_id'] ?? 0)) {
                    throw new RuntimeException('Bitte einen Kunden wählen.');
                }
                $newId = Invoices::saveDraft($id ?: null, $_POST);
                if (($_POST['after'] ?? '') === 'preview') {
                    redirect(BASE . '?p=draft_preview&id=' . $newId);
                }
                flash('Entwurf gespeichert.');
                redirect(BASE . '?p=invoice&id=' . $newId);
            }
            render('invoice_form', [
                'inv' => $inv,
                'credit' => $inv && Invoices::isCredit($inv),
                'customers' => db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(),
                'articles' => Articles::all(),
                'preselect' => (int)($_GET['customer'] ?? 0),
            ]);
            break;

        case 'invoice':
            $id = (int)($_GET['id'] ?? 0);
            $inv = Invoices::find($id);
            if (!$inv) {
                http_response_code(404);
                exit('Rechnung nicht gefunden.');
            }
            if ($post) {
                $a = $_POST['action'] ?? '';
                if ($a === 'issue') {
                    Invoices::issue($id);
                    flash('Rechnung ausgestellt, ZUGFeRD-PDF erzeugt.');
                } elseif ($a === 'delete' && $inv['status'] === 'draft') {
                    db()->prepare("DELETE FROM invoices WHERE id = ? AND status = 'draft'")->execute([$id]);
                    flash('Entwurf gelöscht.');
                    redirect(BASE);
                } elseif ($a === 'cancel') {
                    $newId = Invoices::createCancellation($id);
                    flash('Stornoentwurf angelegt – bitte prüfen und ausstellen. Erst mit dem Ausstellen ist die Rechnung storniert.');
                    redirect(BASE . '?p=invoice&id=' . $newId);
                } elseif ($a === 'paid' && !Invoices::isCredit($inv) && !$inv['cancelled_by']) {
                    $d = (string)($_POST['paid_at'] ?? '');
                    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
                    db()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'issued'")->execute([$d, $id]);
                    flash('Als bezahlt markiert – auf der Statusseite des Kunden sichtbar.');
                } elseif ($a === 'unpaid' && !Invoices::isCredit($inv) && !$inv['cancelled_by']) {
                    db()->prepare("UPDATE invoices SET status = 'issued', paid_at = NULL WHERE id = ? AND status = 'paid'")->execute([$id]);
                    flash('Wieder auf „offen“ gesetzt.');
                } elseif ($a === 'duplicate') {
                    $newId = Invoices::duplicate($id);
                    flash('Kopie als neuer Entwurf angelegt – bitte Daten prüfen und anpassen.');
                    redirect(BASE . '?p=invoice_edit&id=' . $newId);
                } elseif ($a === 'send' && $inv['number']) {
                    $to = trim((string)($_POST['to'] ?? ''));
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Ungültige E-Mail-Adresse.');
                    }
                    $extra = Attachments::collect($_FILES['attachments'] ?? null, $inv['number'] . '.pdf');
                    Mailer::sendInvoice($inv, Invoices::pdfBytes($inv), $to, (string)($_POST['message'] ?? ''), Invoices::statusUrl($inv), $extra);
                    db()->prepare('UPDATE invoices SET sent_at = NOW() WHERE id = ?')->execute([$id]);
                    flash('E-Mail an ' . $to . ' gesendet' . ($extra ? ' (mit ' . count($extra) . ' zusätzlichen Anhängen)' : '') . '.');
                }
                redirect(BASE . '?p=invoice&id=' . $id);
            }
            render('invoice_show', ['inv' => $inv]);
            break;

        case 'pdf':
            $inv = Invoices::find((int)($_GET['id'] ?? 0));
            if (!$inv || !$inv['number']) {
                http_response_code(404);
                exit('PDF nicht gefunden.');
            }
            $bytes = Invoices::pdfBytes($inv);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $inv['number'] . '.pdf"');
            header('Content-Length: ' . strlen($bytes));
            // Geprüft: erzeugte PDF/XML-Daten mit festem Content-Type (kein HTML), keine Benutzereingabe
            // nosemgrep
            echo $bytes;
            exit;

        case 'draft_preview':
            // PDF-Vorschau eines Entwurfs: wird nur erzeugt und ausgeliefert, nichts gespeichert, keine Nummer vergeben
            $inv = Invoices::find((int)($_GET['id'] ?? 0));
            if (!$inv) {
                http_response_code(404);
                exit('Rechnung nicht gefunden.');
            }
            if ($inv['status'] !== 'draft') {
                redirect(BASE . '?p=pdf&id=' . (int)$inv['id']);
            }
            $inv['number'] = 'ENTWURF';
            $bytes = Einvoice::pdf($inv);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="entwurf-vorschau.pdf"');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($bytes));
            // Geprüft: erzeugte PDF-Daten mit festem Content-Type (kein HTML), keine Benutzereingabe
            // nosemgrep
            echo $bytes;
            exit;

        case 'xrechnung':
            $inv = Invoices::find((int)($_GET['id'] ?? 0));
            if (!$inv || !$inv['number']) {
                http_response_code(404);
                exit('Rechnung nicht gefunden.');
            }
            $xml = Einvoice::xml($inv, true);
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $inv['number'] . '_xrechnung.xml"');
            header('Content-Length: ' . strlen($xml));
            // Geprüft: erzeugte PDF/XML-Daten mit festem Content-Type (kein HTML), keine Benutzereingabe
            // nosemgrep
            echo $xml;
            exit;

        case 'letterhead_file':
            $lh = Letterhead::info();
            if (!$lh) {
                http_response_code(404);
                exit('Kein Briefpapier hinterlegt.');
            }
            $ext = strtolower(pathinfo($lh['path'], PATHINFO_EXTENSION));
            header('Content-Type: ' . ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$ext]);
            header('Cache-Control: no-store');
            readfile($lh['path']);
            exit;

        case 'logo_file':
            $lg = Logo::info();
            if (!$lg) {
                http_response_code(404);
                exit('Kein Logo hinterlegt.');
            }
            header('Content-Type: ' . (str_ends_with($lg['file'], '.jpg') ? 'image/jpeg' : 'image/png'));
            header('Cache-Control: no-store');
            readfile($lg['path']);
            exit;

        case 'letterhead_preview':
            $bytes = Einvoice::pdf(Letterhead::sampleInvoice());
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="briefpapier-vorschau.pdf"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;

        case 'settings':
            $errors = [];
            if ($post) {
                $a = $_POST['action'] ?? 'save';
                if ($a === 'save') {
                    $in = fn(string $k): string => is_string($_POST[$k] ?? '') ? clean($_POST[$k] ?? '') : '';
                    foreach (['COMPANY_NAME', 'COMPANY_OWNER', 'COMPANY_STREET', 'COMPANY_CITY', 'COMPANY_EMAIL', 'COMPANY_BANK', 'SMTP_HOST', 'SMTP_USER', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_BCC', 'APP_URL'] as $k) {
                        if (mb_strlen($in($k)) > 190) {
                            $errors[] = $k . ' ist zu lang (höchstens 190 Zeichen).';
                        }
                    }
                    foreach (['COMPANY_PHONE' => 40, 'COMPANY_TAX_NUMBER' => 40, 'COMPANY_VAT_ID' => 30, 'COMPANY_ZIP' => 20, 'COMPANY_IBAN' => 42, 'COMPANY_BIC' => 11] as $k => $len) {
                        if (mb_strlen($in($k)) > $len) {
                            $errors[] = $k . ' ist zu lang (höchstens ' . $len . ' Zeichen).';
                        }
                    }
                    if ($in('SMTP_HOST') !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,253}$|^\[?[0-9A-Fa-f:]+\]?$/', $in('SMTP_HOST'))) {
                        $errors[] = 'SMTP_HOST: nur Hostname oder IP-Adresse, ohne Protokoll und Port.';
                    }
                    $port = $in('SMTP_PORT');
                    if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) {
                        $errors[] = 'SMTP_PORT muss zwischen 1 und 65535 liegen.';
                    }
                    $days = $in('PAYMENT_DAYS');
                    if ($days !== '' && (!ctype_digit($days) || (int)$days > 365)) {
                        $errors[] = 'Zahlungsziel: ganze Zahl von 0 bis 365 Tagen.';
                    }
                    if ($in('CANCEL_PREFIX') !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $in('CANCEL_PREFIX'))) {
                        $errors[] = 'Das Präfix für Stornorechnungen darf nur Buchstaben und Ziffern enthalten (höchstens 10 Zeichen).';
                    }
                    if ($in('INVOICE_PREFIX') !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $in('INVOICE_PREFIX'))) {
                        $errors[] = 'Rechnungspräfix: 1–10 Buchstaben/Ziffern.';
                    }
                    if (!preg_match('/^[A-Za-z]{2}$/', $in('COMPANY_COUNTRY') ?: 'DE')) {
                        $errors[] = 'Land: zweistelliger Ländercode.';
                    }
                    foreach (['COMPANY_NAME' => 'Firmenname', 'COMPANY_STREET' => 'Straße', 'COMPANY_ZIP' => 'PLZ', 'COMPANY_CITY' => 'Ort'] as $k => $label) {
                        if ($in($k) === '') {
                            $errors[] = $label . ' fehlt.';
                        }
                    }
                    foreach (['COMPANY_EMAIL', 'MAIL_FROM', 'MAIL_BCC'] as $k) {
                        if ($in($k) !== '' && !filter_var($in($k), FILTER_VALIDATE_EMAIL)) {
                            $errors[] = $k . ': ungültige E-Mail-Adresse.';
                        }
                    }
                    if ($in('COMPANY_IBAN') !== '' && !Config::ibanValid($in('COMPANY_IBAN'))) {
                        $errors[] = 'Die IBAN ist ungültig (Prüfsumme stimmt nicht).';
                    }
                    if ($in('APP_URL') !== '' && (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~%/\-]*)?$#', $in('APP_URL')))) {
                        $errors[] = 'Die App-Adresse muss mit http:// oder https:// beginnen.';
                    }
                    if (!$errors) {
                        $c = [];
                        foreach (['COMPANY_NAME', 'COMPANY_OWNER', 'COMPANY_STREET', 'COMPANY_ZIP', 'COMPANY_CITY', 'COMPANY_EMAIL', 'COMPANY_PHONE',
                                  'COMPANY_TAX_NUMBER', 'COMPANY_VAT_ID', 'COMPANY_IBAN', 'COMPANY_BIC', 'COMPANY_BANK',
                                  'SMTP_HOST', 'SMTP_USER', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_BCC', 'APP_URL'] as $k) {
                            $c[$k] = $in($k);
                        }
                        $c['COMPANY_COUNTRY'] = strtoupper($in('COMPANY_COUNTRY') ?: 'DE');
                        $c['INVOICE_PREFIX'] = preg_replace('/[^A-Za-z0-9]/', '', $in('INVOICE_PREFIX')) ?: 'RE';
                        $c['CANCEL_PREFIX'] = preg_replace('/[^A-Za-z0-9]/', '', $in('CANCEL_PREFIX')) ?: 'ST';
                        $c['PAYMENT_DAYS'] = (string)max(0, (int)($in('PAYMENT_DAYS') ?: 14));
                        $c['KLEINUNTERNEHMER'] = isset($_POST['KLEINUNTERNEHMER']) ? '1' : '0';
                        $c['GIROCODE'] = isset($_POST['GIROCODE']) ? '1' : '0';
                        $c['SMTP_PORT'] = (string)((int)$in('SMTP_PORT') ?: 587);
                        $c['SMTP_SECURE'] = in_array($in('SMTP_SECURE'), ['tls', 'ssl', 'none'], true) ? $in('SMTP_SECURE') : 'tls';
                        if ((string)($_POST['SMTP_PASS'] ?? '') !== '') {
                            $c['SMTP_PASS'] = (string)$_POST['SMTP_PASS'];
                        }
                        Config::save($c);
                        flash('Einstellungen gespeichert. Bereits ausgestellte Rechnungen bleiben unverändert.');
                        redirect(BASE . '?p=settings');
                    }
                } elseif ($a === 'account') {
                    $pwKey = 'pw:' . RateLimit::ip();
                    if (RateLimit::blocked($pwKey, LOGIN_MAX, LOGIN_WINDOW)) {
                        $errors[] = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
                    } elseif (!password_verify((string)($_POST['current_pass'] ?? ''), env('APP_PASSWORD_HASH'))) {
                        RateLimit::fail($pwKey, LOGIN_WINDOW);
                        $errors[] = 'Das aktuelle Passwort ist falsch.';
                    }
                    $newUser = clean($_POST['new_user'] ?? '');
                    $np = is_string($_POST['new_pass'] ?? '') ? (string)($_POST['new_pass'] ?? '') : '';
                    if (mb_strlen($newUser) > 64) {
                        $errors[] = 'Der Benutzername ist zu lang (höchstens 64 Zeichen).';
                    }
                    if ($np !== '' && strlen($np) < 10) {
                        $errors[] = 'Das neue Passwort braucht mindestens 10 Zeichen.';
                    }
                    if ($np !== ($_POST['new_pass2'] ?? '')) {
                        $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                    }
                    if ($newUser === '') {
                        $errors[] = 'Der Benutzername darf nicht leer sein.';
                    }
                    if (!$errors) {
                        $c = ['APP_USER' => $newUser];
                        if ($np !== '') {
                            $c['APP_PASSWORD_HASH'] = password_hash($np, PASSWORD_DEFAULT);
                        }
                        Config::save($c);
                        session_regenerate_id(true);
                        $_SESSION['fp'] = auth_fingerprint();   // nur diese Sitzung bleibt gültig, alle anderen werden beendet
                        flash($np !== '' ? 'Passwort geändert.' : 'Benutzername geändert.');
                        redirect(BASE . '?p=settings');
                    }
                } elseif ($a === 'letterhead') {
                    $c = [
                        'LETTERHEAD_MT' => (string)Letterhead::margin($_POST['mt'] ?? '', 45),
                        'LETTERHEAD_MB' => (string)Letterhead::margin($_POST['mb'] ?? '', 30),
                        'LETTERHEAD_ML' => (string)Letterhead::margin($_POST['ml'] ?? '', 25),
                        'LETTERHEAD_MR' => (string)Letterhead::margin($_POST['mr'] ?? '', 20),
                        'LETTERHEAD_HIDE_SENDER' => isset($_POST['hide_sender']) ? '1' : '0',
                        'LETTERHEAD_HIDE_FOOTER' => isset($_POST['hide_footer']) ? '1' : '0',
                    ];
                    $up = $_FILES['letterhead'] ?? null;
                    $uploaded = $up && (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                    if ($uploaded) {
                        $c['LETTERHEAD_FILE'] = Letterhead::store($up);
                    }
                    Config::save($c);
                    flash($uploaded ? 'Briefpapier hochgeladen und Einstellungen gespeichert. Gilt für neu ausgestellte Rechnungen.' : 'Briefpapier-Einstellungen gespeichert.');
                    redirect(BASE . '?p=settings');
                } elseif ($a === 'logo') {
                    $c = [
                        'LOGO_WIDTH' => (string)Logo::width($_POST['logo_width'] ?? '', 50),
                        'LOGO_ALIGN' => ($_POST['logo_align'] ?? '') === 'left' ? 'left' : 'right',
                    ];
                    $up = $_FILES['logo'] ?? null;
                    $uploaded = $up && (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                    if ($uploaded) {
                        $c['LOGO_FILE'] = Logo::store($up);
                    }
                    Config::save($c);
                    flash($uploaded ? 'Logo hochgeladen und Einstellungen gespeichert. Gilt für neu ausgestellte Rechnungen.' : 'Logo-Einstellungen gespeichert.');
                    redirect(BASE . '?p=settings');
                } elseif ($a === 'logo_delete') {
                    Logo::remove();
                    Config::save(['LOGO_FILE' => '']);
                    flash('Logo entfernt.');
                    redirect(BASE . '?p=settings');
                } elseif ($a === 'letterhead_delete') {
                    Letterhead::remove();
                    Config::save(['LETTERHEAD_FILE' => '']);
                    flash('Briefpapier entfernt.');
                    redirect(BASE . '?p=settings');
                } elseif ($a === 'reset') {
                    if (env('TEST_MODE') !== '1') {
                        throw new RuntimeException('Der Reset ist nur im Testbetrieb möglich (TEST_MODE in config.php).');
                    }
                    $pwKey = 'pw:' . RateLimit::ip();
                    if (RateLimit::blocked($pwKey, LOGIN_MAX, LOGIN_WINDOW)) {
                        $errors[] = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
                    } elseif (!is_string($_POST['reset_pass'] ?? '') || !password_verify((string)($_POST['reset_pass'] ?? ''), env('APP_PASSWORD_HASH'))) {
                        RateLimit::fail($pwKey, LOGIN_WINDOW);
                        $errors[] = 'Das Passwort ist falsch.';
                    }
                    if (trim((string)($_POST['confirm'] ?? '')) !== 'RESET') {
                        $errors[] = 'Zur Bestätigung bitte RESET eingeben.';
                    }
                    if (!$errors) {
                        $r = Invoices::reset(isset($_POST['keep_customers']));
                        flash('Zurückgesetzt: ' . $r['invoices'] . ' Rechnungen, ' . $r['customers'] . ' Kunden und ' . $r['files'] . ' PDFs gelöscht. Die Nummerierung beginnt wieder bei 1.');
                        redirect(BASE . '?p=settings');
                    }
                } elseif ($a === 'testmail') {
                    $to = trim((string)($_POST['to'] ?? '')) ?: env('COMPANY_EMAIL');
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Bitte eine gültige Empfängeradresse angeben.');
                    }
                    Mailer::sendTest($to);
                    flash('Testmail an ' . $to . ' gesendet.');
                    redirect(BASE . '?p=settings');
                }
            }
            render('settings', ['errors' => $errors, 'old' => $post ? $_POST : []]);
            break;

        default:
            render('invoices', ['rows' => Invoices::listAll()]);
    }
} catch (Throwable $e) {
    error_log((string)$e);
    flash('Fehler: ' . $e->getMessage());
    // Nur interne Pfade (safe_target() verwirft z. B. //evil.example oder Zeilenumbrüche)
    redirect($post ? safe_target($_SERVER['REQUEST_URI'] ?? '') : BASE);
}
