<?php
/**
 * Author: Michael Beck
 * 
 * Repository: https://github.com/grayoctagon/NetterWetter
 * 
 * *developed with AI support*
 * 
 * Weather Graph Visualization App
 * --------------------------------
 * - Lists available minimized weather json files (weatherYYYY_MM_minimized.json)
 * - Shows interactive SVG line graph for several metrics
 * - Defaults to most‑recent file when none selected
 * - Hover graph to get values (cross‑hair, dots, numeric labels)
 *
 */

declare(strict_types=1);

// ----------------------------------------------------
// 0.  Locate input files & pick selection
// ----------------------------------------------------
$files = glob('data/20*/weather*_minimized.json') ?: [];
// newest first (lexicographic ‑> date based)
rsort($files, SORT_NATURAL);
$selectedFile = null;
if (isset($_GET['file']) && in_array($_GET['file'], $files, true)) {
    $selectedFile = $_GET['file'];
} elseif ($files) {
    $selectedFile = $files[0];
}

// guard when no data files exist
if (!$selectedFile) {
    echo "<h2>No weather data files found.</h2>";
    exit;
}

// ----------------------------------------------------
// 1.  Load + sort data chronologically
// ----------------------------------------------------
$raw      = json_decode(file_get_contents($selectedFile), true, 512, JSON_THROW_ON_ERROR);
$location = array_key_first($raw['dataforlocations']);
$rows     = $raw['dataforlocations'][$location] ?? [];
usort($rows, fn($a, $b) => strcmp($a['startTime'], $b['startTime']));   // ascending

// ----------------------------------------------------
// 2.  Prepare metric definitions & ranges
// ----------------------------------------------------
$metrics = [
    'temperatureApparent'   => ['label' => 'Apparent Temp (°C)',   'color' => '#FFD600'],
    'temperature'           => ['label' => 'Temperature (°C)',     'color' => '#FF9800'],
    'humidity'              => ['label' => 'Humidity (%)',         'color' => '#00B0FF'],
    'precipitationIntensity'=> ['label' => 'Precip (mm/hr)',       'color' => '#0D47A1'],
    'windSpeed'             => ['label' => 'Wind (m/s)',           'color' => '#757575'],
    'windGust'              => ['label' => 'Wind Gust (m/s)',      'color' => '#BDBDBD'],
];

$ranges = [];
foreach ($metrics as $key => $_) {
    $values = array_column($rows, $key);
    $values = array_values(array_filter($values, 'is_numeric'));

    // default min/max
    $min = $key === 'humidity' ? 0 : (count($values) ? min($values) : 0);
    $max = count($values) ? max($values) : 0;

    // special axis treatment
    if (in_array($key, ['windSpeed', 'windGust', 'precipitationIntensity'], true)) {
        $min = 0;
    }
    if ($key === 'precipitationIntensity') {
        $max = ceil($max / 2) * 2;              // round to 2 mm/hr grid
    } elseif (in_array($key, ['windSpeed', 'windGust'], true)) {
        $max = ceil($max / 5) * 5;              // 5 m/s grid
    }
    if ($max === $min) {
        $max = $min + 1;                        // avoid div/0
    }
    $ranges[$key] = ['min' => $min, 'max' => $max];
}

// ----------------------------------------------------
// 3.  Build shared arrays for JS
// ----------------------------------------------------
$times = [];
foreach ($rows as $r) { $times[] = strtotime($r['startTime']); }

$jsSeries = [];
foreach (array_keys($metrics) as $key) {
    $arr = [];
    foreach ($rows as $r) {
        $arr[] = array_key_exists($key, $r) ? $r[$key] : null;
    }
    $jsSeries[$key] = $arr;
}

// SVG dimensions / padding
$W  = 1200;
$H  = 600;
$PAD_L = 60; $PAD_R = 20; $PAD_T = 20; $PAD_B = 40;
$PLOT_W = $W - $PAD_L - $PAD_R;
$PLOT_H = $H - $PAD_T - $PAD_B;

// timeline
$startTs = $times[0];
$endTs   = end($times);
$totalH  = max(1, ($endTs - $startTs) / 3600);

// pre‑compute x coordinates for all timestamps
$xCoords = array_map(function(int $ts) use ($startTs, $PLOT_W, $PAD_L, $totalH) {
    return $PAD_L + ($ts - $startTs) / 3600 / $totalH * $PLOT_W;
}, $times);

// helper to scale y
function y(float $value, string $metricKey, array $range, int $PAD_T, int $PLOT_H): float {
    [$min,$max] = [$range[$metricKey]['min'],$range[$metricKey]['max']];
    return $PAD_T + (1 - ($value - $min)/($max - $min)) * $PLOT_H;
}

// ----------------------------------------------------
// 4.  Produce HTML
// ----------------------------------------------------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Weather Graph – <?=htmlspecialchars($selectedFile)?></title>
<style>
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:1rem;}
    .file-list{margin-bottom:1rem;}
    .file-list a{margin-right:.5rem;text-decoration:none;padding:.25rem .5rem;border:1px solid #ccc;border-radius:4px;}
    .file-list a.active{background:#1976d2;color:#fff;}
    svg text{font-size:10px;fill:#555;}
    .grid-major{stroke:#000;stroke-width:1;}
    .grid-minor{stroke:#e0e0e0;stroke-width:1;}
    .grid-y    {stroke:#ccc;stroke-width:1;}
    .crosshair {stroke:#aaa;stroke-width:1;visibility:hidden;pointer-events:none;}
    .dot       {visibility:hidden;pointer-events:none;stroke:#fff;stroke-width:1;}
    .tooltip   {visibility:hidden;font-size:11px;font-weight:600;pointer-events:none;}
</style>
</head>
<body>

<h2>Weather graph for <?=htmlspecialchars($location)?> (<?=count($rows)?> points)</h2>

<div class="file-list">
    <?php foreach ($files as $f): ?>
        <?php $active = $f === $selectedFile ? 'active' : ''; ?>
        <a class="<?=$active?>" href="?file=<?=urlencode($f)?>"><?=htmlspecialchars($f)?></a>
    <?php endforeach; ?>
</div>

<svg id="chart" viewBox="0 0 <?=$W?> <?=$H?>" width="100%" height="auto">
    <!-- horizontal Y% grid -->
    <?php foreach ([0,25,50,75,100] as $pct):
        $y = $PAD_T + (1 - $pct/100) * $PLOT_H; ?>
        <line x1="<?=$PAD_L?>" y1="<?=$y?>" x2="<?=$PAD_L + $PLOT_W?>" y2="<?=$y?>" class="grid-y" />
    <?php endforeach; ?>

    <!-- X axis minor (3‑hour) & major (day) grid -->
    <?php
        $h = 0;
        for ($ts=$startTs; $ts<=$endTs; $ts+=10800, $h+=3) { // 3 hrs
            $x = $PAD_L + ($ts-$startTs)/3600/$totalH*$PLOT_W;
            $isDayBoundary = ( (int)gmdate('H',$ts) === 0);
            $cls = $isDayBoundary ? 'grid-major' : 'grid-minor';
            echo "<line x1='$x' y1='$PAD_T' x2='$x' y2='".($PAD_T+$PLOT_H)."' class='$cls' />";

            // labels
            if($isDayBoundary){
                $dayLabel = gmdate('Y‑m‑d',$ts);
                echo "<text x='$x' y='".($PAD_T+$PLOT_H+12)."' text-anchor='middle'>$dayLabel</text>";
            } else {
                $timeLabel = gmdate('H:i',$ts);
                echo "<text x='$x' y='".($PAD_T+$PLOT_H+12)."' text-anchor='middle' fill='#888'>$timeLabel</text>";
            }
        }
    ?>

    <!-- Metric paths -->
    <?php foreach ($metrics as $key=>$def):
        $p = '';
        foreach ($rows as $idx=>$r) {
            if (!isset($r[$key])) { continue; }
            $x = $xCoords[$idx];
            $y = y($r[$key], $key, $ranges, $PAD_T, $PLOT_H);
            $p .= ($p===''? 'M':' L').round($x,1).' '.round($y,1);
        } ?>
        <path d="<?=$p?>" fill="none" stroke="<?=$def['color']?>" stroke-width="1.5" />
    <?php endforeach; ?>

    <!-- crosshair & intercept dots (added once per metric) -->
    <line id="vline" class="crosshair" y1="<?=$PAD_T?>" y2="<?=$PAD_T+$PLOT_H?>"/>
    <line id="hline" class="crosshair" x1="<?=$PAD_L?>" x2="<?=$PAD_L+$PLOT_W?>"/>

    <?php foreach ($metrics as $key=>$def): ?>
        <circle id="dot_<?=$key?>" r="3" fill="<?=$def['color']?>" class="dot" />
        <text   id="label_<?=$key?>" class="tooltip" fill="<?=$def['color']?>"></text>
    <?php endforeach; ?>
</svg>

<!-- Legend -->
<ul style="list-style:none;padding:0;margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.75rem;">
    <?php foreach ($metrics as $key=>$def): ?>
        <li><span style="display:inline-block;width:12px;height:12px;background:<?=$def['color']?>;margin-right:4px;border-radius:2px;"></span><?=$def['label']?></li>
    <?php endforeach; ?>
</ul>

<script>
(() => {
    const PAD_L = <?=$PAD_L?>, PAD_T = <?=$PAD_T?>, PAD_R = <?=$PAD_R?>, PAD_B = <?=$PAD_B?>;
    const plotW = <?=$PLOT_W?>, plotH = <?=$PLOT_H?>;
    const startTs = <?=$startTs?>, totalHours = <?=$totalH?>;

    const times   = <?=json_encode($times)?>;
    const data    = <?=json_encode($jsSeries)?>;
    const ranges  = <?=json_encode($ranges)?>;

    const svg = document.getElementById('chart');
    const vline = document.getElementById('vline');
    const hline = document.getElementById('hline');

    const dots   = {}, labels = {};
    Object.keys(data).forEach(k => {
        dots[k]   = document.getElementById('dot_'+k);
        labels[k] = document.getElementById('label_'+k);
    });

    function hide(){
        vline.style.visibility = hline.style.visibility = 'hidden';
        for (const k in dots){ dots[k].style.visibility = labels[k].style.visibility = 'hidden'; }
    }
    hide();

    svg.addEventListener('mouseleave', hide);

    svg.addEventListener('mousemove', evt => {
        const pt = svg.createSVGPoint();
        pt.x = evt.clientX; pt.y = evt.clientY;
        const loc = pt.matrixTransform(svg.getScreenCTM().inverse());

        // inside plot?
        if (loc.x < PAD_L || loc.x > PAD_L+plotW || loc.y < PAD_T || loc.y > PAD_T+plotH){
            hide(); return;
        }

        const hour   = (loc.x - PAD_L) / plotW * totalHours;
        const idx    = Math.round(hour);
        if (!times[idx]) { hide(); return; }

        const x = PAD_L + ( (times[idx]-startTs)/3600 / totalHours ) * plotW;

        // update crosshair
        vline.setAttribute('x1',x); vline.setAttribute('x2',x);
        hline.setAttribute('y1',loc.y); hline.setAttribute('y2',loc.y);
        vline.style.visibility = hline.style.visibility = 'visible';

        // per‑metric dots + labels
        Object.keys(data).forEach(key => {
            const val = data[key][idx];
            if (val === null || val === undefined) { dots[key].style.visibility = labels[key].style.visibility = 'hidden'; return; }

            const min = ranges[key].min, max = ranges[key].max;
            const y   = PAD_T + (1 - (val - min)/(max - min))*plotH;

            dots[key].setAttribute('cx',x);
            dots[key].setAttribute('cy',y);
            dots[key].style.visibility = 'visible';

            labels[key].setAttribute('x', x + 5);
            labels[key].setAttribute('y', y - 5);
            labels[key].textContent = val.toFixed(1);
            labels[key].style.visibility = 'visible';
        });
    });
})();
</script>
</body>
</html>
