<?php
// public/settings/index.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/settings.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$err = null; $ok = null;

$set = get_account_settings($pdo, $account_id);

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Hilfsfunktion: PDF nach PNG (erste Seite) mit Imagick
 *
 * @param string $pdfPath
 * @param string $pngPath
 * @return bool
 */
function generate_letterhead_preview($pdfPath, $pngPath): bool {
  if (!class_exists('Imagick')) {
    return false;
  }
  try {
    $im = new Imagick();
    // Auflösung etwas höher, damit die Preview nicht matschig ist
    $im->setResolution(150, 150);
    // nur erste Seite
    $im->readImage($pdfPath . '[0]');
    $im->setImageBackgroundColor('white');
    $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $im->setImageFormat('png');
    $im->writeImage($pngPath);
    $im->clear();
    $im->destroy();
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Hilfsfunktion: existierende Datei aus Settings löschen
 *
 * @param string|null $urlPath  z.B. "/storage/layout/letterhead_first_1_1234.pdf"
 */
function delete_setting_file(?string $urlPath): void {
  if (!$urlPath) return;
  // Annahme: public/ ist Webroot, also "../.." zurück zum Projekt
  $fsPath = realpath(__DIR__ . '/../../storage/layout/' . basename($urlPath));
  if (is_file($fsPath)) {
    @unlink($fsPath);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Basis: vorhandene Settings übernehmen, damit wir vorhandene Pfade weiterreichen können
    $data = $_POST;

    // Upload-Verzeichnis vorbereiten
    $baseStorageDir = __DIR__ . '/../../storage';
    $layoutDir      = $baseStorageDir . '/layout';
    if (!is_dir($layoutDir)) {
      @mkdir($layoutDir, 0775, true);
    }

    // Vorhandene Pfade als Default übernehmen
    $data['invoice_letterhead_first_pdf']     = $set['invoice_letterhead_first_pdf']     ?? '';
    $data['invoice_letterhead_first_preview'] = $set['invoice_letterhead_first_preview'] ?? '';
    $data['invoice_letterhead_next_pdf']      = $set['invoice_letterhead_next_pdf']      ?? '';
    $data['invoice_letterhead_next_preview']  = $set['invoice_letterhead_next_preview']  ?? '';

    // -- Upload-Funktion inline, um Dopplung klein zu halten --
    $handleUpload = function(string $fieldName, string $labelBase, string $oldPdfKey, string $oldPngKey) use (&$data, $layoutDir, $account_id, $set) {
      if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return;
      }
      $file = $_FILES[$fieldName];

      if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '') {
        return;
      }

      // MIME prüfen
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime  = finfo_file($finfo, $file['tmp_name']);
      finfo_close($finfo);

      // simple Prüfung auf PDF
      if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
        // Wenn du willst, kannst du hier noch eine Fehlermeldung in $GLOBALS['err'] setzen
        return;
      }

      $timestamp = time();
      $baseName  = $labelBase . '_' . $account_id . '_' . $timestamp;

      $pdfFsPath = $layoutDir . '/' . $baseName . '.pdf';
      $pngFsPath = $layoutDir . '/' . $baseName . '.png';

      if (!move_uploaded_file($file['tmp_name'], $pdfFsPath)) {
        return;
      }

      // Preview generieren
      $previewOk = generate_letterhead_preview($pdfFsPath, $pngFsPath);

      // alte Dateien löschen
      if (!empty($set[$oldPdfKey])) {
        delete_setting_file($set[$oldPdfKey]);
      }
      if (!empty($set[$oldPngKey])) {
        delete_setting_file($set[$oldPngKey]);
      }

      // URL-Pfade in Settings speichern (relativ zum Webroot)
      $data[$oldPdfKey] = '/settings/file.php?path=' . rawurlencode('layout/' . $baseName . '.pdf');
      if ($previewOk) {
        $data[$oldPngKey] = '/settings/file.php?path=' . rawurlencode('layout/' . $baseName . '.png');
      } else {
        $data[$oldPngKey] = '';
      }
    };

    // Upload Briefbogen 1. Seite
    $handleUpload(
      'invoice_letterhead_first_pdf_upload',
      'letterhead_first',
      'invoice_letterhead_first_pdf',
      'invoice_letterhead_first_preview'
    );

    // Upload Briefbogen Folgeseiten
    $handleUpload(
      'invoice_letterhead_next_pdf_upload',
      'letterhead_next',
      'invoice_letterhead_next_pdf',
      'invoice_letterhead_next_preview'
    );

    // Jetzt alle Settings speichern
    save_account_settings($pdo, $account_id, $data);
    $ok = 'Einstellungen wurden gespeichert.';

    // $set für Anzeige aktualisieren
    $set = get_account_settings($pdo, $account_id);
  } catch (Throwable $e) {
    $err = 'Konnte Einstellungen nicht speichern.';
  }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Einstellungen</h3>
</div>

<?php if ($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
  <div class="card-body">
    <?= csrf_field() ?>

    <h5 class="mb-3">Rechnungen</h5>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Rechnungsnummern-Schema</label>
        <input type="text" class="form-control" name="invoice_number_pattern"
               value="<?= h($set['invoice_number_pattern']) ?>">
        <div class="form-text">
          Platzhalter: <code>{YYYY}</code>, <code>{YY}</code>, <code>{MM}</code>, <code>{DD}</code>, <code>{SEQ}</code> (fortlaufend).
          Beispiel: <code>RE-{YYYY}-{SEQ}</code>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Länge für {SEQ}</label>
        <input type="number" name="invoice_seq_pad" min="1" max="12"
              class="form-control"
              value="<?= h((int)($set['invoice_seq_pad'] ?? 5)) ?>">
        <div class="form-text">Anzahl Ziffern, mit führenden Nullen.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Nächste Sequenz</label>
        <input type="number" class="form-control" name="invoice_next_seq"
               value="<?= (int)$set['invoice_next_seq'] ?>" min="1">
      </div>
      <div class="col-md-3">
        <label class="form-label">Default-Fälligkeit (Tage)</label>
        <input type="number" class="form-control" name="default_due_days"
               value="<?= (int)$set['default_due_days'] ?>" min="0">
        <div class="form-text">0 = gleiches Datum wie Rechnungsdatum</div>
      </div>

      <?php
        // Zahl als JS-kompatible Dezimalzahl vorbereiten (Punkt statt Komma)
        $as_std_rate_js = '19.00';
        $as_scheme = $set['default_tax_scheme'] ?? 'standard';
      ?>
      <div class="col-md-3">
        <label class="form-label">Default-Steuerart</label>
        <select
          name="default_tax_scheme"
          id="as_tax_scheme"
          class="form-select"
          data-rate-standard="<?= $as_std_rate_js ?>"
          data-rate-tax-exempt="0.00"
          data-rate-reverse-charge="0.00"
        >
          <option value="standard"       <?= $as_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt"     <?= $as_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $as_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
        <div class="form-text">Beim Wechsel der Steuerart wird der Steuersatz unten automatisch vorbelegt.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Default-Steuersatz (%)</label>
        <input
          type="number"
          step="0.01"
          min="0"
          max="100"
          name="default_vat_rate"
          id="as_vat_rate"
          class="form-control"
          value="<?= isset($set['default_vat_rate']) ? h(number_format((float)$set['default_vat_rate'], 2, '.', '')) : '' ?>"
        >
        <div class="form-text">Wird durch die Steuerart vorbelegt, kann aber jederzeit überschrieben werden.</div>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var sel = document.getElementById('as_tax_scheme');
          var vat = document.getElementById('as_vat_rate');
          if (!sel || !vat) return;

          // Defaults aus den data-Attributen des Selects lesen
          var defaults = {
            standard:       sel.dataset.rateStandard       || '19.00',
            tax_exempt:     sel.dataset.rateTaxExempt      || '0.00',
            reverse_charge: sel.dataset.rateReverseCharge  || '0.00'
          };

          function applyByScheme() {
            var scheme = sel.value;
            // Steuerart führt → Feld aktiv mit Default belegen (User kann danach überschreiben)
            vat.value = (defaults[scheme] != null ? defaults[scheme] : defaults.standard);
          }

          // Beim Wechsel der Steuerart immer vorbelegen
          sel.addEventListener('change', applyByScheme);
          sel.addEventListener('input',  applyByScheme); // falls manche Browser 'input' statt 'change' feuern

          // Beim ersten Laden nur dann setzen, wenn das Feld leer ist (bestehende Werte nicht überschreiben)
          if (!vat.value) applyByScheme();
        });
      </script>

      <div class="col-md-3">
        <label class="form-label">Minuten-Rundung</label>
        <div class="input-group">
          <input
            type="number"
            name="invoice_round_minutes"
            min="0"
            max="60"
            step="1"
            class="form-control"
            value="<?= (int)($set['invoice_round_minutes'] ?? 0) ?>">
          <span class="input-group-text">Min</span>
        </div>
        <div class="form-text">
          0 = keine Rundung. &gt;0: Nach Summenbildung je Position auf N Minuten <em>aufrunden</em> (ceil).
          Typische Werte: 5, 6, 10, 15 …
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Standard-Einleitungstext</label>
        <textarea class="form-control" name="invoice_intro_text" rows="3"><?= h($set['invoice_intro_text'] ?? '') ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Standard-Schlussformel</label>
        <textarea class="form-control" name="invoice_outro_text" rows="3"><?= h($set['invoice_outro_text'] ?? '') ?></textarea>
      </div>

      <hr class="my-3">

      <h5 class="mb-3">Absender &amp; Bankverbindung</h5>

      <div class="col-md-6">
        <label class="form-label">Name / Firma (Absender)</label>
        <input
          type="text"
          class="form-control"
          name="sender_name"
          value="<?= h($set['sender_name'] ?? '') ?>"
          placeholder="Firma / Name"
        >
      </div>

      <div class="col-md-6">
        <label class="form-label">Straße und Hausnummer</label>
        <input
          type="text"
          class="form-control"
          name="sender_street"
          value="<?= h($set['sender_street'] ?? '') ?>"
          placeholder="Straße Hausnr."
        >
      </div>

      <div class="col-md-3">
        <label class="form-label">Postleitzahl</label>
        <input
          type="text"
          class="form-control"
          name="sender_postcode"
          value="<?= h($set['sender_postcode'] ?? '') ?>"
          placeholder="PLZ"
        >
      </div>

      <div class="col-md-5">
        <label class="form-label">Ort</label>
        <input
          type="text"
          class="form-control"
          name="sender_city"
          value="<?= h($set['sender_city'] ?? '') ?>"
          placeholder="Ort"
        >
      </div>

      <div class="col-md-2">
        <label class="form-label">Land (ISO)</label>
        <input
          type="text"
          class="form-control"
          name="sender_country"
          value="<?= h($set['sender_country'] ?? '') ?>"
          placeholder="DE"
        >
        <div class="form-text">2-stelliger ISO-Code, z.B. DE.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">USt-IdNr. (Absender)</label>
        <input
          type="text"
          class="form-control"
          name="sender_vat_id"
          value="<?= h($set['sender_vat_id'] ?? '') ?>"
          placeholder="z.B. DE123456789"
        >
        <div class="form-text">
          Wird im Factur-X-Export als Seller VAT ID (BT-31) verwendet.
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">IBAN</label>
        <input type="text" class="form-control" name="bank_iban" value="<?= h($set['bank_iban']) ?>"
               placeholder="DE..">
      </div>
      <div class="col-md-4">
        <label class="form-label">BIC</label>
        <input type="text" class="form-control" name="bank_bic" value="<?= h($set['bank_bic']) ?>"
               placeholder="MARKDEF...">
      </div>

      <hr class="my-3">

      <h5 class="mb-3">Rechnungslayout / Briefbogen</h5>

      <div class="col-md-6">
        <label class="form-label">Briefbogen 1. Seite (PDF)</label>
        <input
          type="file"
          class="form-control"
          name="invoice_letterhead_first_pdf_upload"
          accept="application/pdf"
        >
        <div class="form-text">
          PDF im A4-Format. Die erste Seite wird als Hintergrund für Seite 1 der Rechnung verwendet.
          Eine PNG-Vorschau wird automatisch erzeugt.
        </div>

        <?php if (!empty($set['invoice_letterhead_first_pdf'])): ?>
          <div class="mt-2">
            <div class="small text-muted">
              Aktueller Briefbogen:
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($set['invoice_letterhead_first_preview'])): ?>
          <div class="mt-2">
            <img
              src="<?= hurl(url($set['invoice_letterhead_first_preview'])) ?>"
              alt="Vorschau Briefbogen 1. Seite"
              class="img-fluid border"
            >
          </div>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <label class="form-label">Briefbogen Folgeseiten (PDF)</label>
        <input
          type="file"
          class="form-control"
          name="invoice_letterhead_next_pdf_upload"
          accept="application/pdf"
        >
        <div class="form-text">
          Wird als Hintergrund für Seite 2 ff. verwendet.
          Falls nicht gesetzt, kann später die erste Seite wiederverwendet werden.
        </div>

        <?php if (!empty($set['invoice_letterhead_next_pdf'])): ?>
          <div class="mt-2">
            <div class="small text-muted">
              Aktueller Briefbogen (Folgeseiten):
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($set['invoice_letterhead_next_preview'])): ?>
          <div class="mt-2">
            <img
              src="<?= hurl(url($set['invoice_letterhead_next_preview'])) ?>"
              alt="Vorschau Briefbogen Folgeseiten"
              class="img-fluid border"
            >
          </div>
        <?php endif; ?>
      </div>

      <a href="<?= hurl(url("/settings/invoice-layout.php")) ?>" target="_blank" rel="noopener">
        PDF-Bereiche definieren
      </a>

    </div>
  </div>

  <div class="card-footer d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?= hurl(url('/dashboard/index.php')) ?>">Abbrechen</a>
    <button class="btn btn-primary">Speichern</button>
  </div>
</form>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>