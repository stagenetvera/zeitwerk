<?php
/**
 * Reusable Items-Table for invoices (new & edit)
 *
 * NEW expects:  $groups
 * EDIT expects: $items  (inkl. time_entries[] und project_title)
 */
[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, $company ?? null);

$eff_scheme   = $eff_scheme   ?? 'standard';
$eff_vat_js   = number_format((float)$eff_vat, 2, '.', '');
$eff_vat_num  = (float)str_replace(',', '.', (string)$eff_vat_js);

// Namenskonventionen
$mode      = $mode      ?? 'new';
$rowName   = $rowName   ?? ($mode === 'edit' ? 'items' : 'tasks');
$timesName = $timesName ?? ($mode === 'edit' ? 'times_selected' : 'time_ids');
$scheme_default = $eff_scheme;
$rate_default   = $eff_scheme==='standard' ? number_format((float)$eff_vat,2,'.','') : '0.00';

$NAME_TASKS = $rowName;
$NAME_TIMES = $timesName;

function _fmt_hhmm($min){ $h=intdiv($min,60); $r=$min%60; return sprintf('%02d:%02d',$h,$r); }
function _fmt_hours_from_dec($d){ $d=(float)$d; $h=(int)floor($d); $m=(int)round(($d-$h)*60); return sprintf('%02d:%02d',$h,$m); }



$GRAND_NET = 0.0; $GRAND_GROSS = 0.0;
?>
<style>
  .inv-group-head td{ background:#f7f7f9; font-weight:600; }
  .inv-group-head .proj-net, .inv-group-head .proj-gross{ font-weight:700; }
  .inv-item-row td { vertical-align: middle; }
  .inv-details { display:none; background:#fcfcfd; }
  .inv-details td { border-top:0; }
  .inv-grand-total td { background:#f7f7f9; font-weight:700; border-top:2px solid #dee2e6; }
  .inv-grand-total .label { text-transform: none; }
  .inv-toggle-btn{ border:0; background:transparent; width:28px; height:28px; display:grid; place-items:center; border-radius:50%; }
  .inv-toggle-btn:hover{ background:#eef2f7; }
  .chev{ width:16px; height:16px; transition:transform .2s ease; }
  .inv-item-row[aria-expanded="true"] .chev{ transform: rotate(90deg); }
  .mode-switch .btn { padding: .1rem .4rem; }
</style>

<div id="invoice-hidden-trash"></div>

<div id="invoice-items">
  <table class="table align-middle">
    <thead>
      <tr>
        <th style="width:36px"></th>
        <th>Aufgabe</th>
        <th class="text-end w-110">Zeit / Menge</th>
        <th class="text-end w-90">Stundensatz / Einzelpreis</th>
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

/* -------- NEW (aus $groups) -> immer auto (time-based) -------- */
if (!empty($groups)) {
  $showProjectHeads = count($groups) > 1;
  foreach ($groups as $g) {
    $pid = (int)$g['project_id'];

    $projNet = 0.0; $projGross = 0.0;
    foreach ($g['rows'] as $rowPre) {
      $rateNum = (float)($rowPre['hourly_rate'] ?? 0.0);
      $taxNum  = (float)($rowPre['tax_rate']    ?? 0.0);
      $sumMin  = array_sum(array_map(fn($t)=>(int)$t['minutes'], $rowPre['times']));
      $netPre  = ($sumMin/60.0) * $rateNum;
      $grossPre= $netPre * (1 + $taxNum/100);
      $projNet  += $netPre; $projGross+= $grossPre;
    }
    $GRAND_NET += $projNet; $GRAND_GROSS+= $projGross;

    if ($showProjectHeads): ?>
      <tr class="inv-group-head" data-project="<?=$pid?>">
        <td colspan="6"><?=h($g['project_title'])?></td>
        <td class="text-end proj-net"><?= number_format($projNet, 2, ',', '.') ?></td>
        <td class="text-end proj-gross"><?= number_format($projGross, 2, ',', '.') ?></td>
        <td></td>
      </tr>
    <?php endif;

    foreach ($g['rows'] as $row) {
      $idx++;
      $desc    = $row['task_desc'];
      $rateNum = (float)($row['hourly_rate'] ?? 0.0);
      $rate    = number_format($rateNum, 2, ',', '');
      $taxNum  = (float)($row['tax_rate'] ?? 0.0);
      $tax     = number_format($taxNum, 2, ',', '');
      $sumMin  = array_sum(array_map(fn($t)=>(int)$t['minutes'], $row['times']));
      $sumHHM  = _fmt_hhmm($sumMin);
      $net     = ($sumMin/60.0) * $rateNum;
      $gross   = $net * (1 + $taxNum/100);
      ?>
      <tr class="inv-item-row" data-row="<?=$idx?>" data-project="<?=$pid?>" data-mode="auto" aria-expanded="false">
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
          <input type="hidden" name="items[<?=$idx?>][entry_mode]" value="auto">
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
          <select name="items[<?=$idx?>][tax_scheme]" class="form-select inv-tax-sel"
                  data-rate-standard="19.00" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00">
            <option value="standard"       <?= $scheme_default==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
            <option value="tax_exempt"     <?= $scheme_default==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
            <option value="reverse_charge" <?= $scheme_default==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
          </select>
        </td>
        <td class="text-end">
          <input type="number" step="0.01" min="0" max="100" class="form-control text-end inv-vat-input"
                 name="items[<?=$idx?>][vat_rate]" value="<?= h($rate_default) ?>">
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
              <thead><tr><th style="width:42px"></th><th>Zeitraum</th><th class="text-end">HH:MM</th></tr></thead>
              <tbody>
                <?php foreach ($row['times'] as $t): $tid=(int)$t['id']; $m=(int)$t['minutes']; ?>
                  <tr>
                    <td><input type="checkbox" class="form-check-input time-checkbox"
                               name="<?=$NAME_TIMES?>[<?= (int)$row['task_id'] ?>][]" value="<?=$tid?>"
                               data-min="<?=$m?>" checked></td>
                    <td><?=h(_fmt_dmy($t['started_at']))?> – <?=h(_fmt_dmy($t['ended_at']))?></td>
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

/* -------- EDIT (aus $items): auto | time (manuell) | qty (manuell) -------- */
if (empty($groups) && !empty($items)) {
  $byP = [];
  foreach ($items as $it) {
    $pid = (int)($it['project_id'] ?? 0);
    if (!isset($byP[$pid])) $byP[$pid] = ['title'=>$it['project_title'] ?? '', 'rows'=>[]];
    $byP[$pid]['rows'][] = $it;
  }
  $showHeads = count($byP) > 1;

  foreach ($byP as $pid=>$bucket) {
    $projNet = 0.0; $projGross = 0.0;
    foreach ($bucket['rows'] as $rit) {
      $rateNum = (float)($rit['hourly_rate'] ?? 0.0);
      $taxNum  = (float)($rit['vat_rate'] ?? $rit['tax_rate'] ?? 0.0);

      if (array_key_exists('total_net',$rit) && array_key_exists('total_gross',$rit)) {
        $n=(float)$rit['total_net']; $g=(float)$rit['total_gross'];
      } else {
        $hasTimes = !empty($rit['time_entries']);
        $modeFor  = $hasTimes ? 'auto' : ($rit['entry_mode'] ?? 'qty');
        if ($modeFor === 'auto') {
          $sumMin=0; foreach (($rit['time_entries']??[]) as $te) if (!empty($te['selected'])) $sumMin += (int)$te['minutes'];
          $n = ($sumMin/60.0) * $rateNum;
        } elseif ($modeFor === 'time') {
          $qty = (float)($rit['quantity'] ?? 0.0); // in Stunden
          $n = $qty * $rateNum;
        } else {
          $qty = (float)($rit['quantity'] ?? 0.0);
          $n = $qty * $rateNum;
        }
        $g = $n * (1 + $taxNum/100);
      }
      $projNet += $n; $projGross += $g;
    }
    $GRAND_NET += $projNet; $GRAND_GROSS += $projGross;

    if ($showHeads): ?>
      <tr class="inv-group-head" data-project="<?=$pid?>">
        <td colspan="6"><?=h($bucket['title'])?></td>
        <td class="text-end proj-net"><?= number_format($projNet, 2, ',', '.') ?></td>
        <td class="text-end proj-gross"><?= number_format($projGross, 2, ',', '.') ?></td>
        <td></td>
      </tr>
    <?php endif;

    foreach ($bucket['rows'] as $it) {
      $idx++;
      $desc      = $it['description'] ?? '';
      $rateNum   = (float)($it['hourly_rate'] ?? 0.0);
      $rate      = number_format($rateNum, 2, '.', '');
      $taxNum    = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0.0);
      $it_scheme = $it['tax_scheme'] ?? ($taxNum > 0 ? 'standard' : 'tax_exempt');
      $it_vat    = number_format($taxNum, 2, '.', '');

      $times     = $it['time_entries'] ?? [];
      $hasTimes  = !empty($times);
      $entryMode = $hasTimes ? 'auto' : ($it['entry_mode'] ?? 'qty'); // rückwärtskompatibel
      $quantity  = (float)($it['quantity'] ?? 0.0); // für qty/time (Stunden)

      // Anzeige-Defaults
      $sumMin=0; $sumHHM='00:00';
      if ($hasTimes) { foreach ($times as $te) if (!empty($te['selected'])) $sumMin+=(int)$te['minutes']; $sumHHM=_fmt_hhmm($sumMin); }

      // Berechnete Anzeige (DB-Werte bevorzugen)
      if (array_key_exists('total_net',$it) && array_key_exists('total_gross',$it)) {
        $netVal=(float)$it['total_net']; $grossVal=(float)$it['total_gross'];
      } else {
        if ($entryMode==='auto'){ $netVal = ($sumMin/60.0)*$rateNum; }
        else { $netVal = $quantity * $rateNum; }
        $grossVal = $netVal * (1 + $taxNum/100);
      }

      $dis    = !empty($canEditItems) ? '' : ' readonly ';
      $selDis = !empty($canEditItems) ? '' : ' disabled ';
      ?>
      <tr class="inv-item-row" data-row="<?=$idx?>" data-project="<?=$pid?>" data-mode="<?=$entryMode?>" aria-expanded="false">
        <td class="text-center">
          <?php if ($entryMode==='auto'): ?>
            <button type="button" class="inv-toggle-btn" aria-label="Details ein-/ausklappen">
              <svg class="chev" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          <?php endif; ?>
        </td>
        <td>
          <input type="hidden" name="items[<?=$idx?>][id]" value="<?= (int)$it['id'] ?>">
          <input type="hidden" name="items[<?=$idx?>][project_id]" value="<?=$pid?>">
          <input type="hidden" class="entry-mode" name="items[<?=$idx?>][entry_mode]" value="<?=$entryMode?>">
          <input type="text" class="form-control" name="items[<?=$idx?>][description]" value="<?=h($desc)?>" <?=$dis?>>
        </td>

        <!-- Zeit / Menge -->
        <td class="text-end">
          <?php if ($entryMode==='auto'): ?>
            <span class="sum-hhmm"><?=$sumHHM?></span>
            <input type="hidden" name="items[<?=$idx?>][sum_minutes]" value="<?=$sumMin?>" class="sum-minutes">
          <?php elseif ($entryMode==='time'): ?>
            <div class="d-flex gap-1 align-items-center justify-content-end">
              <input type="text" class="form-control text-end hours-input" style="max-width:120px"
                     name="items[<?=$idx?>][hours]" value="<?= _fmt_hours_from_dec($quantity) ?>" <?=$dis?>>
            </div>
            <input type="hidden" class="quantity-dec" name="items[<?=$idx?>][quantity]" value="<?= number_format($quantity,3,'.','') ?>">
          <?php else: /* qty */ ?>
            <input type="number" step="0.25" class="form-control text-end quantity"
                   name="items[<?=$idx?>][quantity]" value="<?= _fmt_qty($quantity) ?>" <?=$dis?>>
          <?php endif; ?>
        </td>

        <!-- Stundensatz / Einzelpreis -->
        <td class="text-end">
          <input type="number" step="0.01" class="form-control text-end rate"
                 name="items[<?=$idx?>][hourly_rate]" value="<?=$rate?>" <?=$dis?>>
        </td>

        <td class="text-end">
          <select name="items[<?=$idx?>][tax_scheme]" class="form-select inv-tax-sel"
                  data-rate-standard="19.00" data-rate-tax-exempt="0.00" data-rate-reverse-charge="0.00" <?=$selDis?>>
            <option value="standard"       <?= $it_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
            <option value="tax_exempt"     <?= $it_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
            <option value="reverse_charge" <?= $it_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
          </select>
        </td>
        <td class="text-end">
          <input type="number" step="0.01" min="0" max="100"
                 class="form-control text-end inv-vat-input"
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
      <tr class="inv-details" data-row="<?=$idx?>" data-project="<?=$pid?>">
        <td></td>
        <td colspan="8">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th style="width:42px"></th><th>Zeitraum</th><th class="text-end">HH:MM</th></tr></thead>
              <tbody>
                <?php foreach ($times as $te): $tid=(int)$te['id']; $m=(int)$te['minutes']; ?>
                  <tr>
                    <td><input class="form-check-input time-checkbox" type="checkbox"
                               name="items[<?=$idx?>][time_ids][]"
                               value="<?=$tid?>" <?=!empty($te['selected'])?'checked':''?> data-min="<?=$m?>" <?=$selDis?>></td>
                    <td><?=h(_fmt_dmy($te['started_at'] ?? ''))?> <?= isset($te['ended_at']) ? '– '.h(_fmt_dmy($te['ended_at'])) : '' ?></td>
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
  function toNumber(str){
    if (typeof str !== 'string') str = String(str ?? '');
    str = str.trim(); if (!str) return 0;
    if (str.indexOf(',') !== -1 && str.indexOf('.') !== -1) { str = str.replace(/\./g,'').replace(',', '.'); }
    else if (str.indexOf(',') !== -1) { str = str.replace(',', '.'); }
    const n = parseFloat(str); return isFinite(n) ? n : 0;
  }
  function fmt2(n){ return (n||0).toFixed(2).replace('.',','); }
  function hhmm(min){ min = Math.max(0, parseInt(min||0,10)); var h = Math.floor(min/60), m = min%60; return (h<10?'0':'')+h+':' + (m<10?'0':'')+m; }
  function parseHours(v){ v=String(v||'').trim(); if(v.includes(':')){ const [h,m='0']=v.split(':',2); return (parseInt(h||'0',10)||0) + (parseInt(m||'0',10)||0)/60; } return toNumber(v); }

  function recalcTotals(){
    var root = document.getElementById('invoice-items'); if (!root) return;
    var sumsByProject = {};
    root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
      var pid = tr.getAttribute('data-project') || '';
      var n = toNumber(tr.querySelector('.net')?.textContent || '0');
      var g = toNumber(tr.querySelector('.gross')?.textContent || '0');
      if (!sumsByProject[pid]) sumsByProject[pid] = {net:0,gross:0};
      sumsByProject[pid].net  += n; sumsByProject[pid].gross+= g;
    });
    Object.keys(sumsByProject).forEach(function(pid){
      var head = root.querySelector('.inv-group-head[data-project="'+pid+'"]'); if (!head) return;
      var ncell = head.querySelector('.proj-net'); var gcell = head.querySelector('.proj-gross');
      if (ncell) ncell.textContent = fmt2(sumsByProject[pid].net);
      if (gcell) gcell.textContent = fmt2(sumsByProject[pid].gross);
    });
    var gnet=0, ggross=0; Object.values(sumsByProject).forEach(function(s){ gnet+=s.net; ggross+=s.gross;});
    var gN = document.getElementById('grand-net'); var gG = document.getElementById('grand-gross');
    if (gN) gN.textContent = fmt2(gnet); if (gG) gG.textContent = fmt2(ggross);
  }

  function recalcRow(tr){
    var mode = tr.getAttribute('data-mode') || tr.querySelector('.entry-mode')?.value || 'auto';

    // AUTO: minutenbasiert (Times)
    if (mode === 'auto') {
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
      recalcTotals();
      return;
    }

    // MANUELL: time (Stunden) oder qty (Menge)
    var rate = toNumber(tr.querySelector('.rate')?.value||'0');
    var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');
    var qtyDec = 0;

    if (mode === 'time') {
      var hinp = tr.querySelector('.hours-input');
      qtyDec = parseHours(hinp ? hinp.value : '0');             // Stunden dezimal
      var qHidden = tr.querySelector('.quantity-dec');
      if (qHidden) qHidden.value = String(qtyDec.toFixed(3));   // server post
    } else { // qty
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
    document.querySelectorAll('.inv-tax-sel').forEach(function(sel){ if (sel.value !== 'standard') any = true; });
    wrap.style.display = any ? '' : 'none';
    var ta = document.getElementById('tax-exemption-reason'); if (!any && ta) ta.value = '';
  }

  // Mode-Switch (nur manuelle Zeilen)
  function switchMode(tr, newMode){
    if (!tr) return;
    var cur = tr.getAttribute('data-mode') || 'auto';
    if (cur === 'auto') return; // auto nicht umschaltbar
    tr.setAttribute('data-mode', newMode);
    var hidden = tr.querySelector('.entry-mode'); if (hidden) hidden.value = newMode;

    // UI anpassen
    var qty = tr.querySelector('.quantity');
    var hrs = tr.querySelector('.hours-input');
    var qhid= tr.querySelector('.quantity-dec');
    if (newMode === 'time') {
      if (!hrs) {
        // qty->time: baue Input um
        var td = qty.closest('td');
        qty.remove();
        var html = '<div class="d-flex gap-1 align-items-center justify-content-end">'
                 + '<input type="text" class="form-control text-end hours-input" style="max-width:120px" name="" value="00:00"></div>';
        td.insertAdjacentHTML('afterbegin', html);
        var hinp = td.querySelector('.hours-input');
        if (!qhid) { var h = document.createElement('input'); h.type='hidden'; h.className='quantity-dec'; td.appendChild(h); }
      }
    } else {
      if (!qty) {
        var td = hrs.closest('td');
        hrs.closest('div').remove();
        if (qhid) qhid.remove();
        var html = '<input type="number" step="0.25" class="form-control text-end quantity" value="1">';
        td.insertAdjacentHTML('afterbegin', html);
      }
    }

    // Buttons aktiv setzen
    var bt = tr.querySelector('.switch-time'); var bq = tr.querySelector('.switch-qty');
    if (bt) bt.classList.toggle('active', newMode==='time');
    if (bq) bq.classList.toggle('active', newMode==='qty');

    recalcRow(tr);
  }

  var root = document.getElementById('invoice-items'); if (!root) return;

  root.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (btn && root.contains(btn)) {
      var tr = btn.closest('tr.inv-item-row'); if (!tr) return;
      var row = tr.getAttribute('data-row');
      var det = document.querySelector('.inv-details[data-row="'+row+'"]'); if (!det) return;
      var open = det.style.display === 'table-row';
      det.style.display = open ? 'none' : 'table-row';
      tr.setAttribute('aria-expanded', String(!open));
      return;
    }

    var del = e.target.closest && e.target.closest('.btn-remove-item');
    if (del && root.contains(del)) {
      if (!confirm('Diese Position aus der Rechnung entfernen?')) return;
      var tr = del.closest('tr.inv-item-row'); if (!tr) return;
      var rowId = tr.getAttribute('data-row'); var pid = tr.getAttribute('data-project');
      var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
      if (idInput && idInput.value) {
        var trash = document.getElementById('invoice-hidden-trash');
        if (trash) { var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='items_deleted[]'; hidden.value=idInput.value; trash.appendChild(hidden); }
      }
      var det = document.querySelector('.inv-details[data-row="'+rowId+'"]'); if (det) det.remove();
      tr.remove();
      if (pid) {
        var still = document.querySelectorAll('.inv-item-row[data-project="'+pid+'"]').length;
        if (still === 0) { var head = document.querySelector('.inv-group-head[data-project="'+pid+'"]'); if (head) head.remove(); }
      }
      toggleTaxReason();
      recalcTotals();
      return;
    }

    // Mode-Switch
    var swTime = e.target.closest && e.target.closest('.switch-time');
    var swQty  = e.target.closest && e.target.closest('.switch-qty');
    if ((swTime || swQty) && root.contains(e.target)) {
      var tr = e.target.closest('tr.inv-item-row'); if (!tr) return;
      switchMode(tr, swTime ? 'time' : 'qty');
      return;
    }
  });

  root.addEventListener('change', function(e){
    var sel = e.target.closest && e.target.closest('.inv-tax-sel');
    if (sel && root.contains(sel)) { updateVatFromScheme(sel); toggleTaxReason(); return; }
    if (e.target.closest && e.target.closest('.time-checkbox')) {
      var row = e.target.closest('tr').closest('tbody').closest('table').closest('td').closest('tr').previousElementSibling;
      if (row && row.classList.contains('inv-item-row')) recalcRow(row);
      return;
    }
  });

  root.addEventListener('input', function(e){
    if (e.target.matches && (e.target.matches('.rate') || e.target.matches('.inv-vat-input') || e.target.matches('.quantity') || e.target.matches('.hours-input'))) {
      var tr = e.target.closest('tr.inv-item-row'); if (tr) recalcRow(tr);
    }
  });

  // Initial
  document.querySelectorAll('tr.inv-item-row').forEach(function(tr){
    var sel = tr.querySelector('.inv-tax-sel');
    var vat = tr.querySelector('.inv-vat-input');
    if (sel && vat && (vat.value === '' || vat.value === null)) updateVatFromScheme(sel);
    recalcRow(tr);
  });
  toggleTaxReason();
  recalcTotals();
})();
</script>