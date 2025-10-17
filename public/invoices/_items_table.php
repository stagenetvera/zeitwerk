<?php
/**
 * Reusable Items-Table for invoices (new & edit)
 *
 * NEW expects: $groups  (siehe Build-Struktur: project_id, project_title, rows[] mit time-entries)
 * EDIT expects: $items  (inkl. time_entries[] und project_title)
 */

// --- Kompatibilitäts-Header: nur Namensvariablen, keine Optik-Änderung ---
$mode      = $mode      ?? 'new';
$rowName   = $rowName   ?? ($mode === 'edit' ? 'items' : 'tasks');
$timesName = $timesName ?? ($mode === 'edit' ? 'times_selected' : 'time_ids');

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
        <th class="text-end w-90">Steuer&nbsp;%</th>
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
      <tr class="inv-group-head" data-project="<?=$pid?>"><td colspan="8"><?=h($g['project_title'])?></td></tr>
    <?php endif;

    foreach ($g['rows'] as $row) {
      $idx++;
      $desc   = $row['task_desc'];
      $rate   = number_format((float)$row['hourly_rate'], 2, ',', '');
      $tax    = number_format((float)$row['tax_rate'], 2, ',', '');
      $sumMin = array_sum(array_map(fn($t)=> (int)$t['minutes'], $row['times']));
      $sumHHM = _fmt_hhmm($sumMin);
      $net    = ($sumMin/60.0) * (float)$row['hourly_rate'];
      $gross  = $net * (1 + ((float)$row['tax_rate']/100));
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
          <input type="number" step="0.01" class="form-control text-end tax" name="items[<?=$idx?>][tax_rate]" value="<?=$tax?>">
        </td>
        <td class="text-end"><span class="net"><?=number_format($net,2,',','.')?></span></td>
        <td class="text-end"><span class="gross"><?=number_format($gross,2,',','.')?></span></td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
        </td>
      </tr>
      <tr class="inv-details" data-row="<?=$idx?>" data-project="<?=$pid?>">
        <td></td>
        <td colspan="7">
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
      <tr class="inv-group-head" data-project="<?=$pid?>"><td colspan="8"><?=h($bucket['title'])?></td></tr>
    <?php endif;

    foreach ($bucket['rows'] as $it) {
      $idx++;
      $desc   = $it['description'] ?? '';
      $rate   = number_format((float)$it['hourly_rate'], 2, ',', '');
      $tax    = number_format((float)$it['tax_rate'], 2, ',', '');
      $times  = $it['time_entries'] ?? [];
      $sumMin = 0;
      foreach ($times as $te) if (!empty($te['selected'])) $sumMin += (int)$te['minutes'];
      $sumHHM = _fmt_hhmm($sumMin);
      $net    = ($sumMin/60.0) * (float)$it['hourly_rate'];
      $gross  = $net * (1 + ((float)$it['tax_rate']/100));
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
          <input type="number" step="0.01" class="form-control text-end tax" name="items[<?=$idx?>][tax_rate]" value="<?=$tax?>">
        </td>
        <td class="text-end"><span class="net"><?=number_format($net,2,',','.')?></span></td>
        <td class="text-end"><span class="gross"><?=number_format($gross,2,',','.')?></span></td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Entfernen</button>
        </td>
      </tr>
      <tr class="inv-details" data-row="<?=$idx?>" data-project="<?=$pid?>">
        <td></td>
        <td colspan="7">
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
  function toNumber(x){ var n = typeof x==='string' ? x.replace(/\./g,'').replace(',', '.') : x; n = parseFloat(n); return isFinite(n)?n:0; }
  function fmt2(n){ return n.toFixed(2).replace('.',','); }
  function hhmm(min){ min = Math.max(0,parseInt(min||0,10)); var h=Math.floor(min/60), m=min%60; return (h<10?'0':'')+h+':' + (m<10?'0':'')+m; }

  function recalcRow(tr){
    var minutes = 0;
    var rowId = tr.getAttribute('data-row');
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
    var tax  = toNumber(tr.querySelector('.tax')?.value||'0');
    var net  = (minutes/60.0) * rate;
    var gross= net * (1 + tax/100);

    var hh = tr.querySelector('.sum-hhmm'); if (hh) hh.textContent = hhmm(minutes);
    var smi= tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
    var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
    var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
  }

  // Expand/Collapse
  (function(){
  function toNumber(x){ var n = typeof x==='string' ? x.replace(/\./g,'').replace(',', '.') : x; n = parseFloat(n); return isFinite(n)?n:0; }
  function fmt2(n){ return n.toFixed(2).replace('.',','); }
  function hhmm(min){ min = Math.max(0,parseInt(min||0,10)); var h=Math.floor(min/60), m=min%60; return (h<10?'0':'')+h+':' + (m<10?'0':'')+m; }

  function recalcRow(tr){
    var minutes = 0;
    var rowId = tr.getAttribute('data-row');
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
    var tax  = toNumber(tr.querySelector('.tax')?.value||'0');
    var net  = (minutes/60.0) * rate;
    var gross= net * (1 + tax/100);

    var hh = tr.querySelector('.sum-hhmm'); if (hh) hh.textContent = hhmm(minutes);
    var smi= tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
    var nspan = tr.querySelector('.net'); if (nspan) nspan.textContent = fmt2(net);
    var gspan = tr.querySelector('.gross'); if (gspan) gspan.textContent = fmt2(gross);
  }

  /* NEU: schöner Toggle */
  document.querySelectorAll('.inv-toggle-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr.inv-item-row'); if (!tr) return;
      var row = tr.getAttribute('data-row');
      var det = document.querySelector('.inv-details[data-row="'+row+'"]'); if (!det) return;
      var open = det.style.display === 'table-row';
      det.style.display = open ? 'none' : 'table-row';
      tr.setAttribute('aria-expanded', String(!open));
    });
  });

  // (Rest wie gehabt)
  document.querySelectorAll('.time-checkbox').forEach(function(cb){
    cb.addEventListener('change', function(){
      var tr = cb.closest('tr').closest('tbody').closest('table').closest('td').closest('tr').previousElementSibling;
      if (tr && tr.classList.contains('inv-item-row')) recalcRow(tr);
    });
  });
  document.querySelectorAll('.rate, .tax').forEach(function(inp){
    inp.addEventListener('input', function(){
      var tr = inp.closest('tr.inv-item-row');
      if (tr) recalcRow(tr);
    });
  });

  // Entfernen-Button + Projekt-Header bereinigen bleibt unverändert …
  document.getElementById('invoice-items').addEventListener('click', function(e){
    var btn = e.target.closest('.btn-remove-item');
    if (!btn) return;
    if (!confirm('Diese Position aus der Rechnung entfernen?')) return;

    var tr = btn.closest('tr.inv-item-row'); if (!tr) return;
    var rowId = tr.getAttribute('data-row');
    var pid   = tr.getAttribute('data-project');

    var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
    if (idInput && idInput.value) {
      var trash = document.getElementById('invoice-hidden-trash');
      var hidden = document.createElement('input');
      hidden.type = 'hidden'; hidden.name = 'items_deleted[]'; hidden.value = idInput.value;
      trash.appendChild(hidden);
    }

    var det = document.querySelector('.inv-details[data-row="'+rowId+'"]');
    if (det) det.remove();
    tr.remove();

    if (pid) {
      var still = document.querySelectorAll('.inv-item-row[data-project="'+pid+'"]').length;
      if (still === 0) {
        var head = document.querySelector('.inv-group-head[data-project="'+pid+'"]');
        if (head) head.remove();
      }
    }
  });

  document.querySelectorAll('tr.inv-item-row').forEach(recalcRow);
})();

  // Recalc bei Änderung Minuten-Auswahl / Rate / Steuer
  document.querySelectorAll('.time-checkbox').forEach(function(cb){
    cb.addEventListener('change', function(){
      var tr = cb.closest('tr').closest('tbody').closest('table').closest('td').closest('tr').previousElementSibling;
      if (tr && tr.classList.contains('inv-item-row')) recalcRow(tr);
    });
  });
  document.querySelectorAll('.rate, .tax').forEach(function(inp){
    inp.addEventListener('input', function(){
      var tr = inp.closest('tr.inv-item-row');
      if (tr) recalcRow(tr);
    });
  });

  // Entfernen-Button (ganze Position) + leeren Projekt-Header beseitigen
  document.getElementById('invoice-items').addEventListener('click', function(e){
    var btn = e.target.closest('.btn-remove-item');
    if (!btn) return;
    if (!confirm('Diese Position aus der Rechnung entfernen?')) return;

    var tr = btn.closest('tr.inv-item-row'); if (!tr) return;
    var rowId = tr.getAttribute('data-row');
    var pid   = tr.getAttribute('data-project');

    // Falls EDIT: Item-ID merken
    var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
    if (idInput && idInput.value) {
      var trash = document.getElementById('invoice-hidden-trash');
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'items_deleted[]';
      hidden.value = idInput.value;
      trash.appendChild(hidden);
    }

    // Details-Zeile entfernen
    var det = document.querySelector('.inv-details[data-row="'+rowId+'"]');
    if (det) det.remove();

    // Item-Zeile entfernen
    tr.remove();

    // Prüfen, ob für dieses Projekt noch Items existieren – wenn nein: Header löschen
    if (pid) {
      var still = document.querySelectorAll('.inv-item-row[data-project="'+pid+'"]').length;
      if (still === 0) {
        var head = document.querySelector('.inv-group-head[data-project="'+pid+'"]');
        if (head) head.remove();
      }
    }
  });

  // Initiale Kalkulation
  document.querySelectorAll('tr.inv-item-row').forEach(recalcRow);
})();
</script>