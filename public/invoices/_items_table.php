<?php
/**
 * Reusable Items-Table for invoices (new & edit)
 *
 * NEW expects: $groups  (siehe Build-Struktur: project_id, project_title, rows[] mit time-entries)
 * EDIT expects: $items  (inkl. time_entries[] und project_title)
 */
[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, $company ?? null);

$eff_scheme   = $eff_scheme   ?? 'standard';
$eff_vat_js = number_format((float)$eff_vat, 2, '.', '');
$eff_vat_num  = (float)str_replace(',', '.', (string)$eff_vat_js);


// --- Kompatibilitäts-Header: nur Namensvariablen, keine Optik-Änderung ---
$mode      = $mode      ?? 'new';
$rowName   = $rowName   ?? ($mode === 'edit' ? 'items' : 'tasks');
$timesName = $timesName ?? ($mode === 'edit' ? 'times_selected' : 'time_ids');
$scheme_default = $eff_scheme; // aus invoices/new via require
$rate_default   = $eff_scheme==='standard' ? number_format((float)$eff_vat,2,'.','') : '0.00';




// Für die bestehenden name="-Attribute" im Markup:
$NAME_TASKS = $rowName;
$NAME_TIMES = $timesName;

function _fmt_hhmm($min){ $h=intdiv($min,60); $r=$min%60; return sprintf('%02d:%02d',$h,$r); }
?>
<style>
  .inv-group-head td{ background:#f7f7f9; font-weight:600; }
  .inv-item-row td { vertical-align: middle; }
  .inv-details { display:none; background:#fcfcfd; }
  .inv-details td { border-top:0; }

  /* Neues, hübscheres Toggle */
  .inv-toggle-btn{
    border:0; background:transparent; width:28px; height:28px;
    display:grid; place-items:center; border-radius:50%;
  }
  .inv-toggle-btn:hover{ background:#eef2f7; }
  .chev{ width:16px; height:16px; transition:transform .2s ease; }
  /* Pfeil dreht bei "aufgeklappt" */
  .inv-item-row[aria-expanded="true"] .chev{ transform: rotate(90deg); }
</style>

<!-- Hidden container für gelöschte Item-IDs (nur Edit) -->
<div id="invoice-hidden-trash"></div>

<div id="invoice-items">
  <table class="table align-middle">
    <thead>
      <tr>
        <th style="width:36px"></th>
        <th>Aufgabe</th>
        <th class="text-end w-110">Zeit</th>
        <th class="text-end w-90">Stundensatz</th>
        <th class="text-end w-90">Steuerart</th>
        <th class="text-end w-90">Steuersatz</th>
        <th class="text-end w-110">Netto</th>
        <th class="text-end w-110">Brutto</th>
        <th class="text-end" style="width:120px">Aktionen</th>
      </tr>
    </thead>
    <tbody>
<?php
$idx = 0;

/* -------- NEW mode: build from $groups -------- */
if (!empty($groups)) {
  $showProjectHeads = count($groups) > 1;
  foreach ($groups as $g) {
    $pid = (int)$g['project_id'];
    if ($showProjectHeads): ?>
      <tr class="inv-group-head" data-project="<?=$pid?>"><td colspan="9"><?=h($g['project_title'])?></td></tr>
    <?php endif;

    foreach ($g['rows'] as $row) {
      $idx++;
      $desc    = $row['task_desc'];
      $rateNum = (float)($row['hourly_rate'] ??  0.0); // <- Stundensatz
      $rate    = number_format((float)$rateNum, 2, ',', '');
      $taxNum  = (float)($row['tax_rate'] ?? 0.0);     // <- Prozent
      $sumMin  = array_sum(array_map(fn($t)=> (int)$t['minutes'], $row['times']));
      $sumHHM  = _fmt_hhmm($sumMin);
      $net     = ($sumMin/60.0) * $rateNum;
      $gross   = $net * (1 + $taxNum/100);
      ?>
      <tr class="inv-item-row" data-row="<?=$idx?>" data-project="<?=$pid?>" aria-expanded="false">
        <td class="text-center">
            <button type="button" class="inv-toggle-btn" aria-label="Details ein-/ausklappen">
              <svg class="chev" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
        </td>
        <td>
          <input type="hidden" name="items[<?=$idx?>][project_id]" value="<?=$pid?>">
          <input type="hidden" name="items[<?=$idx?>][task_id]" value="<?= (int)$row['task_id'] ?>">
          <input type="text" class="form-control" name="items[<?=$idx?>][description]" value="<?=h($desc)?>">
        </td>
        <td class="text-end">
          <span class="sum-hhmm"><?=$sumHHM?></span>
          <input type="hidden" name="items[<?=$idx?>][sum_minutes]" value="<?=$sumMin?>" class="sum-minutes">
        </td>
        <td class="text-end">
          <input type="number" step="0.01" class="form-control text-end rate" name="items[<?=$idx?>][hourly_rate]" value="<?=$rate?>">
        </td>

        <td class="text-end">
          <select
            name="items[<?=$idx?>][tax_scheme]"
            class="form-select inv-tax-sel"
            data-rate-standard="19.00"
            data-rate-tax-exempt="0.00"
            data-rate-reverse-charge="0.00">
            <option value="standard"       <?= $scheme_default==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
            <option value="tax_exempt"     <?= $scheme_default==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
            <option value="reverse_charge" <?= $scheme_default==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
          </select>
        </td>
        <td class="text-end">
          <input
            type="number" step="0.01" min="0" max="100"
            class="form-control text-end inv-vat-input"
            name="items[<?=$idx?>][vat_rate]"
            value="<?= h($rate_default) ?>">
        </td>
        <td class="text-end"><span class="net"><?=number_format($net,2,',','.')?></span></td>
        <td class="text-end"><span class="gross"><?=number_format($gross,2,',','.')?></span></td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
        </td>
      </tr>
      <tr class="inv-details" data-row="<?=$idx?>" data-project="<?=$pid?>">
        <td></td>
        <td colspan="8">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th style="width:42px"></th>
                  <th>Zeitraum</th>
                  <th class="text-end">HH:MM</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($row['times'] as $t): $tid=(int)$t['id']; $m=(int)$t['minutes']; ?>
                  <tr>
                    <td>
                      <input type="checkbox"
                             class="form-check-input time-checkbox"
                             name="<?=$NAME_TIMES?>[<?= (int)$row['task_id'] ?>][]"
                             value="<?= (int)$t['id'] ?>"
                             data-min="<?= (int)$t['minutes'] ?>"
                             checked>
                    </td>
                    <td><?=h($t['started_at'])?> – <?=h($t['ended_at'])?></td>
                    <td class="text-end"><?=_fmt_hhmm($m)?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </td>
      </tr>
      <?php
    }
  }
}

/* -------- EDIT mode: build from $items -------- */
if (empty($groups) && !empty($items)) {
  $byP = [];
  foreach ($items as $it) {
    $pid = (int)($it['project_id'] ?? 0);
    if (!isset($byP[$pid])) $byP[$pid] = ['title'=>$it['project_title'] ?? '', 'rows'=>[]];
    $byP[$pid]['rows'][] = $it;
  }
  $showHeads = count($byP) > 1;

  foreach ($byP as $pid=>$bucket) {
    if ($showHeads): ?>
      <tr class="inv-group-head" data-project="<?=$pid?>"><td colspan="9"><?=h($bucket['title'])?></td></tr>
    <?php endif;

    foreach ($bucket['rows'] as $it) {
      $idx++;
      $desc    = $it['description'] ?? '';
      $rateNum = (float)($it['hourly_rate'] ?? 0.0);
      $rate    = number_format($rateNum, 2, ',', '');
      $taxNum  = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0.0);
      $tax     = number_format($taxNum, 2, ',', '');

      $times   = $it['time_entries'] ?? [];
      $sumMin  = 0;
      foreach ($times as $te) if (!empty($te['selected'])) $sumMin += (int)$te['minutes'];
      $sumHHM  = _fmt_hhmm($sumMin);

      // ❗ Preferiere DB-Summen, fallback auf Minuten-basierte Rechnung
      $netVal   = array_key_exists('total_net',   $it) ? (float)$it['total_net']   : (($sumMin/60.0) * $rateNum);
      $grossVal = array_key_exists('total_gross', $it) ? (float)$it['total_gross'] : ($netVal * (1 + $taxNum/100));

      // Flag: hat diese Zeile überhaupt Times?
      $hasTimes = !empty($times);

      // Für JS: fixe Totals (ohne Times) als data-Attribute bereitstellen
      $dataAttrs = '';
      if (!$hasTimes) {
        $dataAttrs =
          ' data-fixed-total="1"'.
          ' data-total-net="'.htmlspecialchars(number_format($netVal,   2, '.', ''), ENT_QUOTES).'"'.
          ' data-total-gross="'.htmlspecialchars(number_format($grossVal, 2, '.', ''), ENT_QUOTES).'"';
      }

      $it_scheme = $it['tax_scheme']
        ?? (((float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0)) > 0 ? 'standard' : 'tax_exempt');
      $it_vat    = number_format((float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0), 2, '.', '');

      ?>
      <tr class="inv-item-row" data-row="<?=$idx?>" data-project="<?=$pid?>" aria-expanded="false"<?=$dataAttrs?>>
          <td class="text-center">
            <button type="button" class="inv-toggle-btn" aria-label="Details ein-/ausklappen">
              <svg class="chev" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </td>
          <td>
            <input type="hidden" name="items[<?=$idx?>][id]" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="items[<?=$idx?>][project_id]" value="<?=$pid?>">
            <input type="text" class="form-control" name="items[<?=$idx?>][description]" value="<?=h($desc)?>">
          </td>
          <td class="text-end">
            <span class="sum-hhmm"><?=$sumHHM?></span>
            <input type="hidden" name="items[<?=$idx?>][sum_minutes]" value="<?=$sumMin?>" class="sum-minutes">
          </td>
          <td class="text-end">
            <input type="number" step="0.01" class="form-control text-end rate" name="items[<?=$idx?>][hourly_rate]" value="<?=$rate?>">
          </td>

          <td class="text-end">
            <select
              name="items[<?=$idx?>][tax_scheme]"
              class="form-select inv-tax-sel"
              data-rate-standard="19.00"
              data-rate-tax-exempt="0.00"
              data-rate-reverse-charge="0.00">
              <option value="standard"       <?= $it_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
              <option value="tax_exempt"     <?= $it_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
              <option value="reverse_charge" <?= $it_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
            </select>
          </td>
          <td class="text-end">
            <input
              type="number" step="0.01" min="0" max="100"
              class="form-control text-end inv-vat-input"
              name="items[<?=$idx?>][vat_rate]"
              value="<?= h($it_vat) ?>">
          </td>

          <!-- Anzeige mit DB-Summen -->
          <td class="text-end"><span class="net"><?= number_format($netVal,   2, ',', '.') ?></span></td>
          <td class="text-end"><span class="gross"><?= number_format($grossVal, 2, ',', '.') ?></span></td>

          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
          </td>
        </tr>
      <tr class="inv-details" data-row="<?=$idx?>" data-project="<?=$pid?>">
        <td></td>
        <td colspan="8">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th style="width:42px"></th>
                  <th>Zeitraum</th>
                  <th class="text-end">HH:MM</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($times as $te): $tid=(int)$te['id']; $m=(int)$te['minutes']; ?>
                  <tr>
                    <td>
                      <input class="form-check-input time-checkbox"
                             type="checkbox"
                             name="items[<?=$idx?>][time_ids][]"
                             value="<?=$tid?>"
                             <?=!empty($te['selected'])?'checked':''?>
                             data-min="<?=$m?>">
                    </td>
                    <td><?=h($te['started_at'] ?? '')?> <?= isset($te['ended_at']) ? '– '.h($te['ended_at']) : '' ?></td>
                    <td class="text-end"><?=_fmt_hhmm($m)?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </td>
      </tr>
    <?php
    }
  }
}
?>
    </tbody>
  </table>
</div>
<script>
(function(){
  function toNumber(x){
    var n = (typeof x==='string') ? x.replace(/\./g,'').replace(',', '.') : x;
    n = parseFloat(n);
    return isFinite(n) ? n : 0;
  }
  function fmt2(n){ return n.toFixed(2).replace('.',','); }
  function hhmm(min){
    min = Math.max(0, parseInt(min||0,10));
    var h = Math.floor(min/60), m = min%60;
    return (h<10?'0':'')+h+':' + (m<10?'0':'')+m;
  }

  function recalcRow(tr){
    // Fixe Totals aus dem Server verwenden, wenn die Zeile KEINE Times besitzt
    if (tr.dataset.fixedTotal === '1') {
      var nspan = tr.querySelector('.net');
      var gspan = tr.querySelector('.gross');
      var n = parseFloat(tr.dataset.totalNet   || '0');
      var g = parseFloat(tr.dataset.totalGross || '0');
      if (nspan) nspan.textContent = fmt2(isFinite(n) ? n : 0);
      if (gspan) gspan.textContent = fmt2(isFinite(g) ? g : 0);
      return; // NICHT minutenbasiert überschreiben
    }

    // Ansonsten: minutenbasiert wie gehabt
    var minutes = 0;
    var rowId   = tr.getAttribute('data-row');
    var details = document.querySelector('.inv-details[data-row="'+rowId+'"]');
    if (details) {
      details.querySelectorAll('.time-checkbox:checked').forEach(function(cb){
        minutes += parseInt(cb.getAttribute('data-min')||'0',10);
      });
    } else {
      var sm = tr.querySelector('.sum-minutes');
      minutes = sm ? parseInt(sm.value||'0',10) : 0;
    }

    var rate = toNumber(tr.querySelector('.rate')?.value||'0');
    var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');

    var net   = (minutes/60.0) * rate;
    var gross = net * (1 + vat/100);

    var hh = tr.querySelector('.sum-hhmm'); if (hh) hh.textContent = hhmm(minutes);
    var smi= tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
    var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
    var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
  }

  function updateVatFromScheme(sel){
    var tr  = sel.closest('tr.inv-item-row');
    if (!tr) return;
    var vat = tr.querySelector('.inv-vat-input');
    if (!vat) return;
    var map = {
      'standard':       sel.dataset.rateStandard || '19.00',
      'tax_exempt':     sel.dataset.rateTaxExempt || '0.00',
      'reverse_charge': sel.dataset.rateReverseCharge || '0.00'
    };
    vat.value = map[sel.value] || '0.00';
    recalcRow(tr);
  }

  function toggleTaxReason(){
    var wrap = document.getElementById('tax-exemption-reason-wrap');
    if (!wrap) return; // Feld existiert ggf. (noch) nicht
    var any = false;
    document.querySelectorAll('.inv-tax-sel').forEach(function(sel){
      if (sel.value !== 'standard') any = true;
    });
    wrap.style.display = any ? '' : 'none';
    var ta = document.getElementById('tax-exemption-reason');
    if (!any && ta) ta.value = '';
  }

  // === Robust: Delegation & Guards ===
  var root = document.getElementById('invoice-items');
  if (!root) return; // keine Tabelle auf der Seite

  // Clicks: Aufklapper & Entfernen
  root.addEventListener('click', function(e){
    // Aufklappen/Schließen
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (btn && root.contains(btn)) {
      var tr = btn.closest('tr.inv-item-row'); if (!tr) return;
      var row = tr.getAttribute('data-row');
      var det = document.querySelector('.inv-details[data-row="'+row+'"]'); if (!det) return;
      var open = det.style.display === 'table-row';
      det.style.display = open ? 'none' : 'table-row';
      tr.setAttribute('aria-expanded', String(!open));
      return; // nichts weiter tun
    }

    // Entfernen-Button
    var del = e.target.closest && e.target.closest('.btn-remove-item');
    if (del && root.contains(del)) {
      if (!confirm('Diese Position aus der Rechnung entfernen?')) return;

      var tr = del.closest('tr.inv-item-row'); if (!tr) return;
      var rowId = tr.getAttribute('data-row');
      var pid   = tr.getAttribute('data-project');

      // ggf. ID auf die Hidden-Blacklist setzen (Edit-Modus)
      var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
      if (idInput && idInput.value) {
        var trash = document.getElementById('invoice-hidden-trash');
        if (trash) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'items_deleted[]';
          hidden.value = idInput.value;
          trash.appendChild(hidden);
        }
      }

      // Detail-Zeile und Hauptzeile entfernen
      var det = document.querySelector('.inv-details[data-row="'+rowId+'"]');
      if (det) det.remove();
      tr.remove();

      // Projekt-Header leeren, wenn keine Zeile mehr
      if (pid) {
        var still = document.querySelectorAll('.inv-item-row[data-project="'+pid+'"]').length;
        if (still === 0) {
          var head = document.querySelector('.inv-group-head[data-project="'+pid+'"]');
          if (head) head.remove();
        }
      }

      // Feld „Begründung“ evtl. ausblenden
      toggleTaxReason();
      return;
    }
  });

  // Änderungen: Steuerart, Zeit-Checkboxen
  root.addEventListener('change', function(e){
    var sel = e.target.closest && e.target.closest('.inv-tax-sel');
    if (sel && root.contains(sel)) {
      updateVatFromScheme(sel);
      toggleTaxReason();
      return;
    }
    if (e.target.closest && e.target.closest('.time-checkbox')) {
      var tr = e.target.closest('tr')
                       .closest('tbody').closest('table')
                       .closest('td').closest('tr').previousElementSibling;
      if (tr && tr.classList.contains('inv-item-row')) recalcRow(tr);
    }
  });

  // Eingaben: Stundensatz / Steuersatz
  root.addEventListener('input', function(e){
    if (e.target.closest && (e.target.closest('.rate') || e.target.closest('.inv-vat-input'))){
      var tr = e.target.closest('tr.inv-item-row');
      if (tr) recalcRow(tr);
    }
  });

  // Initialisierung
  document.querySelectorAll('tr.inv-item-row').forEach(function(tr){
    var sel = tr.querySelector('.inv-tax-sel');
    var vat = tr.querySelector('.inv-vat-input');
    if (sel && vat && (vat.value === '' || vat.value === null)) {
      updateVatFromScheme(sel);
    } else {
      recalcRow(tr);
    }
  });
  toggleTaxReason();
})();
</script>