<?php
// public/settings/invoice-layout.php

require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/settings.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$err = null;
$ok  = null;

$set = get_account_settings($pdo, $account_id);

// Helper: Pfad aus Setting nach storage/ auflösen
function _resolve_storage_path_for_setting(string $val): string {
  $val = trim($val);
  if ($val === '') return '';

  // file.php?path=layout%2F...
  if (strpos($val, 'file.php') !== false) {
    $parts = parse_url($val);
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $q);
      if (!empty($q['path'])) {
        $rel = ltrim(urldecode($q['path']), '/');
        return realpath(__DIR__ . '/../../storage/' . $rel) ?: (__DIR__ . '/../../storage/' . $rel);
      }
    }
  }

  // Absolutpfad
  if ($val !== '' && ($val[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $val))) {
    return $val;
  }

  // Sonst unter storage/layout
  $fname = basename($val);
  return __DIR__ . '/../../storage/layout/' . $fname;
}

// Preview ggf. on-the-fly erzeugen, wenn PDF vorhanden, Preview aber fehlt
function _ensure_preview_for_pdf(string $pdfPath, string $targetPng): bool {
  $logDir = __DIR__ . '/../../storage/logs';
  if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
  $logLine = '['.date('c').'] invoice-layout preview pdf='.$pdfPath.' png='.$targetPng.' ';
  if (!class_exists('Imagick')) {
    @file_put_contents($logDir.'/letterhead_preview.log', $logLine.'Imagick fehlt'."\n", FILE_APPEND);
    return false;
  }
  try {
    $im = new Imagick();
    $im->setResolution(150, 150);
    $im->readImage($pdfPath.'[0]');
    $im->setImageBackgroundColor('white');
    $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $im->setImageFormat('png');
    $im->writeImage($targetPng);
    $im->clear();
    $im->destroy();
    @file_put_contents($logDir.'/letterhead_preview.log', $logLine.'OK size='.(@filesize($targetPng)?:0)."\n", FILE_APPEND);
    return true;
  } catch (Throwable $e) {
    @file_put_contents($logDir.'/letterhead_preview.log', $logLine.'Fehler: '.$e->getMessage()."\n", FILE_APPEND);
    return false;
  }
}

// Einfaches Logging für Fehlerfälle (z. B. wenn auf Live kein PHP-Errorlog einsehbar ist)
$__layout_log_dir = __DIR__ . '/../../storage/logs';
if (!is_dir($__layout_log_dir)) {
  @mkdir($__layout_log_dir, 0775, true);
}
$__layout_log_fn = $__layout_log_dir . '/invoice_layout.log';
function _layout_log(string $msg): void {
  global $__layout_log_fn, $account_id;
  $line = '['.date('c')."] acc_id={$account_id} :: ".$msg."\n";
  if ($__layout_log_fn) {
    if (@file_put_contents($__layout_log_fn, $line, FILE_APPEND) === false) {
      error_log($line);
    }
  } else {
    error_log($line);
  }
}

// Layout aus Settings holen
$layoutJson = $set['invoice_layout_zones'] ?? '';
$layoutData = [];

if ($layoutJson) {
  $decoded = json_decode($layoutJson, true);
  if (is_array($decoded)) {
    $layoutData = $decoded;
  }
}

// Fallback-Struktur
if (empty($layoutData)) {
  $layoutData = [
    'page_size' => 'A4',
    'units'     => 'percent',
    'zones'     => []
  ];
}

$zonesData = isset($layoutData['zones']) && is_array($layoutData['zones'])
  ? $layoutData['zones']
  : [];

// Briefbogen-Previews/PDF
$previewFirst = $set['invoice_letterhead_first_preview'] ?? '';
$previewNext  = $set['invoice_letterhead_next_preview'] ?? '';
$pdfFirst     = $set['invoice_letterhead_first_pdf'] ?? '';
$pdfNext      = $set['invoice_letterhead_next_pdf'] ?? '';

// Falls PDF vorhanden, aber Preview fehlt: versuchen, jetzt zu generieren
if ($pdfFirst && !$previewFirst) {
  $pdfPath = _resolve_storage_path_for_setting($pdfFirst);
  $pngName = pathinfo($pdfPath, PATHINFO_FILENAME).'.png';
  $pngPath = dirname($pdfPath) . '/' . $pngName;
  if (is_readable($pdfPath) && _ensure_preview_for_pdf($pdfPath, $pngPath)) {
    // Versuche relativen Pfad ab storage/ zu konstruieren
    $rel = str_replace(realpath(__DIR__.'/../../storage/').'/', '', realpath($pngPath));
    $previewFirst = '/settings/file.php?path=' . rawurlencode($rel);
    $set['invoice_letterhead_first_preview'] = $previewFirst;
    save_account_settings($pdo, $account_id, ['invoice_letterhead_first_preview' => $previewFirst]);
  }
}

if ($pdfNext && !$previewNext) {
  $pdfPath = _resolve_storage_path_for_setting($pdfNext);
  $pngName = pathinfo($pdfPath, PATHINFO_FILENAME).'.png';
  $pngPath = dirname($pdfPath) . '/' . $pngName;
  if (is_readable($pdfPath) && _ensure_preview_for_pdf($pdfPath, $pngPath)) {
    $rel = str_replace(realpath(__DIR__.'/../../storage/').'/', '', realpath($pngPath));
    $previewNext = '/settings/file.php?path=' . rawurlencode($rel);
    $set['invoice_letterhead_next_preview'] = $previewNext;
    save_account_settings($pdo, $account_id, ['invoice_letterhead_next_preview' => $previewNext]);
  }
}

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $json = $_POST['layout_json'] ?? '';

    if ($json !== '') {
      $decoded = json_decode($json, true);
      if (!is_array($decoded)) {
        throw new RuntimeException('Layout-JSON ist ungültig.');
      }

      $json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
      $data = ['invoice_layout_zones' => $json];
      _layout_log('Speichere invoice_layout_zones, Länge=' . strlen($json));
      save_account_settings($pdo, $account_id, $data);

      $ok = 'Rechnungslayout wurde gespeichert.';

      $set = get_account_settings($pdo, $account_id);
      $layoutJson = $set['invoice_layout_zones'] ?? '';
      $layoutData = json_decode($layoutJson, true) ?: $layoutData;
      $zonesData  = isset($layoutData['zones']) && is_array($layoutData['zones'])
        ? $layoutData['zones']
        : [];
    } else {
      $err = 'Es wurden keine Layout-Daten übertragen.';
      _layout_log('POST ohne layout_json');
    }
  } catch (Throwable $e) {
    $err = 'Konnte Rechnungslayout nicht speichern. ' . $e->getMessage();
    _layout_log('Fehler: ' . $e->getMessage());
  }
}

$layoutJsonForJs = json_encode($layoutData, JSON_UNESCAPED_UNICODE);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnungslayout / Briefbogen</h3>
  <a class="btn btn-outline-secondary btn-sm" href="<?= hurl(url('/settings/index.php')) ?>">
    &laquo; Zurück zu den Einstellungen
  </a>
</div>

<?php if ($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

<?php if (!$previewFirst && !$pdfFirst): ?>
  <div class="alert alert-warning">
    Es ist noch kein Briefbogen für die erste Seite hinterlegt.
    Bitte lade zuerst unter <a href="<?= hurl(url('/settings/index.php')) ?>">Einstellungen &raquo; Rechnungen</a>
    einen PDF-Briefbogen hoch. Die PNG-Vorschau wird dann automatisch erzeugt.
  </div>
<?php elseif ($pdfFirst && !$previewFirst): ?>
  <div class="alert alert-warning">
    Briefbogen-PDF ist vorhanden, aber keine Vorschau wurde erzeugt (evtl. fehlt Imagick). Die Layout-Zonen können ohne Vorschau nicht bearbeitet werden.
    Datei: <a href="<?= hurl(url($pdfFirst)) ?>" target="_blank" rel="noopener">öffnen</a>
  </div>
<?php else: ?>
  <form method="post" class="card" id="layout-form">
    <div class="card-body">
      <?= csrf_field() ?>

      <p class="text-muted">
        Ziehe und skaliere die farbigen Bereiche auf dem Briefbogen,
        um festzulegen, wo Adresse, Rechnungsinformationen, Positionen platziert werden sollen.
        Für Folgeseiten kannst du ein separates Layout definieren.
      </p>

      <input type="hidden" name="layout_json" id="layout_json" value="">

      <style>
        .layout-canvas-wrapper {
          max-width: 900px;
        }
        .layout-canvas {
          position: relative;
          width: 100%;
          padding-top: 141.4%; /* A4 */
          border: 1px solid #ccc;
          background: #fff;
          overflow: hidden;
        }
        .layout-canvas-inner {
          position: absolute;
          inset: 0;
        }
        .layout-canvas-inner img {
          width: 100%;
          height: 100%;
          object-fit: contain;
          display: block;
        }
        .layout-zone {
          position: absolute;
          border: 2px dashed #0d6efd;
          background: rgba(13, 110, 253, 0.08);
          color: #0d6efd;
          font-size: 0.8rem;
          box-sizing: border-box;
          cursor: move;
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          padding: 3px 6px;
        }
        .layout-zone-header {
          pointer-events: none;
        }
        .layout-zone-handle {
          width: 12px;
          height: 12px;
          border-radius: 2px;
          border: 1px solid #0d6efd;
          background: rgba(13, 110, 253, 0.4);
          cursor: se-resize;
          align-self: flex-end;
          margin-left: 4px;
          flex-shrink: 0;
        }
      </style>

      <?php if ($previewNext): ?>
        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-page1-btn" data-bs-toggle="tab" data-bs-target="#tab-page1" type="button" role="tab">
              Seite 1
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-page2-btn" data-bs-toggle="tab" data-bs-target="#tab-page2" type="button" role="tab">
              Folgeseiten
            </button>
          </li>
        </ul>
      <?php endif; ?>

      <div class="layout-canvas-wrapper <?= $previewNext ? 'tab-content' : '' ?>">

        <!-- Seite 1 -->
        <div class="<?= $previewNext ? 'tab-pane fade show active' : '' ?>" id="tab-page1" role="tabpanel">
          <div class="layout-canvas" id="layout-canvas-1">
            <div class="layout-canvas-inner">
              <img src="<?= hurl(url($previewFirst)) ?>" alt="Briefbogen Vorschau Seite 1">
              <!-- Zonen per JS -->
            </div>
          </div>
          <div class="mt-2 small text-muted">
            Layout für die erste Seite (Adresse, Rechnungsinfo, Positionen).
          </div>
        </div>

        <!-- Folgeseiten -->
        <?php if ($previewNext): ?>
          <div class="tab-pane fade" id="tab-page2" role="tabpanel">
            <div class="layout-canvas" id="layout-canvas-2">
              <div class="layout-canvas-inner">
                <img src="<?= hurl(url($previewNext)) ?>" alt="Briefbogen Vorschau Folgeseiten">
                <!-- Zonen per JS -->
              </div>
            </div>
            <div class="mt-2 small text-muted">
              Layout für Folgeseiten (Positionen).
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="mt-3 small text-muted">
        Hinweis: Die Positionen werden relativ zur Seitengröße gespeichert (in Prozent).
        Auf dem echten PDF werden sie auf A4 (210×297mm) umgerechnet.
      </div>

    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <a class="btn btn-outline-secondary" href="<?= hurl(url('/settings/index.php')) ?>">Abbrechen</a>
      <button type="button" class="btn btn-primary" id="btn-save-layout">Layout speichern</button>
    </div>
  </form>

  <script>
    (function() {
      var layoutData = <?= htmlspecialchars($layoutJsonForJs, ENT_NOQUOTES, 'UTF-8') ?> || {};
      if (typeof layoutData !== 'object' || layoutData === null) {
        layoutData = { page_size: 'A4', units: 'percent', zones: {} };
      }
      var zonesData = layoutData.zones || {};

      var canvas1Inner = document.querySelector('#layout-canvas-1 .layout-canvas-inner');
      var canvas2Inner = document.querySelector('#layout-canvas-2 .layout-canvas-inner');
      var hasPage2     = !!canvas2Inner;

      var saveBtn     = document.getElementById('btn-save-layout');
      var layoutInput = document.getElementById('layout_json');
      var form        = document.getElementById('layout-form');

      if (!canvas1Inner || !saveBtn || !layoutInput || !form) {
        return;
      }

      // Felder Seite 1
      var FIELDS_PAGE1 = [
        { name: 'addressee',      label: 'Adresse',       page: 1 },
        { name: 'invoice_info', label: 'Rechnungsinfo', page: 1 },
        { name: 'main_area',        label: 'Positionen',    page: 1 },
      ];

      // Felder Seite 2
      var FIELDS_PAGE2 = [
        { name: 'main_area_page_2',       label: 'Positionen (Folgeseiten)', page: 2 },
      ];

      // Default-Layout Seite 1 (in %)
      var defaultZonesPage1 = {
        addressee:      { page: 1, x: 10, y: 25, w: 40, h: 20 },
        invoice_info: { page: 1, x: 60, y: 25, w: 30, h: 20 },
        main_area:        { page: 1, x: 10, y: 55, w: 80, h: 35 },
      };

      // Default-Layout Seite 2 (in %)
      var defaultZonesPage2 = {
        main_area_page_2:       { page: 2, x: 10, y: 20, w: 80, h: 60 },
      };

      function getZoneConfig(fieldName, defaults) {
        var saved = zonesData[fieldName];
        var def   = defaults[fieldName];

        if (!def) {
          def = { page: 1, x: 10, y: 10, w: 20, h: 10 };
        }

        if (saved && typeof saved === 'object') {
          return {
            page: saved.page || def.page,
            x: (typeof saved.x === 'number') ? saved.x : def.x,
            y: (typeof saved.y === 'number') ? saved.y : def.y,
            w: (typeof saved.w === 'number') ? saved.w : def.w,
            h: (typeof saved.h === 'number') ? saved.h : def.h
          };
        }

        return def;
      }

      function createZone(containerInner, fieldName, label, zoneConfig) {
        if (!containerInner || !zoneConfig) return;

        var pageNum = zoneConfig.page || 1;

        var zoneEl = document.createElement('div');
        zoneEl.className = 'layout-zone';
        zoneEl.dataset.field = fieldName;
        zoneEl.dataset.page  = String(pageNum);

        var header = document.createElement('div');
        header.className = 'layout-zone-header';
        header.textContent = label;

        var handle = document.createElement('div');
        handle.className = 'layout-zone-handle';

        zoneEl.appendChild(header);
        zoneEl.appendChild(handle);
        containerInner.appendChild(zoneEl);

        function applyFromPercent() {
          var rect = containerInner.getBoundingClientRect();
          if (!rect.width || !rect.height) return;

          var x = zoneConfig.x;
          var y = zoneConfig.y;
          var w = zoneConfig.w;
          var h = zoneConfig.h;

          zoneEl.style.left   = (x / 100 * rect.width)  + 'px';
          zoneEl.style.top    = (y / 100 * rect.height) + 'px';
          zoneEl.style.width  = (w / 100 * rect.width)  + 'px';
          zoneEl.style.height = (h / 100 * rect.height) + 'px';
        }

        applyFromPercent();

        // Drag
        (function enableDrag() {
          var isDragging = false;
          var startX = 0, startY = 0;
          var origLeft = 0, origTop = 0;

          zoneEl.addEventListener('mousedown', function(e) {
            if (e.target === handle) return;
            e.preventDefault();
            isDragging = true;

            var rect = zoneEl.getBoundingClientRect();
            var canvasRect = containerInner.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            origLeft = rect.left - canvasRect.left;
            origTop  = rect.top  - canvasRect.top;

            function onMove(ev) {
              if (!isDragging) return;
              var canvasRect = containerInner.getBoundingClientRect();
              var dx = ev.clientX - startX;
              var dy = ev.clientY - startY;
              var newLeft = origLeft + dx;
              var newTop  = origTop  + dy;

              var rect = zoneEl.getBoundingClientRect();
              var width = rect.width;
              var height = rect.height;

              if (newLeft < 0) newLeft = 0;
              if (newTop < 0) newTop = 0;
              if (newLeft + width > canvasRect.width) {
                newLeft = canvasRect.width - width;
              }
              if (newTop + height > canvasRect.height) {
                newTop = canvasRect.height - height;
              }

              zoneEl.style.left = newLeft + 'px';
              zoneEl.style.top  = newTop  + 'px';
            }

            function onUp() {
              isDragging = false;
              document.removeEventListener('mousemove', onMove);
              document.removeEventListener('mouseup', onUp);
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
          });
        })();

        // Resize
        (function enableResize() {
          var isResizing = false;
          var startX = 0, startY = 0;
          var origWidth = 0, origHeight = 0;

          handle.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            isResizing = true;

            var rect = zoneEl.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            origWidth  = rect.width;
            origHeight = rect.height;

            function onResize(ev) {
              if (!isResizing) return;
              var canvasRect = containerInner.getBoundingClientRect();
              var dx = ev.clientX - startX;
              var dy = ev.clientY - startY;

              var newWidth  = origWidth  + dx;
              var newHeight = origHeight + dy;

              if (newWidth < 30) newWidth = 30;
              if (newHeight < 20) newHeight = 20;

              var zoneRect = zoneEl.getBoundingClientRect();
              var left = zoneRect.left - canvasRect.left;
              var top  = zoneRect.top  - canvasRect.top;

              if (left + newWidth > canvasRect.width) {
                newWidth = canvasRect.width - left;
              }
              if (top + newHeight > canvasRect.height) {
                newHeight = canvasRect.height - top;
              }

              zoneEl.style.width  = newWidth + 'px';
              zoneEl.style.height = newHeight + 'px';
            }

            function onResizeUp() {
              isResizing = false;
              document.removeEventListener('mousemove', onResize);
              document.removeEventListener('mouseup', onResizeUp);
            }

            document.addEventListener('mousemove', onResize);
            document.addEventListener('mouseup', onResizeUp);
          });
        })();
      }

      // --- Init Seite 1 direkt (sichtbar) ---
      FIELDS_PAGE1.forEach(function(f) {
        var cfg = getZoneConfig(f.name, defaultZonesPage1);
        createZone(canvas1Inner, f.name, f.label, cfg);
      });

      // --- Seite 2 nur initialisieren, wenn Tab das erste Mal geklickt wird ---
      var page2Initialized = false;
      function initPage2() {
        if (page2Initialized || !hasPage2) return;
        page2Initialized = true;
        FIELDS_PAGE2.forEach(function(f) {
          var cfg = getZoneConfig(f.name, defaultZonesPage2);
          createZone(canvas2Inner, f.name, f.label, cfg);
        });
      }

      if (hasPage2) {
        var btn2 = document.getElementById('tab-page2-btn');
        if (btn2) {
          btn2.addEventListener('click', function() {
            // Canvas ist sichtbar, wenn Tab gewechselt wurde
            setTimeout(initPage2, 0);
          });
        }
      }

      // --- Speichern: Pixel → Prozent ---
      function collectLayout() {
        var resultZones = {};
        // vorhandene Zonen übernehmen
        for (var key in zonesData) {
          if (Object.prototype.hasOwnProperty.call(zonesData, key)) {
            resultZones[key] = zonesData[key];
          }
        }

        function collectFromCanvas(inner, fields, pageNum) {
          if (!inner) return;
          var canvasRect = inner.getBoundingClientRect();
          if (!canvasRect.width || !canvasRect.height) return;

          fields.forEach(function(f) {
            var zoneEl = inner.querySelector('.layout-zone[data-field="'+f.name+'"]');
            if (!zoneEl) return;

            var r = zoneEl.getBoundingClientRect();
            var left   = r.left - canvasRect.left;
            var top    = r.top  - canvasRect.top;
            var width  = r.width;
            var height = r.height;

            var xPercent = (left   / canvasRect.width)  * 100;
            var yPercent = (top    / canvasRect.height) * 100;
            var wPercent = (width  / canvasRect.width)  * 100;
            var hPercent = (height / canvasRect.height) * 100;

            resultZones[f.name] = {
              page: pageNum,
              x: xPercent,
              y: yPercent,
              w: wPercent,
              h: hPercent,
              hAlign: "left",
              fontSizePt: 10,
              lineSpacing: 1.2
            };
          });
        }

        collectFromCanvas(canvas1Inner, FIELDS_PAGE1, 1);
        if (page2Initialized) {
          collectFromCanvas(canvas2Inner, FIELDS_PAGE2, 2);
        }

        return {
          page_size: layoutData.page_size || 'A4',
          units: layoutData.units || 'percent',
          zones: resultZones
        };
      }

      saveBtn.addEventListener('click', function() {
        var layout = collectLayout();
        layoutInput.value = JSON.stringify(layout);
        form.submit();
      });
    })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
