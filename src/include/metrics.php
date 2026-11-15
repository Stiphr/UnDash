<?php
/*
 * UnDash - history charts: one reading a minute from the background check (scripts/alertcheck.php), so the charts have no
 * holes when no dashboard is open. The last 26 hours are kept minute by minute, 31 days as 15-minute averages.
 * The working copy is in the temp folder; data/metrics.json gets a copy every 10 minutes, so the disk is not written each minute.
 */

const UND_METRIC_FIELDS = ['cpu', 'mem', 'temp', 'disk_temp', 'rx', 'tx', 'used'];
const UND_METRIC_FINE = 26 * 3600;
const UND_METRIC_STEP = 900;
const UND_METRIC_COARSE = 31 * 86400;
const UND_METRIC_FLUSH = 600;
const UND_METRIC_RANGES = ['1h' => [3600, 'fine'], '24h' => [86400, 'fine'], '7d' => [7 * 86400, 'coarse'], '30d' => [30 * 86400, 'coarse']];

function und_metric_file() { return und_data_dir() . '/metrics.json'; }

/** The newer of the working copy and the saved one (the temp folder is empty after the container is re-created). */
function und_metrics_load() {
  $h = und_read_json(und_tmp('metrics.json'), null);
  if (!is_array($h)) $h = und_read_json(und_metric_file(), []);
  return ['fine' => array_values((array)($h['fine'] ?? [])), 'coarse' => array_values((array)($h['coarse'] ?? [])), 'saved' => (int)($h['saved'] ?? 0)];
}

/** One row from a system snapshot: [time, then UND_METRIC_FIELDS]. A value the server did not report stays null. */
function und_metrics_row(array $sys, array $disks, $now) {
  $a = (array)($sys['array'] ?? []); $size = (float)($a['size'] ?? 0); $free = (float)($a['free'] ?? 0);
  if ($size <= 0) foreach ((array)($a['pools'] ?? []) as $p) { $size += (float)($p['size'] ?? 0); $free += (float)($p['free'] ?? 0); }
  $temps = array_filter(array_map(function ($d) { return $d['temp'] ?? null; }, $disks), 'is_numeric');
  $n = function ($v, $dec) { return is_numeric($v) ? round((float)$v, $dec) : null; };
  return [(int)$now, $n($sys['cpu']['pct'] ?? null, 1), $n($sys['mem']['pct'] ?? null, 1), $n($sys['cpu']['temp'] ?? null, 0), $temps ? (int)max($temps) : null,
          $n($sys['net']['rx'] ?? null, 0), $n($sys['net']['tx'] ?? null, 0), $size > 0 ? round(100 * ($size - $free) / $size, 2) : null];
}

/** The average of each field over these rows, ignoring what was not reported. */
function und_metrics_avg(array $rows, $t) {
  $out = [(int)$t];
  foreach (array_keys(UND_METRIC_FIELDS) as $i) {
    $v = array_filter(array_map(function ($r) use ($i) { return $r[$i + 1] ?? null; }, $rows), 'is_numeric');
    $out[] = $v ? round(array_sum($v) / count($v), 2) : null;
  }
  return $out;
}

/** Adds one reading. A finished 15-minute stretch becomes one average; old rows drop off both lists. */
function und_metrics_add(array $h, array $row) {
  $now = $row[0];
  $last = end($h['fine']);
  if ($last && $now - (int)$last[0] < 30) return $h;      // a restart ran the check twice
  $bucket = $now - $now % UND_METRIC_STEP;
  $was = $last ? (int)$last[0] - (int)$last[0] % UND_METRIC_STEP : $bucket;
  if ($was < $bucket) {
    $done = array_filter($h['fine'], function ($r) use ($was) { return $r[0] >= $was && $r[0] < $was + UND_METRIC_STEP; });
    if ($done) $h['coarse'][] = und_metrics_avg(array_values($done), $was);
  }
  $h['fine'][] = $row;
  $h['fine'] = array_values(array_filter($h['fine'], function ($r) use ($now) { return $r[0] >= $now - UND_METRIC_FINE; }));
  $h['coarse'] = array_values(array_filter($h['coarse'], function ($r) use ($now) { return $r[0] >= $now - UND_METRIC_COARSE; }));
  return $h;
}

function und_metrics_record(array $sys, $now = null) {
  $now = $now ?? time();
  if (isset($sys['error'])) return;
  $st = und_storage(); $disks = [];
  if (is_array($st) && !isset($st['error'])) foreach (['parity', 'array', 'pools'] as $k) $disks = array_merge($disks, (array)($st[$k] ?? []));
  $h = und_metrics_add(und_metrics_load(), und_metrics_row($sys, $disks, $now));
  if ($now - $h['saved'] >= UND_METRIC_FLUSH) { $h['saved'] = $now; und_write_json(und_metric_file(), $h); }
  und_write_json(und_tmp('metrics.json'), $h);
}

/** For the page: the rows of one range. step = seconds between rows, so the chart can show where the server was off. */
function und_metrics_range($range, $now = null) {
  $now = $now ?? time();
  $r = UND_METRIC_RANGES[$range] ?? UND_METRIC_RANGES['24h'];
  $h = und_metrics_load();
  $rows = array_values(array_filter($h[$r[1]], function ($x) use ($now, $r) { return $x[0] >= $now - $r[0]; }));
  return ['now' => $now, 'from' => $now - $r[0], 'step' => $r[1] === 'fine' ? 60 : UND_METRIC_STEP, 'fields' => UND_METRIC_FIELDS, 'rows' => $rows];
}

/** When the storage fills up at the pace of the last 30 days: a straight line through the 15-minute averages. Size in bytes. */
function und_metrics_forecast(array $rows, $size, $now = null) {
  $now = $now ?? time(); $col = array_search('used', UND_METRIC_FIELDS) + 1; $pts = [];
  foreach ($rows as $r) if (isset($r[$col]) && $r[0] >= $now - UND_METRIC_RANGES['30d'][0]) $pts[] = [($r[0] - $now) / 86400, (float)$r[$col]];
  $need = 3;
  $span = $pts ? end($pts)[0] - $pts[0][0] : 0;
  if ($span < $need) return ['ready' => false, 'wait' => (int)ceil($need - $span)];
  $n = count($pts); $sx = 0; $sy = 0; $xx = 0; $xy = 0;
  foreach ($pts as $p) { $sx += $p[0]; $sy += $p[1]; $xx += $p[0] * $p[0]; $xy += $p[0] * $p[1]; }
  $div = $n * $xx - $sx * $sx;
  $slope = $div > 0 ? ($n * $xy - $sx * $sy) / $div : 0;           // % a day
  if ($slope < 0.005) return ['ready' => true, 'rising' => false];
  return ['ready' => true, 'rising' => true, 'per_day' => (int)round($slope / 100 * $size), 'days' => (int)round((100 - end($pts)[1]) / $slope)];
}

