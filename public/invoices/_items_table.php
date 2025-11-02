<?php
require_once __DIR__ . '/../../src/lib/recurring.php';

/**
 * Flache Items-Tabelle (NEW & EDIT)
 * - NEW erwartet:  $groups   (tasks + times)  -> baut auto-Zeilen
 * - EDIT erwartet: $items    (inkl. time_entries[], entry_mode, total_* optional)
 *
 * Einheitliches Posting-Schema:
 *   items[idx][id]?            (nur EDIT, für bestehende Positionen)
 *   items[idx][task_id]?       (bei auto-Zeilen)
 *   items[idx][entry_mode]     ('auto' | 'time' | 'qty')
 *   items[idx][description]
 *   items[idx][hourly_rate]
 *   items[idx][tax_scheme]     ('standard'|'tax_exempt'|'reverse_charge')
 *   items[idx][vat_rate]
 *   items[idx][quantity]       (für 'time' (dezimalstunden) & 'qty')
 *   items[idx][hours]          (optional HH:MM Eingabe bei 'time', Server wandelt um)
 *   items[idx][time_ids][]     (bei 'auto')
 */

[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, $company ?? null);
$eff_scheme   = $eff_scheme   ?? 'standard';
$eff_vat_js   = number_format((float)$eff_vat, 2, '.', '');
$eff_vat_num  = (float)$eff_vat_js;

$mode = $mode ?? 'new'; // 'new' oder 'edit'

function _fmt_hhmm($min){ $min=(int)$min; $h=intdiv($min,60); $r=$min%60; return sprintf('%02d:%02d',$h,$r); }
function _fmt_hours_from_dec($d){ $d=(float)$d; $h=(int)floor($d); $m=(int)round(($d-$h)*60); return sprintf('%02d:%02d',$h,$m); }

$ROUND_UNIT_MINS = max(0, (int)($settings['invoice_round_minutes'] ?? 0));
if (!function_exists('_round_minutes_up')) {
  function _round_minutes_up(int $minutes, int $unit): int {
    if ($unit <= 0) return $minutes;
    if ($minutes <= 0) return 0;
    return (int)(ceil($minutes / $unit) * $unit);
  }
}


$GRAND_NET = 0.0; $GRAND_GROSS = 0.0;
?>
<style>
  .inv-item-row td { vertical-align: middle; }
  .inv-details { display:none; background:#fcfcfd; }
  .inv-details td { border-top:0; }
  .inv-grand-total td { background:#f7f7f9; font-weight:700; border-top:2px solid #dee2e6; }
  .inv-grand-total .label { text-transform: none; }
  .inv-toggle-btn{ border:0; background:transparent; width:28px; height:28px; display:grid; place-items:center; border-radius:50%; }
  .inv-toggle-btn:hover{ background:#eef2f7; }
  .chev{ width:16px; height:16px; transition:transform .2s ease; }
  .inv-item-row[aria-expanded="true"] .chev{ transform: rotate(90deg); }

  /* DnD */
  .dnd-drop-target { outline:2px dashed #6c757d; outline-offset:-2px; }
  .dnd-dragging    { opacity:.6; }

  /* Griff & Reorder-Indikator */
  .row-reorder-handle{ cursor:grab; display:inline-grid; place-items:center; width:22px; height:22px; border-radius:4px; }
  .row-reorder-handle:hover{ background:#eef2f7; }
  .row-reorder-handle:active{ cursor:grabbing; }
  .reorder-indicator-before{ box-shadow: inset 0 3px 0 0 #0d6efd; }
  .reorder-indicator-after { box-shadow: inset 0 -3px 0 0 #0d6efd; }

  /* Sichtbarer Einfüge-Platzhalter beim Reorder */
  .reorder-placeholder td { padding:0!important; height:0!important; border:0!important; }
  .reorder-placeholder td::before{
    content:"";
    display:block;
    height:0;
    border-top:3px solid #0d6efd;
  }

  /* Ziel-Hervorhebung, wenn eine Zeit auf eine Aufgaben-Hauptzeile gezogen wird */
  .inv-item-row.time-drop-accept {
    outline: 2px dashed #0d6efd;
    outline-offset: -2px;
    background: rgba(13,110,253,.06);
  }
</style>


<div id="invoice-hidden-trash"></div>
<div id="invoice-order-tracker"></div>

<div id="invoice-items" data-round-unit="<?= (int)$ROUND_UNIT_MINS ?>">
  <table class="table align-middle">
    <thead>
      <tr>
        <th style="width:36px"></th>
        <th>Aufgabe / Beschreibung</th>
        <th class="text-end w-110">Zeit / Menge</th>
        <th class="text-end w-110">Satz / Preis</th>
        <th class="text-end w-120">Steuerart</th>
        <th class="text-end w-90">MwSt %</th>
        <th class="text-end w-110">Netto</th>
        <th class="text-end w-110">Brutto</th>
        <th class="text-end" style="width:120px">Aktionen</th>
      </tr>
    </thead>
    <tbody>
<?php
$idx = 0;

/* ---------- NEW: flach aus $groups ---------- */
if (!empty($groups)) {
  foreach ($groups as $g) {
    foreach ($g['rows'] as $row) {
      $idx++;
      $desc    = $row['task_desc'];
      $rateNum = (float)($row['hourly_rate'] ?? 0.0);
      $rate    = number_format($rateNum, 2, '.', '');
      $taxNum  = isset($row['tax_rate']) ? (float)$row['tax_rate'] : ($eff_scheme==='standard' ? $eff_vat_num : 0.0);
      $tax     = number_format($taxNum, 2, '.', '');
      $sumMinRaw  = array_sum(array_map(fn($t)=>(int)$t['minutes'], $row['times']));
      $sumMin     = _round_minutes_up($sumMinRaw, $ROUND_UNIT_MINS);
      $sumHHM     = _fmt_hhmm($sumMin);
      $hoursPrec  = $sumMin / 60.0;            // aus GERUNDETEN Minuten
      $net        = $hoursPrec * $rateNum;
      $gross      = $net * (1 + $taxNum/100);
      $GRAND_NET += $net; $GRAND_GROSS += $gross;


      ?>
      <tr class="inv-item-row"
          data-row="<?=$idx?>"
          data-mode="auto"
          data-task-id="<?= (int)$row['task_id'] ?>"
          aria-expanded="false">
        <td class="text-center">
          <div class="d-flex justify-content-center gap-1">
            <button type="button" class="inv-toggle-btn" aria-label="Details ein-/ausklappen">
              <svg class="chev" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
            <span class="row-reorder-handle" draggable="true" aria-label="Position verschieben" title="Ziehen zum Sortieren">
              <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
                <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8Zm4 0h2v2H9V8ZM5 12h2v2H5v-2Zm4 0h2v2H9v-2Z" fill="currentColor"/>
              </svg>
            </span>
          </div>
        </td>
        <td>
          <input type="hidden" name="items[<?=$idx?>][task_id]" value="<?= (int)$row['task_id'] ?>">
          <input type="hidden" name="items[<?=$idx?>][entry_mode]" value="auto">
          <input type="text" class="form-control" name="items[<?=$idx?>][description]" value="<?=h($desc)?>">
        </td>
        <td class="text-end">
          <span class="sum-hhmm"><?=$sumHHM?></span>
          <input type="hidden" name="items[<?=$idx?>][sum_minutes]" value="<?=$sumMin?>" class="sum-minutes">
        </td>
        <td class="text-end">
          <input type="number" class="form-control text-end rate no-spin" name="items[<?=$idx?>][hourly_rate]" value="<?=$rate?>">
        </td>
        <td class="text-end">
          <select name="items[<?=$idx?>][tax_scheme]" class="form-select inv-tax-sel"
                  data-rate-standard="<?=$eff_vat_js?>" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00">
            <option value="standard"       <?= $eff_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
            <option value="tax_exempt"     <?= $eff_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
            <option value="reverse_charge" <?= $eff_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
          </select>
        </td>
        <td class="text-end">
          <input type="number" min="0" max="100" class="form-control text-end inv-vat-input no-spin"
                 name="items[<?=$idx?>][vat_rate]" value="<?= h(number_format($taxNum,2,'.','')) ?>">
        </td>
        <td class="text-end"><span class="net"><?=number_format($net,2,',','.')?></span></td>
        <td class="text-end"><span class="gross"><?=number_format($gross,2,',','.')?></span></td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
        </td>
      </tr>
      <tr class="inv-details" data-row="<?=$idx?>">
        <td></td>
        <td colspan="8">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th style="width:42px"></th><th>Zeitraum</th><th class="text-end">HH:MM</th></tr></thead>
              <tbody>
                <?php foreach ($row['times'] as $t): $tid=(int)$t['id']; $m=(int)$t['minutes']; ?>
                  <tr class="time-row" draggable="true" data-time-id="<?=$tid?>">
                    <td><input type="checkbox" class="form-check-input time-checkbox"
                               name="items[<?=$idx?>][time_ids][]" value="<?=$tid?>"
                               data-min="<?=$m?>" checked></td>
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

/* ---------- EDIT: flach aus $items ---------- */
if (empty($groups) && !empty($items)) {
  foreach ($items as $it) {
    $idx++;
    $desc      = $it['description'] ?? '';
    $rateNum   = (float)($it['hourly_rate'] ?? 0.0);
    $rate      = number_format($rateNum, 2, '.', '');
    $taxNum    = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0.0);
    $it_scheme = $it['tax_scheme'] ?? ($taxNum > 0 ? 'standard' : 'tax_exempt');
    $it_vat    = number_format($taxNum, 2, '.', '');

    $times     = $it['time_entries'] ?? [];
    $hasTimes  = !empty($times);
    $entryMode = $it['entry_mode'] ?? ($hasTimes ? 'auto' : 'qty');
    $quantity  = (float)($it['quantity'] ?? 0.0);

    $sumMin=0; $sumHHM='00:00';
    if ($hasTimes) {
      foreach ($times as $te) if (!empty($te['selected'])) $sumMin+=(int)$te['minutes'];
      // nur AUTO-Positionen werden nach Summenbildung gerundet
      $sumMin = ($entryMode==='auto') ? _round_minutes_up($sumMin, $ROUND_UNIT_MINS) : $sumMin;
      $sumHHM=_fmt_hhmm($sumMin);
    }

    if (array_key_exists('total_net',$it) && array_key_exists('total_gross',$it)) {
      $netVal=(float)$it['total_net']; $grossVal=(float)$it['total_gross'];
    } else {
      if ($entryMode==='auto'){ $netVal = ($sumMin/60.0)*$rateNum; }
      else { $netVal = $quantity * $rateNum; }
      $grossVal = $netVal * (1 + $taxNum/100);
    }
    $GRAND_NET += $netVal; $GRAND_GROSS += $grossVal;

    $dis    = !empty($canEditItems) ? '' : ' readonly ';
    $selDis = !empty($canEditItems) ? '' : ' disabled ';
    ?>
    <tr class="inv-item-row" data-row="<?=$idx?>" data-mode="<?=$entryMode?>" aria-expanded="false">
      <td class="text-center">
        <div class="d-flex justify-content-center gap-1">
          <?php if ($entryMode==='auto'): ?>
            <button type="button" class="inv-toggle-btn" aria-label="Details ein-/ausklappen">
              <svg class="chev" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          <?php endif; ?>
          <span class="row-reorder-handle" draggable="true" aria-label="Position verschieben" title="Ziehen zum Sortieren">
            <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
              <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8Zm4 0h2v2H9V8ZM5 12h2v2H5v-2Zm4 0h2v2H9v-2Z" fill="currentColor"/>
            </svg>
          </span>
        </div>
      </td>
      <td>
        <?php if (!empty($ri_key_by_desc[$it['description']])): ?>
          <input type="hidden" name="items[<?= (int)$idx ?>][ri_key]" value="<?= h($ri_key_by_desc[$it['description']]) ?>">
        <?php endif; ?>
        <input type="hidden" name="items[<?=$idx?>][id]" value="<?= (int)($it['id'] ?? 0) ?>">
        <input type="hidden" class="entry-mode" name="items[<?=$idx?>][entry_mode]" value="<?=$entryMode?>">
        <?php if (!empty($it['task_id'])): ?>
          <input type="hidden" name="items[<?=$idx?>][task_id]" value="<?= (int)$it['task_id'] ?>">
        <?php endif; ?>
        <input type="text" class="form-control" name="items[<?=$idx?>][description]" value="<?=h($desc)?>" <?=$dis?>>
      </td>

      <td class="text-end">
        <?php if ($entryMode==='auto'): ?>
          <span class="sum-hhmm"><?=$sumHHM?></span>
          <input type="hidden" name="items[<?=$idx?>][sum_minutes]" value="<?=$sumMin?>" class="sum-minutes">
        <?php elseif ($entryMode==='time'): ?>
          <div class="d-flex gap-1 align-items-center justify-content-end">
            <input type="text" class="form-control text-end hours-input"
                   name="items[<?=$idx?>][hours]" value="<?= _fmt_hours_from_dec($quantity) ?>" <?=$dis?>>
          </div>
          <input type="hidden" class="quantity-dec" name="items[<?=$idx?>][quantity]" value="<?= number_format($quantity,3,'.','') ?>">
        <?php else: ?>
          <input type="number" class="form-control text-end quantity no-spin"
                 name="items[<?=$idx?>][quantity]" value="<?= _fmt_qty($quantity,3,'.','') ?>" <?=$dis?>>
        <?php endif; ?>
      </td>

      <td class="text-end">
        <input type="number"  class="form-control text-end rate no-spin"
               name="items[<?=$idx?>][hourly_rate]" value="<?=$rate?>" <?=$dis?>>
      </td>

      <td class="text-end">
        <select name="items[<?=$idx?>][tax_scheme]" class="form-select inv-tax-sel"
                data-rate-standard="<?=$eff_vat_js?>" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00" <?=$selDis?>>
          <option value="standard"       <?= $it_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt"     <?= $it_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $it_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
      </td>
      <td class="text-end">
        <input type="number" min="0" max="100"
               class="form-control text-end inv-vat-input no-spin"
               name="items[<?=$idx?>][vat_rate]"
               value="<?= h($it_vat) ?>" <?=$dis?>>
      </td>

      <td class="text-end"><span class="net"><?= number_format($netVal,   2, ',', '.') ?></span></td>
      <td class="text-end"><span class="gross"><?= number_format($grossVal, 2, ',', '.') ?></span></td>

      <td class="text-end text-nowrap">
        <button <?php if (empty($canEditItems)) echo " disabled ";?> type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
      </td>
    </tr>

    <?php if ($entryMode==='auto'): ?>
    <tr class="inv-details" data-row="<?=$idx?>">
      <td></td>
      <td colspan="8">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th style="width:42px"></th><th>Zeitraum</th><th class="text-end">HH:MM</th></tr></thead>
            <tbody>
              <?php foreach ($times as $te): $tid=(int)$te['id']; $m=(int)$te['minutes']; ?>
                <tr class="time-row" draggable="true" data-time-id="<?=$tid?>">
                  <td><input class="form-check-input time-checkbox" type="checkbox"
                             name="items[<?=$idx?>][time_ids][]"
                             value="<?=$tid?>" <?=!empty($te['selected'])?'checked':''?> data-min="<?=$m?>" <?=$selDis?>></td>
                  <td><?=h($te['started_at'] ?? '')?> <?= isset($te['ended_at']) ? '– '.h($te['ended_at']) : '' ?></td>
                  <td class="text-end"><?=_fmt_hhmm($m)?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </td>
    </tr>
    <?php endif; ?>

  <?php
  }
}
?>
      <tr class="inv-grand-total">
        <td></td>
        <td colspan="5" class="text-end label">Gesamtsumme</td>
        <td class="text-end"><span id="grand-net"><?= number_format($GRAND_NET, 2, ',', '.') ?></span></td>
        <td class="text-end"><span id="grand-gross"><?= number_format($GRAND_GROSS, 2, ',', '.') ?></span></td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>

<script>
(function(){
  var root = document.getElementById('invoice-items'); if (!root) return;
  var roundUnit = parseInt(root.getAttribute('data-round-unit') || '0', 10) || 0;
  /* ---------- Helpers ---------- */
  function getDetailsRowByMain(mainTr){
    if (!mainTr) return null;
    var det = mainTr.nextElementSibling;
    if (!det || !det.classList.contains('inv-details')) {
      var rowId = mainTr.getAttribute('data-row');
      det = root.querySelector('.inv-details[data-row="'+rowId+'"]');
    }
    return det || null;
  }
  function getDetailsRowById(rowId){
    return root.querySelector('.inv-details[data-row="'+rowId+'"]') || null;
  }
  function getDetailsTbodyById(rowId){
    var det = getDetailsRowById(rowId);
    return det ? det.querySelector('tbody') : null;
  }
  function getAfterPairNode(refMain){
    var next = refMain.nextElementSibling;
    if (next && next.classList.contains('inv-details') && next.getAttribute('data-row') === refMain.getAttribute('data-row')) {
      return next.nextElementSibling;
    }
    return next;
  }
  function clearTimeDropAccept(){
    root.querySelectorAll('tr.inv-item-row.time-drop-accept').forEach(function(r){
      r.classList.remove('time-drop-accept');
    });
  }

  function toNumber(str){
    if (typeof str !== 'string') str = String(str ?? '');
    str = str.trim(); if (!str) return 0;
    if (str.indexOf(',') !== -1 && str.indexOf('.') !== -1) { str = str.replace(/\./g,'').replace(',', '.'); }
    else if (str.indexOf(',') !== -1) { str = str.replace(',', '.'); }
    var n = parseFloat(str); return isFinite(n) ? n : 0;
  }
  function fmt2(n){ return (n||0).toFixed(2).replace('.',','); }
  function hhmm(min){ min = Math.max(0, parseInt(min||0,10)); var h = Math.floor(min/60), m = min%60; return (h<10?'0':'')+h+':' + (m<10?'0':'')+m; }
  function parseHours(v){ v=String(v||'').trim(); if(v.includes(':')){ var sp=v.split(':'); var h=parseInt(sp[0]||'0',10)||0; var m=parseInt(sp[1]||'0',10)||0; return h + m/60; } return toNumber(v); }

  /* ---------- Summen ---------- */
  function recalcTotals(){
    var gnet=0, ggross=0;
    root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
      gnet  += toNumber(tr.querySelector('.net')?.textContent || '0');
      ggross+= toNumber(tr.querySelector('.gross')?.textContent || '0');
    });
    var gN = document.getElementById('grand-net'); var gG = document.getElementById('grand-gross');
    if (gN) gN.textContent = fmt2(gnet); if (gG) gG.textContent = fmt2(ggross);
  }

  function recalcRow(tr){
    var mode = tr.getAttribute('data-mode') || tr.querySelector('.entry-mode')?.value || 'auto';

    if (mode === 'auto') {
      var minutes = 0;
      var rowId   = tr.getAttribute('data-row');
      var details = getDetailsRowById(rowId);
      if (details) {
        details.querySelectorAll('.time-checkbox:checked').forEach(function(cb){
          minutes += parseInt(cb.getAttribute('data-min')||'0',10);
        });
      } else {
        var sm = tr.querySelector('.sum-minutes');
        minutes = sm ? parseInt(sm.value||'0',10) : 0;
      }
       // Clientseitige Vorschau: Minuten nach Summenbildung runden (nur Anzeige)
      if (roundUnit > 0 && minutes > 0) {
        minutes = Math.ceil(minutes / roundUnit) * roundUnit;
      }

      var rate = toNumber(tr.querySelector('.rate')?.value||'0');
      var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');
      var net   = (minutes/60.0) * rate;
      var gross = net * (1 + vat/100);
      var hh = tr.querySelector('.sum-hhmm'); if (hh) hh.textContent = hhmm(minutes);
      var smi= tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
      var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
      var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
      recalcTotals();
      return;
    }

    var rate = toNumber(tr.querySelector('.rate')?.value||'0');
    var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');
    var qtyDec = 0;

    if (mode === 'time') {
      var hinp = tr.querySelector('.hours-input');
      qtyDec = parseHours(hinp ? hinp.value : '0');
      var qHidden = tr.querySelector('.quantity-dec');
      if (qHidden) qHidden.value = String(qtyDec.toFixed(3));
    } else {
      qtyDec = toNumber(tr.querySelector('.quantity')?.value||'0');
    }

    var net   = qtyDec * rate;
    var gross = net * (1 + vat/100);
    var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
    var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
    recalcTotals();
  }

  function updateVatFromScheme(sel){
    var tr  = sel.closest('tr.inv-item-row'); if (!tr) return;
    var vat = tr.querySelector('.inv-vat-input'); if (!vat) return;
    var map = {
      'standard':       sel.dataset.rateStandard || '19.00',
      'tax_exempt':     sel.dataset.rateTaxExempt || '0.00',
      'reverse_charge': sel.dataset.rateReverseCharge || '0.00'
    };
    if (sel.value in map) vat.value = map[sel.value];
    recalcRow(tr);
  }

  function toggleTaxReason(){
    var wrap = document.getElementById('tax-exemption-reason-wrap');
    if (!wrap) return;
    var any = false;
    root.querySelectorAll('.inv-tax-sel').forEach(function(sel){ if (sel.value !== 'standard') any = true; });
    wrap.style.display = any ? '' : 'none';
    var ta = document.getElementById('tax-exemption-reason'); if (!any && ta) ta.value = '';
  }

  // Mode-Switch (nur manuelle Zeilen)
  function switchMode(tr, newMode){
    if (!tr) return;
    var cur = tr.getAttribute('data-mode') || 'auto';
    if (cur === 'auto') return;
    tr.setAttribute('data-mode', newMode);
    var hidden = tr.querySelector('.entry-mode'); if (hidden) hidden.value = newMode;

    var qty = tr.querySelector('.quantity');
    var hrs = tr.querySelector('.hours-input');
    var qhid= tr.querySelector('.quantity-dec');
    if (newMode === 'time') {
      if (!hrs) {
        var td = qty.closest('td');
        qty.remove();
        var html = '<div class="d-flex gap-1 align-items-center justify-content-end">'
                 + '<input type="text" class="form-control text-end hours-input"  name="" value="00:00"></div>';
        td.insertAdjacentHTML('afterbegin', html);
        if (!qhid) { var h = document.createElement('input'); h.type='hidden'; h.className='quantity-dec'; td.appendChild(h); }
      }
    } else {
      if (!qty) {
        var td = hrs.closest('td');
        hrs.closest('div').remove();
        if (qhid) qhid.remove();
        var html = '<input type="number" class="form-control text-end quantity no-spin" value="1">';
        td.insertAdjacentHTML('afterbegin', html);
      }
    }

    var bt = tr.querySelector('.switch-time'); var bq = tr.querySelector('.switch-qty');
    if (bt) bt.classList.toggle('active', newMode==='time');
    if (bq) bq.classList.toggle('active', newMode==='qty');

    recalcRow(tr);
  }

  // Reindex: passt data-row & name="items[old]" → "items[new]" an
  function reindexRows(){
    var pairs = [];
    root.querySelectorAll('tr.inv-item-row').forEach(function(main){
      var det = main.nextElementSibling;
      if (!(det && det.classList.contains('inv-details'))) det = null;
      pairs.push({ main: main, det: det, oldIdx: String(main.getAttribute('data-row') || '') });
    });

    pairs.forEach(function(p, i){
      var newIdx = String(i + 1);
      var oldIdx = p.oldIdx || newIdx;

      p.main.setAttribute('data-row', newIdx);
      if (p.det) p.det.setAttribute('data-row', newIdx);

      [p.main, p.det].forEach(function(scope){
        if (!scope) return;
        scope.querySelectorAll('[name]').forEach(function(el){
          var nm = el.getAttribute('name'); if (!nm) return;
          var esc = oldIdx.replace(/[-/\\^$*+?.()|[\]{}]/g,'\\$&');
          var re  = new RegExp('^items\\[' + esc + '\\]');
          var nn  = nm.replace(re, 'items['+newIdx+']');
          if (nn !== nm) el.setAttribute('name', nn);
        });
      });
    });
  }

  function ensurePlaceholder(){
    var ph = document.getElementById('invoice-reorder-placeholder');
    if (!ph) {
      ph = document.createElement('tr');
      ph.id = 'invoice-reorder-placeholder';
      ph.className = 'reorder-placeholder';
      var td = document.createElement('td');
      td.colSpan = (root.querySelector('thead tr')?.children.length) || 9;
      ph.appendChild(td);
    }
    return ph;
  }
  function removePlaceholder(){
    var ph = document.getElementById('invoice-reorder-placeholder');
    if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
  }

  function updateOrderHidden(){
    var box = document.getElementById('invoice-order-tracker'); if (!box) return;
    box.innerHTML = '';
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      var itemIdInput = r.querySelector('input[name^="items["][name$="[id]"]');
      if (itemIdInput && itemIdInput.value) {
        var i = document.createElement('input'); i.type='hidden'; i.name='item_order[]'; i.value=String(itemIdInput.value); box.appendChild(i);
      }
    });
  }

  function clearReorderIndicators(){
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      r.classList.remove('reorder-indicator-before','reorder-indicator-after');
    });
  }

  function removeSourceItemIfEmpty(sourceRow){
    if (!sourceRow || (sourceRow.getAttribute('data-mode') !== 'auto')) return;
    var rowId = sourceRow.getAttribute('data-row');
    var srcTbody = getDetailsTbodyById(rowId);
    if (!srcTbody) return;
    var anyLeft = srcTbody.querySelector('.time-checkbox:checked');
    if (anyLeft) return;

    var srcItemIdInput = sourceRow.querySelector('input[name^="items["][name$="[id]"]');

    var det = getDetailsRowById(rowId);
    if (det) det.remove();

    if (srcItemIdInput && srcItemIdInput.value) {
      var trash = document.getElementById('invoice-hidden-trash');
      if (trash) {
        var hidden = document.createElement('input');
        hidden.type='hidden';
        hidden.name='items_deleted[]';
        hidden.value=srcItemIdInput.value;
        trash.appendChild(hidden);
      }
    }
    sourceRow.remove();
    reindexRows();
    updateOrderHidden();
  }

  /* ---------- DnD ---------- */
  var dragData = null; // {kind:'time'|'task'|'reorder', fromRowId, timeId?}

  function markDropTargets(on){
    root.querySelectorAll('.inv-details tbody').forEach(function(tb){
      tb.classList.toggle('dnd-drop-target', !!on);
    });
    root.querySelectorAll('tr.inv-item-row[data-mode="auto"]').forEach(function(r){
      r.classList.toggle('dnd-drop-target', !!on);
    });
  }

  // 1) Zeit-Zeilen als Draggable
  root.querySelectorAll('.inv-details tbody tr.time-row[draggable="true"]').forEach(function(tr){
    tr.addEventListener('dragstart', function(e){
      var detailsTr = tr.closest('tr.inv-details');
      var fromRowId = detailsTr ? detailsTr.getAttribute('data-row') : (tr.closest('tr.inv-item-row')?.getAttribute('data-row') || '');
      var timeId = tr.getAttribute('data-time-id') || '';
      dragData = { kind:'time', timeId:String(timeId), fromRowId:String(fromRowId) };
      tr.classList.add('dnd-dragging');
      markDropTargets(true);
      try { e.dataTransfer.setData('text/plain','time:'+timeId); } catch(_) {}
      if (e.dataTransfer) e.dataTransfer.effectAllowed='move';
    });
    tr.addEventListener('dragend', function(){
      tr.classList.remove('dnd-dragging');
      dragData = null; markDropTargets(false); clearTimeDropAccept();
    });
  });

  // 2) Ganze Task-Zeile als Draggable
  root.querySelectorAll('tr.inv-item-row[data-mode="auto"]').forEach(function(row){
    row.setAttribute('draggable','true');
    row.addEventListener('dragstart', function(e){
      if (e.target && e.target.closest && e.target.closest('.row-reorder-handle')) return; // Griff → Reorder
      var fromRowId = row.getAttribute('data-row') || '';
      dragData = { kind:'task', fromRowId:String(fromRowId) };
      row.classList.add('dnd-dragging');
      markDropTargets(true);
      try { e.dataTransfer.setData('text/plain','task:'+fromRowId); } catch(_) {}
      if (e.dataTransfer) e.dataTransfer.effectAllowed='move';
    });
    row.addEventListener('dragend', function(){
      row.classList.remove('dnd-dragging');
      dragData = null; markDropTargets(false); clearTimeDropAccept();
    });
  });

  // 3) Drop-Ziel: Detail-Tabellenkörper
  root.querySelectorAll('.inv-details tbody').forEach(function(tb){
    tb.addEventListener('dragover', function(e){
      if (!dragData) return;
      e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect='move';
    });
    tb.addEventListener('drop', function(e){
      e.preventDefault();
      // wichtig: nicht stoppen → Kopfzeilen-Handler dürfen parallel feuern, falls nötig
      if (!dragData) return;

      var targetDetails = tb.closest('tr.inv-details');
      if (!targetDetails) return;
      var targetRow = root.querySelector('tr.inv-item-row[data-row="'+targetDetails.getAttribute('data-row')+'"]');
      if (!targetRow) return;

      // sicher öffnen
      targetRow.setAttribute('aria-expanded','true'); targetDetails.style.display='table-row';

      if (dragData.kind === 'time') {
        var fromRow = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
        var timeTr = getDetailsRowById(dragData.fromRowId)?.querySelector('tbody tr.time-row[data-time-id="'+dragData.timeId+'"]');
        if (!timeTr) return;

        tb.appendChild(timeTr);
        var cb = timeTr.querySelector('.time-checkbox');
        if (cb) {
          var targetRowId = targetRow.getAttribute('data-row') || '';
          cb.name = 'items[' + targetRowId + '][time_ids][]';
          cb.checked = true;
        }

        if (fromRow) recalcRow(fromRow);
        recalcRow(targetRow);
        removeSourceItemIfEmpty(fromRow);
        recalcTotals(); toggleTaxReason();
        updateOrderHidden();
      } else if (dragData.kind === 'task') {
        var fromRow = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
        var fromTbody = getDetailsTbodyById(dragData.fromRowId);
        if (!fromRow || !fromTbody) return;

        Array.from(fromTbody.querySelectorAll('tr.time-row')).forEach(function(timeTr){
          tb.appendChild(timeTr);
          var cb = timeTr.querySelector('.time-checkbox');
          if (cb) {
            var targetRowId = targetRow.getAttribute('data-row') || '';
            cb.name = 'items[' + targetRowId + '][time_ids][]';
            cb.checked = true;
          }
        });

        recalcRow(targetRow);
        recalcRow(fromRow);
        removeSourceItemIfEmpty(fromRow);
        recalcTotals(); toggleTaxReason();
        updateOrderHidden();
      }

      // neu: immer aufräumen
      clearTimeDropAccept();
      markDropTargets(false);
    });
  });

  // 4) Drop-Ziel: Direkt auf Kopfzeile (auch zugeklappt)
  root.querySelectorAll('tr.inv-item-row[data-mode="auto"]').forEach(function(row){
    var hoverTimer = null;

    row.addEventListener('dragenter', function(e){
      if (!dragData || (dragData.kind!=='time' && dragData.kind!=='task')) return;
      row.classList.add('time-drop-accept');
      if (hoverTimer) clearTimeout(hoverTimer);
      hoverTimer = setTimeout(function(){
        var det = getDetailsRowByMain(row);
        if (det && det.style.display !== 'table-row') {
          det.style.display = 'table-row';
          row.setAttribute('aria-expanded', 'true');
        }
      }, 400);
    });

    row.addEventListener('dragover', function(e){
      if (!dragData || (dragData.kind!=='time' && dragData.kind!=='task')) return;
      e.preventDefault(); // Drop erlauben
      row.classList.add('time-drop-accept');
    });

    row.addEventListener('dragleave', function(){
      row.classList.remove('time-drop-accept');
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
    });

    row.addEventListener('drop', function(e){
      if (!dragData || (dragData.kind!=='time' && dragData.kind!=='task')) return;
      e.preventDefault();

      row.classList.remove('time-drop-accept');
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }

      var targetRowId = row.getAttribute('data-row') || '';
      var targetTbody = getDetailsTbodyById(targetRowId);
      var targetDetails = getDetailsRowById(targetRowId);
      if (!targetTbody || !targetDetails) return;

      // öffnen (falls zu)
      row.setAttribute('aria-expanded','true');
      targetDetails.style.display = 'table-row';

      if (dragData.kind === 'time') {
        var fromRow  = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
        var timeTr   = getDetailsRowById(dragData.fromRowId)?.querySelector('tbody tr.time-row[data-time-id="'+dragData.timeId+'"]');
        if (!timeTr) return;

        targetTbody.appendChild(timeTr);
        var cb = timeTr.querySelector('.time-checkbox');
        if (cb) {
          cb.name = 'items[' + targetRowId + '][time_ids][]';
          cb.checked = true;
        }

        if (fromRow) recalcRow(fromRow);
        recalcRow(row);
        removeSourceItemIfEmpty(fromRow);
        recalcTotals(); toggleTaxReason();
        updateOrderHidden();
      } else if (dragData.kind === 'task') {
        var fromRow   = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
        var fromTbody = getDetailsTbodyById(dragData.fromRowId);
        if (!fromRow || !fromTbody || fromRow === row) return;

        Array.from(fromTbody.querySelectorAll('tr.time-row')).forEach(function(timeTr){
          targetTbody.appendChild(timeTr);
          var cb = timeTr.querySelector('.time-checkbox');
          if (cb) {
            cb.name = 'items[' + targetRowId + '][time_ids][]';
            cb.checked = true;
          }
        });

        recalcRow(row);
        recalcRow(fromRow);
        removeSourceItemIfEmpty(fromRow);
        recalcTotals(); toggleTaxReason();
        updateOrderHidden();
      }

      // neu: immer aufräumen
      clearTimeDropAccept();
      markDropTargets(false);
    });
  });

  // 5) Reihenfolge via Handle (nur Hauptzeilen)
  root.querySelectorAll('.row-reorder-handle').forEach(function(h){
    h.addEventListener('dragstart', function(e){
      var row = h.closest('tr.inv-item-row'); if (!row) return;
      var fromId = row.getAttribute('data-row') || '';
      dragData = { kind:'reorder', fromRowId:String(fromId) };
      row.classList.add('dnd-dragging');
      if (e.stopPropagation) e.stopPropagation();
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', 'reorder:'+fromId); } catch(_) {}
      }
    });
    h.addEventListener('dragend', function(e){
      var row = h.closest('tr.inv-item-row'); if (row) row.classList.remove('dnd-dragging');
      dragData = null; clearReorderIndicators(); removePlaceholder(); clearTimeDropAccept();
      if (e.stopPropagation) e.stopPropagation();
    });
  });

  root.querySelectorAll('tr.inv-item-row').forEach(function(targetRow){
    targetRow.addEventListener('dragover', function(e){
      if (!dragData || dragData.kind !== 'reorder') return;
      e.preventDefault();

      var rect = targetRow.getBoundingClientRect();
      var after = (e.clientY - rect.top) > (rect.height / 2);

      clearReorderIndicators();
      targetRow.classList.add(after ? 'reorder-indicator-after' : 'reorder-indicator-before');

      var ph = ensurePlaceholder();
      var tbody = targetRow.parentNode;
      var ref   = after ? getAfterPairNode(targetRow) : targetRow;
      if (ph !== ref) tbody.insertBefore(ph, ref || null);
    });

    targetRow.addEventListener('dragleave', function(){
      if (dragData && dragData.kind === 'reorder') {
        targetRow.classList.remove('reorder-indicator-before','reorder-indicator-after');
      }
    });

    targetRow.addEventListener('drop', function(e){
      if (!dragData || dragData.kind !== 'reorder') return;
      e.preventDefault();

      var rect = targetRow.getBoundingClientRect();
      var after = (e.clientY - rect.top) > (rect.height / 2);

      var fromId = dragData.fromRowId;
      var toId   = targetRow.getAttribute('data-row') || '';

      clearReorderIndicators();

      (function moveRowPair(fromRowId, targetRowId, placeAfter){
        if (!fromRowId || !targetRowId || fromRowId === targetRowId) return;
        var fromMain   = root.querySelector('tr.inv-item-row[data-row="'+fromRowId+'"]');
        var targetMain = root.querySelector('tr.inv-item-row[data-row="'+targetRowId+'"]');
        if (!fromMain || !targetMain) return;
        var fromDet = getDetailsRowById(fromRowId);
        var tbody = targetMain.parentNode;
        var refNode = placeAfter ? getAfterPairNode(targetMain) : targetMain;
        if (refNode) {
          tbody.insertBefore(fromMain, refNode);
          if (fromDet) tbody.insertBefore(fromDet, refNode);
        } else {
          tbody.appendChild(fromMain);
          if (fromDet) tbody.appendChild(fromDet);
        }
        reindexRows();
      })(fromId, toId, after);

      removePlaceholder();
      reindexRows();
      updateOrderHidden();
      clearTimeDropAccept();
    });
  });

  /* ---------- Keine Textdrops in Inputs/Selects (aber Propagation NICHT stoppen) ---------- */
  root.addEventListener('dragover', function(e){
    if (e.target && (e.target.matches('input, textarea, select'))) {
      e.preventDefault(); // verhindert Textcursor/Copy-Effekt
    }
  }, true);
  root.addEventListener('drop', function(e){
    if (e.target && (e.target.matches('input, textarea, select'))) {
      e.preventDefault(); // verhindert Einfügen von "time:123" o.ä.
      // KEIN stopPropagation mehr → Kopfzeile/Row kann den Drop verarbeiten
    }
  }, true);

  /* ---------- Delegierte UI-Events ---------- */
  root.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (btn && root.contains(btn)) {
      var tr  = btn.closest('tr.inv-item-row'); if (!tr) return;
      var det = getDetailsRowByMain(tr);
      if (!det) return;
      var open = det.style.display === 'table-row';
      det.style.display = open ? 'none' : 'table-row';
      tr.setAttribute('aria-expanded', String(!open));
      return;
    }

    var del = e.target.closest && e.target.closest('.btn-remove-item');
    if (del && root.contains(del)) {
      if (!confirm('Diese Position aus der Rechnung entfernen?')) return;
      var tr = del.closest('tr.inv-item-row'); if (!tr) return;
      var rowId = tr.getAttribute('data-row');
      var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
      if (idInput && idInput.value) {
        var trash = document.getElementById('invoice-hidden-trash');
        if (trash) { var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items_deleted[]'; hidden.value=idInput.value; trash.appendChild(hidden); }
      }
      var det = getDetailsRowById(rowId); if (det) det.remove();
      tr.remove();
      reindexRows();
      toggleTaxReason();
      recalcTotals();
      updateOrderHidden();
      return;
    }

    var swTime = e.target.closest && e.target.closest('.switch-time');
    var swQty  = e.target.closest && e.target.closest('.switch-qty');
    if ((swTime || swQty) && root.contains(e.target)) {
      var tr2 = e.target.closest('tr.inv-item-row'); if (!tr2) return;
      switchMode(tr2, swTime ? 'time' : 'qty');
      return;
    }
  });

  root.addEventListener('change', function(e){
    var sel = e.target.closest && e.target.closest('.inv-tax-sel');
    if (sel && root.contains(sel)) { updateVatFromScheme(sel); toggleTaxReason(); return; }
    if (e.target.closest && e.target.closest('.time-checkbox')) {
      var rowTr = e.target.closest('tr.inv-details')?.previousElementSibling;
      if (rowTr && rowTr.classList.contains('inv-item-row')) recalcRow(rowTr);
      return;
    }
  });

  root.addEventListener('input', function(e){
    if (e.target.matches && (e.target.matches('.rate') || e.target.matches('.inv-vat-input') || e.target.matches('.quantity') || e.target.matches('.hours-input'))) {
      var tr = e.target.closest('tr.inv-item-row'); if (tr) recalcRow(tr);
    }
  });

  /* ---------- Zusätzlich: globales Drag-Ende säubert Highlight ---------- */
  document.addEventListener('dragend', clearTimeDropAccept, true);

  /* ---------- Initial ---------- */
  root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
    var sel = tr.querySelector('.inv-tax-sel');
    var vat = tr.querySelector('.inv-vat-input');
    if (sel && vat && (vat.value === '' || vat.value === null)) updateVatFromScheme(sel);
    recalcRow(tr);
  });
  toggleTaxReason();
  recalcTotals();

  (function(){
    var f = window.requestAnimationFrame || setTimeout;
    f(function(){
      reindexRows();
      updateOrderHidden();
    });
  })();
})();
</script>