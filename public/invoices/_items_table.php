<?php
/**
 * Erwartet:
 * - $mode        : 'new'|'edit'
 * - $groups      : [ ['rows' => [ taskRows... ] ] ] (nur in new)
 * - $items       : [ itemRows... ] (nur in edit)
 * - $rowName     : Feldname der Items, z.B. 'items'
 * - $timesName   : Feldname für time_ids, z.B. 'time_ids'
 * - $ROUND_UNIT_MINS : int (Rundung)
 * - $DEFAULT_SCHEME, $DEFAULT_TAX | (Fallback auf $DEFAULT_VAT)
 * - optional $ri_key_by_desc[description] => key  (für Edit; Recurring-Ledger)
 *
 * Struktur eines taskRows (new):
 * [
 *   'task_id'              => int,
 *   'task_desc'            => string,
 *   'hourly_rate'          => float,
 *   'tax_rate'             => float,
 *   'billing_mode'         => 'time'|'fixed',
 *   'fixed_price_cents'    => int,
 *   'billed_in_invoice_id' => int|null,
 *   'billed_invoice_status'=> 'in_vorbereitung'|'gestellt'|'bezahlt'|'storniert'|null,
 *   'times'                => [ ['id'=>int,'minutes'=>int,'started_at'=>..., 'ended_at'=>...], ... ],
 *   'minutes_sum'          => int (bereits gerundet nach $ROUND_UNIT_MINS)
 * ]
 *
 * Struktur eines itemRows (edit):
 * [
 *   'id'           => int,
 *   'task_id'      => int|null,
 *   'description'  => string,
 *   'hourly_rate'  => float,
 *   'vat_rate'     => float,
 *   'tax_scheme'   => 'standard'|'tax_exempt'|'reverse_charge',
 *   'quantity'     => float,
 *   'entry_mode'   => 'fixed'|'auto'|'time'|'qty',
 *   'total_net'    => float|null,
 *   'time_entries' => [ ['id'=>int,'minutes'=>int,'started_at'=>..., 'ended_at'=>..., 'selected'=>bool], ... ],
 * ]
 */


function _fmt_money($v){ return number_format((float)$v, 2, ',', '.'); } // Anzeige (Spans)
function _fmt_money_input($v){ return number_format((float)$v, 2, '.',  ''); } // Inputs: KEINE Gruppierung


$__DEFAULT_SCHEME = isset($DEFAULT_SCHEME) ? (string)$DEFAULT_SCHEME : 'standard';
$__DEFAULT_TAX    = isset($DEFAULT_TAX) ? (float)$DEFAULT_TAX : (isset($DEFAULT_VAT) ? (float)$DEFAULT_VAT : 19.0);
$__ROUND_UNIT     = isset($ROUND_UNIT_MINS) ? (int)$ROUND_UNIT_MINS : 0;
$allow_edit = isset($allow_edit) ? (bool)$allow_edit : true;
$recurring_items_prefill = isset($recurring_items_prefill) ? (array)$recurring_items_prefill : [];

$theadCols = [
  '',            // toggler/drag-handle
  'Bezeichnung',
  'Zeit / Menge',
  'Satz / Preis',
  'Steuerart',
  'MwSt %',
  'Netto',
  'Brutto',
  '',            // Aktionen
];

// kleine Helfer
function _minutes_to_hhmm($min){ $min=max(0,(int)$min); $h=floor($min/60); $m=$min%60; return sprintf('%02d:%02d',$h,$m); }
function _sum_minutes_checked($timeEntries){
  $sum=0;
  foreach ((array)$timeEntries as $t) {
    if (!isset($t['selected']) || $t['selected']) $sum += (int)($t['minutes'] ?? 0);
  }
  return $sum;
}
function _render_time_row_generic($rowName, $rowIndex, $t, $checked=true){
  global  $allow_edit;
  $tid = (int)($t['id'] ?? 0);
  $min = (int)($t['minutes'] ?? 0);
  $st  = _fmt_dmy($t['started_at'] ?? null);
  $en  = _fmt_dmy($t['ended_at']   ?? null);
  ?>
  <tr class="time-row" draggable="true" data-time-id="<?= $tid ?>">
    <td>
      <input class="form-check-input time-checkbox"
             type="checkbox"
             name="<?= $rowName.'['.$rowIndex.'][time_ids][]' ?>"
             value="<?= $tid ?>" <?= $checked ? 'checked' : '' ?>
             data-min="<?= (int)$min ?>"
             <?= $allow_edit ? '' : 'disabled' ?>>
    </td>
    <td><?= htmlspecialchars($st) ?> – <?= htmlspecialchars($en) ?></td>
    <td class="text-end"><?= (int)$min ?> min</td>
  </tr>
  <?php
}

?>
<div id="invoice-items" class="table-responsive" data-round-unit="<?= (int)$__ROUND_UNIT ?>" data-locked="<?= $allow_edit ? '0' : '1' ?>">
  <table class="table table-sm align-middle mb-0">
    <thead>
      <tr>
        <th style="width:36px"></th>
        <th>Bezeichnung</th>
        <th class="text-end" style="width:160px">Zeit / Menge</th>
        <th class="text-end" style="width:150px">Satz / Preis</th>
        <th class="text-end" style="width:150px">Steuerart</th>
        <th class="text-end" style="width:120px">MwSt %</th>
        <th class="text-end" style="width:140px">Netto</th>
        <th class="text-end" style="width:120px"></th>
      </tr>
    </thead>
    <tbody>
<?php
$rowIndex = 0;

/* =========================
 * EDIT-MODUS: aus $items
 * ========================= */
if (($mode ?? 'new') === 'edit'):

  foreach ((array)($items ?? []) as $it):
    $rowIndex++;

    $itemId   = (int)($it['id'] ?? 0);
    $desc     = (string)($it['description'] ?? '');
    $modeVal  = strtolower((string)($it['entry_mode'] ?? 'qty'));
    if (!in_array($modeVal, ['fixed','auto','time','qty'], true)) $modeVal = 'qty';

    $rate     = (float)($it['hourly_rate'] ?? 0.0);
    $scheme   = (string)($it['tax_scheme'] ?? $__DEFAULT_SCHEME);
    $vat      = ($scheme === 'standard') ? (float)($it['vat_rate'] ?? $__DEFAULT_TAX) : 0.0;
    $qtyIn    = (float)($it['quantity'] ?? 0.0);

    // Zeiten für auto/fixed
    $timeEntries = (array)($it['time_entries'] ?? []);
    $minutesSum  = _sum_minutes_checked($timeEntries);
    if ($modeVal === 'auto' && $__ROUND_UNIT > 0 && $minutesSum > 0) {
      $minutesSum = (int)(ceil($minutesSum / $__ROUND_UNIT) * $__ROUND_UNIT);
    }
    $hhmm = _minutes_to_hhmm($minutesSum);

    // Menge für Anzeige bestimmen (für Input-Felder)
    if ($modeVal === 'fixed') {
      $qty = 1.0;
    } elseif ($modeVal === 'auto') {
      $qty = round($minutesSum / 60.0, 3);
    } elseif ($modeVal === 'time') {
      $qty = round($qtyIn, 3);
    } else { // qty
      $qty = round($qtyIn, 3);
    }

    // Netto-Anzeige bevorzugt aus DB übernehmen
    $net = isset($it['total_net']) ? (float)$it['total_net'] : null;

    // Fallback, falls alte Rechnungen noch kein total_net haben
    if ($net === null) {
      if ($modeVal === 'fixed') {
        $net = round($rate * 1.0, 2);
      } elseif ($modeVal === 'auto') {
        $net = round(($minutesSum / 60.0) * $rate, 2);
      } elseif ($modeVal === 'time') {
        $net = round($qtyIn * $rate, 2);
      } else {
        $net = round($qtyIn * $rate, 2);
      }
    }

    // Recurring-Key (falls übergeben)
    $ri_key = isset($ri_key_by_desc[$desc]) ? (string)$ri_key_by_desc[$desc] : '';

    ?>
    <tr class="inv-item-row"
        data-row="<?= $rowIndex ?>"
        data-mode="<?= htmlspecialchars($modeVal) ?>"
        aria-expanded="false">
      <td class="text-center">
        <div class="d-flex justify-content-center gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary inv-toggle-btn" title="Details ein/aus">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
            <span class="visually-hidden">Details umschalten</span>
          </button>
          <span class="row-reorder-handle" <?= $allow_edit ? 'draggable="true"' : '' ?> aria-label="Position verschieben" title="Ziehen zum Sortieren">
            <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
              <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8ZM9 8h2v2H9V8ZM5 12h2v2H5v-2ZM9 12h2v2H9v-2Z" fill="currentColor"/>
            </svg>
          </span>
        </div>
        <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][id]' ?>" value="<?= (int)$itemId ?>">
        <input type="hidden" class="entry-mode" name="<?= $rowName.'['.$rowIndex.'][entry_mode]' ?>" value="<?= htmlspecialchars($modeVal) ?>">
        <?php if ($ri_key !== ''): ?>
          <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][ri_key]' ?>" value="<?= h($ri_key) ?>">
        <?php endif; ?>
        <?php if (in_array($modeVal, ['auto','fixed'], true)): ?>
          <input type="hidden" class="sum-minutes" name="<?= $rowName.'['.$rowIndex.'][sum_minutes]' ?>" value="<?= (int)$minutesSum ?>">
        <?php endif; ?>
      </td>

      <td>
        <input type="text" class="form-control form-control-sm"
               name="<?= $rowName.'['.$rowIndex.'][description]' ?>"
               value="<?= htmlspecialchars($desc) ?>"
               <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <?php if ($modeVal === 'fixed'): ?>
          <div class="form-control-plaintext text-end">
            1 <span class="text-muted">(<?= $hhmm ?>)</span>
          </div>
          <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>" value="1">
        <?php elseif ($modeVal === 'auto'): ?>
          <div class="form-control-plaintext text-end">
            <span class="sum-hhmm"><?= $hhmm ?></span>
          </div>
          <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>" value="<?= _fmt_qty($qty) ?>">
        <?php elseif ($modeVal === 'time'): ?>
          <!-- Für Zeit-Modus zeigen wir (vorerst) nur ein Dezimalfeld; das Edit-Skript in edit.php ergänzt den Icon-Toggle und HH:MM -->
          <input type="number" class="form-control form-control-sm text-end quantity no-spin"
                 name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>"
                 value="<?= _fmt_qty($qty) ?>" min="0" <?php if (!$allow_edit): ?>disabled<?php endif;?>>
        <?php else: /* qty */ ?>
          <input type="number" class="form-control form-control-sm text-end quantity no-spin"
                 name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>"
                 value="<?= _fmt_qty($qty) ?>" min="0" <?php if (!$allow_edit): ?>disabled<?php endif;?>>
        <?php endif; ?>
      </td>

      <td class="text-end">
        <input type="number" class="form-control form-control-sm text-end rate no-spin"
               name="<?= $rowName.'['.$rowIndex.'][hourly_rate]' ?>"
               value="<?= _fmt_money_input($rate) ?>" min="0" <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <select name="<?= $rowName.'['.$rowIndex.'][tax_scheme]' ?>" class="form-select form-select-sm inv-tax-sel"
                data-rate-standard="<?= number_format($__DEFAULT_TAX,2,'.','') ?>"
                data-rate-tax-exempt="0.00"
                data-rate-reverse-charge="0.00"
                <?php if (!$allow_edit): ?>disabled<?php endif;?>>
          <option value="standard" <?= $scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt" <?= $scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
      </td>

      <td class="text-end">
        <input type="number" min="0" max="100" step="0.01"
               class="form-control form-control-sm text-end inv-vat-input no-spin"
               name="<?= $rowName.'['.$rowIndex.'][vat_rate]' ?>"
               value="<?= number_format($vat,2,'.','') ?>"
               <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <span class="net"><?= _fmt_money($net) ?></span>
      </td>

      <td class="text-end text-nowrap">
        <?php if ($allow_edit): ?>
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">
            <i class="bi bi-trash"></i>
          </button>
        <?php endif; ?>
      </td>
    </tr>

    <tr class="inv-details" data-row="<?= $rowIndex ?>" style="display:none">
      <td></td>
      <td colspan="7">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:40px"></th>
                <th>Zeiteintrag</th>
                <th class="text-end" style="width:120px">Minuten</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($timeEntries as $t) { _render_time_row_generic($rowName, $rowIndex, $t, (!isset($t['selected']) || $t['selected'])); } ?>
            </tbody>
          </table>
        </div>
      </td>
    </tr>
  <?php
  endforeach;

/* =========================
 * NEW-MODUS: aus $groups
 * ========================= */
else:

  function render_time_row_new($rowName, $rowIndex, $t){
    _render_time_row_generic($rowName, $rowIndex, $t, true);
  }

  foreach ((array)($groups[0]['rows'] ?? []) as $taskRow):
    $rowIndex++;

    $isFixed    = (($taskRow['billing_mode'] ?? '') === 'fixed');
    $taskId     = (int)$taskRow['task_id'];
    $minutesSum = (int)$taskRow['minutes_sum'];
    $qtyHours   = max(0, $minutesSum) / 60.0; // nur Anzeige / auto

    $scheme = $__DEFAULT_SCHEME;
    if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) {
      $scheme = 'standard';
    }
    $vat    = ($scheme === 'standard') ? (float)$__DEFAULT_TAX : 0.0;

    // Soft-Lock / Already billed UI (Info)
    $anchorId         = (int)($taskRow['billed_in_invoice_id'] ?? 0);
    $anchorStatus     = $taskRow['billed_invoice_status'] ?? null;
    $isLockedToDraft  = $isFixed && $anchorId && ($anchorStatus === 'in_vorbereitung');
    $isAlreadyBilled  = $isFixed && $anchorId && in_array($anchorStatus, ['gestellt','bezahlt'], true);

    $rowMode = $isFixed ? 'fixed' : 'auto';

    // Fixpreis in Dezimal
    $fixedPriceDec = ((int)($taskRow['fixed_price_cents'] ?? 0)) / 100.0;

    // Wenn bereits an ENTWURF gekoppelt → Preis in dieser neuen Rechnung = 0.00
    $rate = $isFixed
      ? (($isLockedToDraft || $isAlreadyBilled) ? 0.00 : $fixedPriceDec)
      : (float)($taskRow['hourly_rate'] ?? 0.0);

    // Netto/Brutto (fixed: Menge = 1, Preis = $rate)
    if ($isFixed) $net = $rate; else $net = $qtyHours * $rate;

    $hh = _minutes_to_hhmm($minutesSum);
    ?>
    <tr class="inv-item-row"
        data-row="<?= $rowIndex ?>"
        data-mode="<?= $rowMode ?>"
        data-fixed="<?= $isFixed ? '1' : '0' ?>"
        aria-expanded="false">
      <td class="text-center">
        <div class="d-flex justify-content-center gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary inv-toggle-btn" title="Details ein/aus">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
            <span class="visually-hidden">Details umschalten</span>
          </button>
          <span class="row-reorder-handle" draggable="true" aria-label="Position verschieben" title="Ziehen zum Sortieren">
            <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
              <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8ZM9 8h2v2H9V8ZM5 12h2v2H5v-2ZM9 12h2v2H9v-2Z" fill="currentColor"/>
            </svg>
          </span>
        </div>
        <input type="hidden" class="entry-mode" name="<?= $rowName.'['.$rowIndex.'][entry_mode]' ?>" value="<?= $rowMode ?>">
        <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][task_id]' ?>" value="<?= $taskId ?>">
        <input type="hidden" class="sum-minutes" name="<?= $rowName.'['.$rowIndex.'][sum_minutes]' ?>" value="<?= (int)$minutesSum ?>">
      </td>

      <td>
        <input type="text" class="form-control form-control-sm"
               name="<?= $rowName.'['.$rowIndex.'][description]' ?>"
               value="<?= htmlspecialchars($taskRow['task_desc'] ?? '') ?>">
        <?php if ($isFixed && $isAlreadyBilled): ?>
          <div class="text-muted small mt-1">
            Bereits abgerechnet in Rechnung #<?= (int)$anchorId ?> — Betrag 0,00&nbsp;€
          </div>
        <?php elseif ($isFixed && !$anchorId): ?>
          <div class="text-muted small mt-1">
            Fixpreis (<?= _fmt_money($rate) ?> €)
          </div>
        <?php elseif ($isFixed && $isLockedToDraft): ?>
          <div class="text-warning small mt-1">
            Bereits in Entwurf #<?= (int)$anchorId ?> — dieser Task ist vorläufig gesperrt
          </div>
        <?php endif; ?>
      </td>

      <td class="text-end">
        <?php if ($isFixed): ?>
          <div class="form-control-plaintext text-end">
            1 <span class="text-muted">(<?= $hh ?>)</span>
          </div>
          <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>" value="1.000">
        <?php else: ?>
          <div class="form-control-plaintext text-end">
            <span class="sum-hhmm"><?= $hh ?></span>
          <?php endif; ?>
      </td>

      <td class="text-end">
        <input type="number" class="form-control form-control-sm text-end rate no-spin"
               name="<?= $rowName.'['.$rowIndex.'][hourly_rate]' ?>"
               value="<?= _fmt_money_input($rate) ?>" min="0" <?= ($isFixed && $isAlreadyBilled)?'disabled':'' ?>>
      </td>

      <td class="text-end">
        <select name="<?= $rowName.'['.$rowIndex.'][tax_scheme]' ?>" class="form-select form-select-sm inv-tax-sel"
                data-rate-standard="<?= number_format($__DEFAULT_TAX,2,'.','') ?>"
                data-rate-tax-exempt="0.00"
                data-rate-reverse-charge="0.00">
          <option value="standard" <?= $scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt" <?= $scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
      </td>

      <td class="text-end">
        <input type="number" min="0" max="100" step="0.01"
               class="form-control form-control-sm text-end inv-vat-input no-spin"
               name="<?= $rowName.'['.$rowIndex.'][vat_rate]' ?>"
               value="<?= number_format((float)$vat,2,'.','') ?>">
      </td>

      <td class="text-end"><span class="net"><?= _fmt_money($net) ?></span></td>

      <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">
          <i class="bi bi-trash"></i>
        </button>
      </td>
    </tr>

    <tr class="inv-details" data-row="<?= $rowIndex ?>" style="display:none">
      <td></td>
      <td colspan="7">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:40px"></th>
                <th>Zeiteintrag</th>
                <th class="text-end" style="width:120px">Minuten</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ((array)($taskRow['times'] ?? []) as $t) { render_time_row_new($rowName, $rowIndex, $t); } ?>
            </tbody>
          </table>
        </div>
      </td>
    </tr>
  <?php
  endforeach;

  // Wiederkehrende Items (als manuelle qty-Positionen)
  foreach ((array)$recurring_items_prefill as $ri):
    $rowIndex++;
    $desc   = (string)($ri['description'] ?? '');
    $qty    = round((float)($ri['quantity'] ?? 0.0), 3);
    $rate   = (float)($ri['hourly_rate'] ?? 0.0);
    $scheme = (string)($ri['tax_scheme'] ?? $__DEFAULT_SCHEME);
    if (!in_array($scheme, ['standard','tax_exempt','reverse_charge'], true)) $scheme = 'standard';
    $vat    = ($scheme === 'standard') ? (float)($ri['vat_rate'] ?? $__DEFAULT_TAX) : 0.0;
    $net    = round($qty * $rate, 2);
    $ri_key = isset($ri['ri_key']) ? (string)$ri['ri_key'] : '';
    ?>
    <tr class="inv-item-row"
        data-row="<?= $rowIndex ?>"
        data-mode="qty"
        aria-expanded="false">
      <td class="text-center">
        <div class="d-flex justify-content-center gap-1">
          <span class="row-reorder-handle" draggable="true" aria-label="Position verschieben" title="Ziehen zum Sortieren">
            <svg class="grip" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
              <path d="M5 4h2v2H5V4Zm4 0h2v2H9V4ZM5 8h2v2H5V8ZM9 8h2v2H9V8ZM5 12h2v2H5v-2ZM9 12h2v2H9v-2Z" fill="currentColor"/>
            </svg>
          </span>
        </div>
        <input type="hidden" class="entry-mode" name="<?= $rowName.'['.$rowIndex.'][entry_mode]' ?>" value="qty">
        <?php if ($ri_key !== ''): ?>
          <input type="hidden" name="<?= $rowName.'['.$rowIndex.'][ri_key]' ?>" value="<?= htmlspecialchars($ri_key, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
      </td>

      <td>
        <input type="text" class="form-control form-control-sm"
               name="<?= $rowName.'['.$rowIndex.'][description]' ?>"
               value="<?= htmlspecialchars($desc) ?>"
               <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <input type="number" class="form-control form-control-sm text-end quantity no-spin"
               name="<?= $rowName.'['.$rowIndex.'][quantity]' ?>"
               value="<?= _fmt_qty($qty) ?>" min="0" step="0.001" <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <input type="number" class="form-control form-control-sm text-end rate no-spin"
               name="<?= $rowName.'['.$rowIndex.'][hourly_rate]' ?>"
               value="<?= _fmt_money_input($rate) ?>" min="0" <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end">
        <select name="<?= $rowName.'['.$rowIndex.'][tax_scheme]' ?>" class="form-select form-select-sm inv-tax-sel"
                data-rate-standard="<?= number_format($__DEFAULT_TAX,2,'.','') ?>"
                data-rate-tax-exempt="0.00"
                data-rate-reverse-charge="0.00"
                <?php if (!$allow_edit): ?>disabled<?php endif;?>>
          <option value="standard" <?= $scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt" <?= $scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
      </td>

      <td class="text-end">
        <input type="number" min="0" max="100" step="0.01"
               class="form-control form-control-sm text-end inv-vat-input no-spin"
               name="<?= $rowName.'['.$rowIndex.'][vat_rate]' ?>"
               value="<?= number_format($vat,2,'.','') ?>"
               <?php if (!$allow_edit): ?>disabled<?php endif;?>>
      </td>

      <td class="text-end"><span class="net"><?= _fmt_money($net) ?></span></td>

      <td class="text-end text-nowrap">
        <?php if ($allow_edit): ?>
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">
            <i class="bi bi-trash"></i>
          </button>
        <?php endif; ?>
      </td>
    </tr>
  <?php
  endforeach;

endif; // end edit/new
?>

      <!-- Grand total -->
      <tr class="inv-grand-total">
        <td></td>
        <td class="text-end fw-bold" colspan="5">Gesamt</td>
        <td class="text-end fw-bold"><span id="grand-net">0,00</span></td>
        <td></td>
      </tr>
    </tbody>
  </table>

  <!-- Hidden containers for reindex + deletions + order -->
  <div id="invoice-hidden-trash" class="d-none"></div>
  <div id="invoice-order-tracker" class="d-none"></div>
</div>

<!-- --- JS: Toggle, Recalc, DnD, "+ Position" (manuell) --- -->
<script>
(function(){
  var root = document.getElementById('invoice-items');
  if (!root) return;

  var roundUnit = parseInt(root.getAttribute('data-round-unit') || '0', 10) || 0;
  var locked    = (root.getAttribute('data-locked') === '1');

  /* ===== Helpers ===== */
  function getDetailsRowByMain(mainTr){
    if (!mainTr) return null;
    var det = mainTr.nextElementSibling;
    if (!det || !det.classList.contains('inv-details') || det.getAttribute('data-row') !== mainTr.getAttribute('data-row')) {
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
    return det ? (det.querySelector('tbody') || det) : null;
  }
  function getAfterPairNode(refMain){
    var next = refMain.nextElementSibling;
    if (next && next.classList.contains('inv-details') && next.getAttribute('data-row') === refMain.getAttribute('data-row')) {
      return next.nextElementSibling;
    }
    return next;
  }
  function toNumber(str){
    if (typeof str !== 'string') str = String(str ?? '');
    str = str.trim(); if (!str) return 0;
    if (str.indexOf(',') !== -1 && str.indexOf('.') !== -1) { str = str.replace(/\./g,'').replace(',', '.'); }
    else if (str.indexOf(',') !== -1) { str = str.replace(',', '.'); }
    var n = parseFloat(str); return isFinite(n) ? n : 0;
  }
  function fmt2(n){ return (n||0).toFixed(2).replace('.',','); }
  function hhmm(min){
    min = Math.max(0, parseInt(min||0,10));
    var h = Math.floor(min/60), m = min%60;
    return (h<10?'0':'')+h+':' + (m<10?'0':'')+m;
  }
  function parseHoursToDecimal(v){
    v = String(v||'').trim();
    if (v.includes(':')) {
      var p=v.split(':'), h=parseInt(p[0]||'0',10)||0, m=parseInt(p[1]||'0',10)||0;
      return h + (m/60);
    }
    var n = parseFloat(v.replace(',','.')); return isFinite(n) ? n : 0;
  }

  function roundHalfUp(value, places) {
    var factor = Math.pow(10, places);
    if (value >= 0) {
      return Math.floor(value * factor + 0.5) / factor;
    }
    return Math.ceil(value * factor - 0.5) / factor;
  }

  /* ===== Recalc ===== */
  function recalcTotals(){
    var gnet    = 0;
    var vatMap  = {}; // key: "19.00" -> Nettosumme
    var totalVat = 0;

    root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
      var netVal = toNumber(tr.querySelector('.net')?.textContent || '0');
      gnet += netVal;

      var scheme = (tr.querySelector('.inv-tax-sel')?.value || 'standard').toLowerCase();
      var rate   = toNumber(tr.querySelector('.inv-vat-input')?.value || '0');

      // Nur Standard-Steuersätze mit > 0 % berücksichtigen
      if (scheme === 'standard' && rate > 0) {
        var key = rate.toFixed(2);    // z.B. "19.00"
        if (!vatMap[key]) vatMap[key] = 0;
        vatMap[key] += netVal;
      }
    });

    gnet = roundHalfUp(gnet, 2);

    var gN = document.getElementById('grand-net');
    if (gN) gN.textContent = fmt2(gnet);

    // Alte MwSt-/Brutto-Zeilen entfernen
    root.querySelectorAll('tr.vat-summary-row, tr.grand-gross-row').forEach(function(r){
      r.parentNode && r.parentNode.removeChild(r);
    });

    var grandRow = root.querySelector('tr.inv-grand-total');
    if (!grandRow) return;
    var tbody = grandRow.parentNode;
    var insertRef = grandRow.nextSibling; // wir fügen NACH der Netto-Gesamtzeile ein

    // MwSt-Zeilen pro Satz
    Object.keys(vatMap).sort(function(a, b){
      return parseFloat(a) - parseFloat(b);
    }).forEach(function(key){
      var rate    = parseFloat(key);
      var netSum  = vatMap[key];
      var vatAmt  = roundHalfUp(netSum * rate / 100.0, 2);
      totalVat   += vatAmt;

      var tr = document.createElement('tr');
      tr.className = 'vat-summary-row';
      tr.innerHTML =
        '<td></td>' +
        '<td class="text-end" colspan="5">MwSt ' + rate.toString().replace('.', ',') + ' %</td>' +
        '<td class="text-end">' + fmt2(vatAmt) + '</td>' +
        '<td></td>';

      tbody.insertBefore(tr, insertRef);
    });

    // Brutto-Gesamt unter den MwSt-Zeilen
    var totalGross = roundHalfUp(gnet + totalVat, 2);
    var grossRow = document.createElement('tr');
    grossRow.className = 'grand-gross-row fw-bold';
    grossRow.innerHTML =
      '<td></td>' +
      '<td class="text-end" colspan="5">Brutto gesamt</td>' +
      '<td class="text-end">' + fmt2(totalGross) + '</td>' +
      '<td></td>';

    tbody.insertBefore(grossRow, insertRef);
  }

  function recalcRow(tr){
    var mode = (tr.getAttribute('data-mode') || tr.querySelector('.entry-mode')?.value || 'auto').toLowerCase();
    var rate = toNumber(tr.querySelector('.rate')?.value||'0');
    var vat  = toNumber(tr.querySelector('.inv-vat-input')?.value||'0');

    if (mode === 'auto' || mode === 'fixed') {
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
      if (roundUnit > 0 && minutes > 0 && mode === 'auto') {
        minutes = Math.ceil(minutes / roundUnit) * roundUnit;
      }

      if (mode === 'auto') {
        var net   = (minutes/60.0) * rate;
        var hh    = tr.querySelector('.sum-hhmm');  if (hh)  hh.textContent = hhmm(minutes);
        var smi   = tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
        var nspan = tr.querySelector('.net');      if (nspan) nspan.textContent = fmt2(net);
      } else { // fixed
        var hhInfo = tr.querySelector('.form-control-plaintext .text-muted');
        if (hhInfo) hhInfo.textContent = '('+hhmm(minutes)+')';
        var smi   = tr.querySelector('.sum-minutes'); if (smi) smi.value = String(minutes);
        var net   = rate * 1.0;
        var nspan = tr.querySelector('.net');      if (nspan) nspan.textContent = fmt2(net);
      }
      recalcTotals();
      return;
    }

    // Manuell
    var qtyDec = 0;
    if (mode === 'time') {
      var hin = tr.querySelector('.hours-input');
      qtyDec = parseHoursToDecimal(hin ? hin.value : '0');
      var qHidden = tr.querySelector('.quantity-dec');
      if (qHidden) qHidden.value = (qtyDec||0).toFixed(3);
    } else {
      qtyDec = toNumber(tr.querySelector('.quantity')?.value||'0');
    }
    var net   = qtyDec * rate;
    var nspan = tr.querySelector('.net');   if (nspan) nspan.textContent = fmt2(net);
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

  /* ===== Steuer-Begründung UI ===== */
  function needsTaxReason(){
    var need = false;
    root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
      var scheme = (tr.querySelector('.inv-tax-sel')?.value || 'standard').toLowerCase();
      var vat    = toNumber(tr.querySelector('.inv-vat-input')?.value || '0');
      if (scheme !== 'standard' || vat <= 0) need = true;
    });
    return need;
  }
  function toggleTaxReason(){
    var wrap = document.getElementById('tax-exemption-reason-wrap');
    if (!wrap) return;
    var need = needsTaxReason();
    wrap.style.display = need ? '' : 'none';
    if (!need) {
      var ta = document.getElementById('tax-exemption-reason');
      if (ta) ta.value = '';
    }
  }

  /* ===== Reihen neu indexieren (names) ===== */
  function reindexRows(){
    var pairs = [];
    root.querySelectorAll('tr.inv-item-row').forEach(function(main){
      var det = main.nextElementSibling;
      if (!(det && det.classList.contains('inv-details') && det.getAttribute('data-row') === main.getAttribute('data-row'))) det = null;
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
  function updateOrderHidden(){
    var box = document.getElementById('invoice-order-tracker'); if (!box) return;
    box.innerHTML = '';
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      var itemIdInput = r.querySelector('input[name^="items["][name$="[id]"]');
      if (itemIdInput && itemIdInput.value) {
        var i = document.createElement('input');
        i.type='hidden'; i.name='item_order[]'; i.value=String(itemIdInput.value);
        box.appendChild(i);
      }
    });
  }

  /* ===== Immer: Chevron-Toggle (auch im Locked-Zustand erlaubt) ===== */
  root.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.inv-toggle-btn');
    if (!btn || !root.contains(btn)) return;
    var tr  = btn.closest('tr.inv-item-row'); if (!tr) return;
    var det = getDetailsRowByMain(tr); if (!det) return;
    var open = (det.style.display === 'table-row');
    det.style.display = open ? 'none' : 'table-row';
    tr.setAttribute('aria-expanded', String(!open));
  });

  /* ===== Initial (immer) ===== */
  root.querySelectorAll('tr.inv-item-row').forEach(function(tr){
    var sel = tr.querySelector('.inv-tax-sel');
    var vat = tr.querySelector('.inv-vat-input');
    if (sel && vat && (vat.value === '' || vat.value === null)) {
      updateVatFromScheme(sel);
    }
    // WICHTIG: KEIN recalcRow(tr) hier -> wir lassen die von PHP/DB gelieferten Net-Werte unangetastet.
  });
  toggleTaxReason();
  recalcTotals();

  /* ===== Lock-Guard: Alles bearbeiten sperren (außer Details auf/zu) ===== */
  if (locked) {
    // Inputs sperren, Entfernen/Reorder/Drag&Drop ausblenden, Zeit-Checkboxen sperren
    root.querySelectorAll('input, select, textarea, button').forEach(function(el){
      if (el.classList.contains('inv-toggle-btn')) return; // Details-Toggler erlauben
      el.disabled = true;
console.log(el);
    });
    root.querySelectorAll('#addManualItem, .btn-remove-item, .row-reorder-handle, .time-row').forEach(function(el){
      el.removeAttribute('draggable');
      if (!el.classList.contains('time-row')) el.style.display = 'none';
    });
    root.querySelectorAll('.time-checkbox').forEach(function(cb){ cb.disabled = true; });
    return; // keine Edit-Listener initialisieren
  }

  /* ===== Editierbarer Zustand: Listener, DnD, Remove, Reorder ===== */

  // Steuer-Änderungen + Beträge
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
    if (!e.target.matches) return;
    if (e.target.matches('.rate') || e.target.matches('.inv-vat-input') || e.target.matches('.quantity') || e.target.matches('.hours-input')) {
      var tr = e.target.closest('tr.inv-item-row'); if (tr) recalcRow(tr);
      toggleTaxReason();
    }
  });

  // Entfernen
  root.addEventListener('click', function(e){
    var del = e.target.closest && e.target.closest('.btn-remove-item');
    if (!del || !root.contains(del)) return;
    if (!confirm('Diese Position aus der Rechnung entfernen?')) return;
    var tr = del.closest('tr.inv-item-row'); if (!tr) return;
    var rowId = tr.getAttribute('data-row');
    var det   = getDetailsRowById(rowId); if (det) det.remove();

    // Wenn persistente Item-ID existiert → in items_deleted[] eintragen
    var idInput = tr.querySelector('input[name^="items["][name$="[id]"]');
    if (idInput && idInput.value) {
      var trash = document.getElementById('invoice-hidden-trash');
      if (trash) {
        var hidden = document.createElement('input');
        hidden.type='hidden';
        hidden.name='items_deleted[]';
        hidden.value=idInput.value;
        trash.appendChild(hidden);
      }
    }

    tr.remove();
    reindexRows(); toggleTaxReason(); recalcTotals(); updateOrderHidden();
  });

  /* ----- Drag & Drop: Reihenfolge der Positionen (ganze Zeilen) ----- */
  var dragData = null; // {kind:'reorder'|'time', fromRowId, timeId?}
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
  function clearReorderIndicators(){
    root.querySelectorAll('tr.inv-item-row').forEach(function(r){
      r.classList.remove('reorder-indicator-before','reorder-indicator-after');
    });
  }

  // Start drag (reorder)
  root.querySelectorAll('tr.inv-item-row .row-reorder-handle').forEach(function(handle){
    handle.setAttribute('draggable','true');
    handle.addEventListener('dragstart', function(e){
      var row = handle.closest('tr.inv-item-row'); if (!row) return;
      var fromRowId = row.getAttribute('data-row') || '';
      dragData = { kind:'reorder', fromRowId:String(fromRowId) };
      row.classList.add('dnd-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain','reorder:'+fromRowId); } catch(_) {}
      }
    });
    handle.addEventListener('dragend', function(){
      var row = handle.closest('tr.inv-item-row');
      if (row) row.classList.remove('dnd-dragging');
      dragData = null; clearReorderIndicators(); removePlaceholder();
    });
  });

  // Target rows for reorder
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
        var tbody   = targetMain.parentNode;
        var refNode = placeAfter ? getAfterPairNode(targetMain) : targetMain;
        if (refNode) {
          tbody.insertBefore(fromMain, refNode);
          if (fromDet) tbody.insertBefore(fromDet, refNode);
        } else {
          tbody.appendChild(fromMain);
          if (fromDet) tbody.appendChild(fromDet);
        }
        reindexRows();
        updateOrderHidden();
      })(fromId, toId, after);

      removePlaceholder();
    });
  });

  /* ----- Drag & Drop: Zeiten zwischen Positionen verschieben ----- */
  function rejectDropIfIllegal(targetRow){
    if (!targetRow) return true;
    var mode = (targetRow.getAttribute('data-mode') || '').toLowerCase();
    // Zeiten dürfen NICHT in reine 'qty'-Zeilen abgelegt werden
    return (mode === 'qty');
  }
  function markDropTargets(on){
    root.querySelectorAll('.inv-details tbody').forEach(function(tb){
      tb.classList.toggle('dnd-drop-target', !!on);
    });
  }

  root.querySelectorAll('.inv-details tbody tr.time-row[draggable="true"]').forEach(function(tr){
    tr.addEventListener('dragstart', function(e){
      var detailsTr = tr.closest('tr.inv-details');
      var fromRowId = detailsTr ? detailsTr.getAttribute('data-row') : (tr.closest('tr.inv-item-row')?.getAttribute('data-row') || '');
      var timeId = tr.getAttribute('data-time-id') || '';
      dragData = { kind:'time', timeId:String(timeId), fromRowId:String(fromRowId) };
      tr.classList.add('dnd-dragging');
      markDropTargets(true);
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed='move';
        try { e.dataTransfer.setData('text/plain','time:'+timeId); } catch(_) {}
      }
    });
    tr.addEventListener('dragend', function(){
      tr.classList.remove('dnd-dragging');
      dragData = null; markDropTargets(false);
    });
  });

  function doAppendTimeTrToRow(timeTr, targetRow){
    var targetRowId   = targetRow.getAttribute('data-row') || '';
    var targetDetails = getDetailsRowById(targetRowId);
    var targetTbody   = getDetailsTbodyById(targetRowId);
    if (!targetDetails || !targetTbody) return;
    // öffnen
    targetRow.setAttribute('aria-expanded','true');
    targetDetails.style.display = 'table-row';
    // verschieben
    targetTbody.appendChild(timeTr);
    var cb = timeTr.querySelector('.time-checkbox');
    if (cb) {
      cb.name = 'items[' + targetRowId + '][time_ids][]';
      cb.checked = true;
    }
  }

  root.querySelectorAll('.inv-details tbody').forEach(function(tb){
    tb.addEventListener('dragover', function(e){
      if (!dragData) return;
      var trItem = tb.closest('tr.inv-details')?.previousElementSibling;
      if (rejectDropIfIllegal(trItem)) return;
      e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect='move';
    });
    tb.addEventListener('drop', function(e){
      if (!dragData) return;
      e.preventDefault();
      var targetDetails = tb.closest('tr.inv-details');
      if (!targetDetails) return;
      var targetRow = root.querySelector('tr.inv-item-row[data-row="'+targetDetails.getAttribute('data-row')+'"]');
      if (!targetRow || rejectDropIfIllegal(targetRow)) return;

      if (dragData.kind === 'time') {
        var timeTr = getDetailsRowById(dragData.fromRowId)?.querySelector('tbody tr.time-row[data-time-id="'+dragData.timeId+'"]');
        if (!timeTr) return;
        doAppendTimeTrToRow(timeTr, targetRow);
        var fromRow = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
        if (fromRow) recalcRow(fromRow);
        recalcRow(targetRow);
        recalcTotals();
      }
    });
  });

  // Drop direkt auf Kopfzeile (öffnet Details bei Hover)
  root.querySelectorAll('tr.inv-item-row').forEach(function(row){
    var hoverTimer = null;
    row.addEventListener('dragenter', function(e){
      if (!dragData || dragData.kind!=='time' || rejectDropIfIllegal(row)) return;
      row.classList.add('time-drop-accept');
      if (hoverTimer) clearTimeout(hoverTimer);
      hoverTimer = setTimeout(function(){
        var det = getDetailsRowByMain(row);
        if (det && det.style.display !== 'table-row') {
          det.style.display = 'table-row';
          row.setAttribute('aria-expanded', 'true');
        }
      }, 300);
    });
    row.addEventListener('dragover', function(e){
      if (!dragData || dragData.kind!=='time' || rejectDropIfIllegal(row)) return;
      e.preventDefault();
      row.classList.add('time-drop-accept');
    });
    row.addEventListener('dragleave', function(){
      row.classList.remove('time-drop-accept');
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
    });
    row.addEventListener('drop', function(e){
      if (!dragData || dragData.kind!=='time' || rejectDropIfIllegal(row)) return;
      e.preventDefault();
      row.classList.remove('time-drop-accept');
      if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }

      var rowId = row.getAttribute('data-row') || '';
      var targetDetails = getDetailsRowById(rowId);
      var targetTbody   = getDetailsTbodyById(rowId);
      if (!targetDetails || !targetTbody) return;

      row.setAttribute('aria-expanded','true');
      targetDetails.style.display = 'table-row';

      var timeTr = getDetailsRowById(dragData.fromRowId)?.querySelector('tbody tr.time-row[data-time-id="'+dragData.timeId+'"]');
      if (!timeTr) return;

      doAppendTimeTrToRow(timeTr, row);
      var fromRow = root.querySelector('tr.inv-item-row[data-row="'+dragData.fromRowId+'"]');
      if (fromRow) recalcRow(fromRow);
      recalcRow(row);
      recalcTotals();
    });
  });

})();
</script>
