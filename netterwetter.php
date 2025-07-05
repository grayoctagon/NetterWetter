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
 * Changes (2025‑07‑02):
 *   • Each day starts at 00:00 exactly and is rendered 400 px wide.
 *   • Time labels every 3 h (00:00, 03:00, 06:00 … 21:00).
 *   • 00:00 grid line is dark‑grey; other 3 h lines are light‑grey.
 *   • At 12:00 of each day a day‑label is drawn below the time label (e.g. “Friday 4.7.2025”).
 *   • Tooltip values now carry units (°C, %, mm/h, m/s).
 *
 */

declare(strict_types=1);

// ----------------------------------------------------
// 0.  Locate input files & pick selection
// ----------------------------------------------------
$files = glob('data/20*/weather*_minimized.json') ?: [];
// newest first (lexicographic ‑> date based)
rsort($files, SORT_NATURAL);               // newest first (lexicographic ok)
$selectedFile = $files[0] ?? null;
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

// gather all timestamps (UTC) & compute unique day starts
$times = array_map(fn($r) => strtotime($r['startTime']), $rows);
$midnights = [];
foreach ($times as $ts) {
    $mid = strtotime(gmdate('Y-m-d 00:00:00', $ts));
    $midnights[$mid] = true;
}
ksort($midnights);
$dayStarts = array_keys($midnights);
$dayCount  = count($dayStarts);
$firstDayStart = $dayStarts[0];

// ----------------------------------------------------
// 2.  Prepare metric definitions & ranges
// ----------------------------------------------------
$metrics = [
    'windGust'               => ['label' => 'Wind Gust (m/s)',    'color' => '#AAA', 'unit' => 'm/s',  "strokewidth" => 1.5],
    'windSpeed'              => ['label' => 'Wind (m/s)',         'color' => '#FFF', 'unit' => 'm/s',  "strokewidth" => 1.5],
    'humidity'               => ['label' => 'Humidity (%)',       'color' => '#00B0FF', 'unit' => '%',    "strokewidth" => 1.5],
    'precipitationIntensity' => ['label' => 'Precip (mm/h)',      'color' => '#0D47A1', 'unit' => 'mm/h', "strokewidth" => 3],
    'temperatureApparent'    => ['label' => 'Apparent Temp (°C)', 'color' => '#FFD600', 'unit' => '°C',   "strokewidth" => 4],
    'temperature'            => ['label' => 'Temperature (°C)',   'color' => '#FF9800', 'unit' => '°C',   "strokewidth" => 2],
];

$ranges = [];
$airMax=1;
{
    $asV = array_column($rows, 'windSpeed');
    $asV = array_values(array_filter($asV, 'is_numeric'));
    $agV = array_column($rows, 'windGust');
    $agV = array_values(array_filter($agV, 'is_numeric'));
    $airMax=max(max($asV),max($agV));
}
foreach ($metrics as $key => $_) {
    $values = array_column($rows, $key);
    $values = array_values(array_filter($values, 'is_numeric'));
    

    // default min/max
    $min = ($key === 'humidity') ? 0 : (count($values) ? min($values) : 0);
    $max = ($key === 'humidity') ? 100 : (count($values) ? max($values) : 0);
    if (in_array($key, ['windSpeed','windGust','precipitationIntensity'], true)) {
        $min = 0;
    }
    if (in_array($key, ['windSpeed','windGust'], true)) {
        $max = $airMax;
    }
    if ($key === 'precipitationIntensity') {
        $max = ceil($max / 2) * 2;              // round to 2 mm/hr grid
    } elseif (in_array($key, ['windSpeed', 'windGust'], true)) {
        $max = ceil($max / 5) * 5;              // 5 m/s grid
    }
    if ($max === $min) {
        $max = $min + 1;                        // avoid div/0
    }
    $ranges[$key] = ['min' => $min, 'max' => $max];
}

// ----------------------------------------------------
// 3.  Geometry helpers
// ----------------------------------------------------
$DAY_PX = 400;
$PAD_L = 60; $PAD_R = 20; $PAD_T = 20; $PAD_B = 55; // extra space for two‑line labels
$PLOT_W = $dayCount * $DAY_PX;
$PLOT_H = 600 - $PAD_T - $PAD_B;          // keep overall height 600
$W      = $PAD_L + $PLOT_W + $PAD_R;      // full SVG width
$H      = 600;

// X‑coords for every data point
function convertDaytimeToX($ts){
    global $firstDayStart, $DAY_PX, $PAD_L;
    $dayIdx = intdiv($ts - $firstDayStart, 86400);
    $secsIntoDay = $ts - ($firstDayStart + $dayIdx*86400);
    return $PAD_L + $dayIdx*$DAY_PX + ($secsIntoDay / 86400) * $DAY_PX;
}
$xCoords = [];
foreach ($times as $ts) {
    $xCoords[] =convertDaytimeToX($ts);
}

// Y‑coord helper
function y(float $val, string $key, array $ranges, int $PAD_T, int $PLOT_H): float {
    [$min,$max] = [$ranges[$key]['min'],$ranges[$key]['max']];
    return $PAD_T + (1 - ($val - $min)/($max - $min)) * $PLOT_H;
}

// Prepare JS series arrays
$jsSeries = [];
foreach (array_keys($metrics) as $key) {
    $arr = [];
    foreach ($rows as $r) { $arr[] = $r[$key] ?? null; }
    $jsSeries[$key] = $arr;
}

// ----------------------------------------------------
// 4.  Produce HTML
// ----------------------------------------------------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>NetterWetter - <?=htmlspecialchars($selectedFile)?></title>
<style>
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:1rem;background-color: gray;}
    .file-list{margin-bottom:1rem;}
    .file-list a{margin-right:.5rem;text-decoration:none;padding:.25rem .5rem;border:1px solid #ccc;border-radius:4px;}
    .file-list a.active{background:#1976d2;color:#fff;}
    /*svg text{font-size:18px;fill:#222;}*/
    .grid-midnight{stroke:#424242;stroke-width:1;}
    .grid-3h   {stroke:#e0e0e0;stroke-width:1;}
    .grid-y    {stroke:#ccc;stroke-width:1;}
    .crosshair {stroke:#aaa;stroke-width:1;visibility:hidden;pointer-events:none;}
    .dot       {visibility:hidden;pointer-events:none;stroke:#fff;stroke-width:1;}
    .tooltip   {visibility:hidden;font-size:11px;font-weight:600;pointer-events:none;}
</style>
</head>
<body>
<h2>Weather graph for <?=htmlspecialchars($location)?> (<?=count($rows)?> points)</h2>
<div class="file-list">
    <?php foreach ($files as $f): ?>
        <a class="<?=$f===$selectedFile?'active':'';?>" href="?file=<?=urlencode($f)?>"><?=htmlspecialchars($f)?></a>
    <?php endforeach; ?>
</div>
<div style="overflow-x:auto;">
<svg id="chart" viewBox="0 0 <?=$W?> <?=$H?>" width="<?=$W?>" height="<?=$H?>">
    <!-- sunrise sunset -->
    <g>
        <linearGradient id="sunrise">
            <stop offset="0" stop-color="#80808000"/>
            <stop offset="0.8" stop-color="#ffa50033"/>
        </linearGradient>
        <linearGradient id="sunset">
            <stop offset="0.2" stop-color="#ffa50033"/>
            <stop offset="1" stop-color="#80808000"/>
        </linearGradient>
        <linearGradient id="daylight">
            <stop offset="0" stop-color="#ffa50033"/>
            <stop offset="1" stop-color="#ffa50033"/>
        </linearGradient>
        <?php 
            $sunrises = array_column($rows, 'sunriseTime');
            $sunrises = array_values(array_filter($sunrises, 'is_string'));
            $sunrises = array_map('strtotime', $sunrises);
            
            $sunsets = array_column($rows, 'sunsetTime');
            $sunsets = array_values(array_filter($sunsets, 'is_string'));
            $sunsets = array_map('strtotime', $sunsets);
            
        ?>
        <?php foreach ($sunrises as $index=>$sunrise1): ?>
            <rect name="<?=(date("H:i:s", $sunrise1)."/".$sunrise1)?>" x="<?=convertDaytimeToX($sunrise1)-$DAY_PX/48?>" y="<?=$PAD_T?>" fill="url(#sunrise)" width="<?=$DAY_PX/24?>" height="<?=$PLOT_H?>"/>
            <text font-size='12px' text-anchor='middle' fill='#ffa500ff' x="<?=convertDaytimeToX($sunrise1)?>" y="<?=$PAD_T+$PLOT_H-4?>"><?=date("H:i", $sunrise1)?></text>
            
            <rect x="<?=convertDaytimeToX($sunrise1)+$DAY_PX/48?>" y="<?=$PAD_T?>" fill="url(#daylight)" width="<?=(($DAY_PX/24)*(((isset($sunsets[$index])?$sunsets[$index]:12)-$sunrise1)/60/60-1))?>" height="<?=$PLOT_H?>"/>
            
        <?php endforeach; ?>
        <?php foreach ($sunsets as $sunset1): ?>
            <rect name="<?=(date("H:i:s", $sunset1)."/".$sunset1)?>" x="<?=convertDaytimeToX($sunset1)-$DAY_PX/48?>" y="<?=$PAD_T?>" fill="url(#sunset)" width="<?=$DAY_PX/24?>" height="<?=$PLOT_H?>"/>
            <text font-size='12px' text-anchor='middle' fill='#ffa500ff' x="<?=convertDaytimeToX($sunset1)?>" y="<?=$PAD_T+$PLOT_H-4?>"><?=date("H:i", $sunset1)?></text>
        <?php endforeach; ?>
    </g>
    
    <!-- horizontal Y% grid -->
    <g>
    <?php foreach ([0,25,50,75,100] as $pct):
        $y = $PAD_T + (1 - $pct/100) * $PLOT_H; ?>
        <line x1="<?=$PAD_L?>" y1="<?=$y?>" x2="<?=$PAD_L + $PLOT_W?>" y2="<?=$y?>" class="grid-y" />
    <?php endforeach; ?>
    </g>

    <!-- vertical day + 3h grid & labels -->
    <g>
    <?php foreach ($dayStarts as $dIdx=>$mid):
        $dayX = $PAD_L + $dIdx*$DAY_PX;
        // midnight line
        echo "<line x1='$dayX' y1='$PAD_T' x2='$dayX' y2='".($PAD_T+$PLOT_H)."' class='grid-midnight' />";
        // label 00:00
        $lblY = $PAD_T + $PLOT_H + 18;
        echo "<text font-size='18px' x='$dayX' y='$lblY' text-anchor='middle'>00:00</text>";

        // 3‑hour minor lines (3.21)
        for($h=3;$h<=21;$h+=3){
            $x = $dayX + ($h/24)*$DAY_PX;
            echo "<line x1='$x' y1='$PAD_T' x2='$x' y2='".($PAD_T+$PLOT_H)."' class='grid-3h' />";
            echo "<text font-size='18px' x='$x' y='$lblY' text-anchor='middle' fill='#222'>".sprintf('%02d:00',$h)."</text>";
            // 12:00 extra day label
            if($h===12){
                $dayLabel = gmdate('l j.n.Y', $mid+43200); // +12h
                echo "<text font-size='18px' x='$x' y='".($lblY+18)."' text-anchor='middle' fill='#222'>$dayLabel</text>";
            }
        }
    endforeach; ?>
    </g>
    <!-- Paths for each metric -->
    <g>
    <?php foreach ($metrics as $key=>$def):
        $p='';
        foreach ($rows as $i=>$r){
			if(!isset($r[$key])) continue; $p.=($p?' L':'M').round($xCoords[$i],1).' '.round(y($r[$key],$key,$ranges,$PAD_T,$PLOT_H),1);
		} ?>
        <path name="<?=$key?>" d="<?=$p?>" fill="none" stroke="<?=$def['color']?>" stroke-width="<?=$def['strokewidth']?>" />
    <?php endforeach; ?>
    </g>

    <!-- crosshair & dots -->
    <g>
    <line id="vline"   class="crosshair" y1="<?=$PAD_T?>" y2="<?=$PAD_T+$PLOT_H?>" />
    <line id="hline"   class="crosshair" x1="<?=$PAD_L?>" x2="<?=$PAD_L+$PLOT_W?>" />
    <?php foreach ($metrics as $key=>$def): ?>
        <circle id="dot_<?=$key?>" r="3" fill="<?=$def['color']?>" class="dot" />
        <text font-size='18px' id="label_<?=$key?>" class="tooltip" fill="<?=$def['color']?>"></text>
    <?php endforeach; ?>
    </g>
</svg>
</div>

<ul style="list-style:none;padding:0;margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.75rem;">
<?php foreach ($metrics as $key=>$def): ?><li><span style="display:inline-block;width:12px;height:12px;background:<?=$def['color']?>;margin-right:4px;border-radius:2px;"></span><?=$def['label']?></li><?php endforeach; ?>
</ul>

<script>
(() => {
    const PAD_L = <?=$PAD_L?>, PAD_T = <?=$PAD_T?>, PLOT_H = <?=$PLOT_H?>;
    const xCoords = <?=json_encode($xCoords)?>;
    const times   = <?=json_encode($times)?>;
    const data    = <?=json_encode($jsSeries)?>;
    const ranges  = <?=json_encode($ranges)?>;
    const units   = <?=json_encode(array_column($metrics,'unit'))?>;

    const svg = document.getElementById('chart');
    const vline = document.getElementById('vline');
    const hline = document.getElementById('hline');
    const dots = {}, labels = {};
    Object.keys(data).forEach(k=>{dots[k]=document.getElementById('dot_'+k);labels[k]=document.getElementById('label_'+k);});

    function hide(){vline.style.visibility=hline.style.visibility='hidden';Object.values(dots).forEach(d=>d.style.visibility='hidden');Object.values(labels).forEach(l=>l.style.visibility='hidden');}
    hide();

    svg.addEventListener('mouseleave', hide);

    svg.addEventListener('mousemove', e=>{
        const pt = svg.createSVGPoint(); pt.x=e.clientX; pt.y=e.clientY;
        const loc = pt.matrixTransform(svg.getScreenCTM().inverse());
        if (loc.y < PAD_T || loc.y > PAD_T+PLOT_H) { hide(); return; }
        // find nearest x‑coord (binary search would be nicer, linear ok here)
        let idx = 0; let best = Infinity;
        for(let i=0;i<xCoords.length;i++){
            const d = Math.abs(xCoords[i]-loc.x); if(d<best){best=d;idx=i;} else if(xCoords[i] > loc.x && d>best) break; }
        const x = xCoords[idx];
        if (Math.abs(x-loc.x) > 30) { hide(); return; } // too far from any point

        vline.setAttribute('x1',x); vline.setAttribute('x2',x); vline.style.visibility='visible';
        hline.setAttribute('y1',loc.y); hline.setAttribute('y2',loc.y); hline.style.visibility='visible';

        Object.keys(data).forEach(key=>{
            const val = data[key][idx];
            if(val===null||val===undefined){dots[key].style.visibility=labels[key].style.visibility='hidden';return;}
            const min=ranges[key].min,max=ranges[key].max;
            const y = PAD_T + (1-(val-min)/(max-min))*PLOT_H;
            dots[key].setAttribute('cx',x); dots[key].setAttribute('cy',y); dots[key].style.visibility='visible';
            labels[key].setAttribute('x',x+5); labels[key].setAttribute('y',y-5);
            const unit = units[key]||'';
            const decimals = (unit==='%'?0:1);
            labels[key].textContent = val.toFixed(decimals)+unit;
            labels[key].style.visibility='visible';
        });
    });
})();
</script>
</body>
</html>