<?php

declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "A extensão GD é obrigatória para gerar os ícones.\n");
    exit(1);
}

$root = dirname(__DIR__);
$out = $root.'/public/icons/sutoorii-tickets-icon-512.png';
@mkdir(dirname($out), 0775, true);

$S = 2;
$size = 512 * $S;
$im = imagecreatetruecolor($size, $size);
imagealphablending($im, true);
imagesavealpha($im, true);

function rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}
function col($im, string $hex, int $alpha = 0): int {
    [$r,$g,$b] = rgb($hex);
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}
function roundedRect($im, int $x, int $y, int $w, int $h, int $r, int $color): void {
    imagefilledrectangle($im, $x+$r, $y, $x+$w-$r, $y+$h, $color);
    imagefilledrectangle($im, $x, $y+$r, $x+$w, $y+$h-$r, $color);
    imagefilledellipse($im, $x+$r, $y+$r, $r*2, $r*2, $color);
    imagefilledellipse($im, $x+$w-$r, $y+$r, $r*2, $r*2, $color);
    imagefilledellipse($im, $x+$r, $y+$h-$r, $r*2, $r*2, $color);
    imagefilledellipse($im, $x+$w-$r, $y+$h-$r, $r*2, $r*2, $color);
}
function cubic(array $p0, array $p1, array $p2, array $p3, int $steps = 80): array {
    $out = [];
    for ($i=0; $i<=$steps; $i++) {
        $t=$i/$steps; $u=1-$t;
        $x=$u*$u*$u*$p0[0]+3*$u*$u*$t*$p1[0]+3*$u*$t*$t*$p2[0]+$t*$t*$t*$p3[0];
        $y=$u*$u*$u*$p0[1]+3*$u*$u*$t*$p1[1]+3*$u*$t*$t*$p2[1]+$t*$t*$t*$p3[1];
        $out[]=[(int)round($x),(int)round($y)];
    }
    return $out;
}
function thickPath($im, array $points, int $width, int $color): void {
    $radius=(int)($width/2);
    for($i=0;$i<count($points)-1;$i++){
        imagesetthickness($im,$width);
        imageline($im,$points[$i][0],$points[$i][1],$points[$i+1][0],$points[$i+1][1],$color);
    }
    foreach($points as [$x,$y]) imagefilledellipse($im,$x,$y,$width,$width,$color);
    imagesetthickness($im,1);
}

$bgTop = rgb('#120719');
$bgBottom = rgb('#241037');
for ($y=0; $y<$size; $y++) {
    $t=$y/max(1,$size-1);
    $r=(int)round($bgTop[0]*(1-$t)+$bgBottom[0]*$t);
    $g=(int)round($bgTop[1]*(1-$t)+$bgBottom[1]*$t);
    $b=(int)round($bgTop[2]*(1-$t)+$bgBottom[2]*$t);
    $c=imagecolorallocate($im,$r,$g,$b);
    imageline($im,0,$y,$size,$y,$c);
}

$x=54*$S; $y=66*$S; $w=404*$S; $h=380*$S; $r=72*$S;
roundedRect($im,$x,$y,$w,$h,$r,col($im,'#8b35ee'));
imagefilledrectangle($im,$x+$r,$y,$x+$w-$r,$y+132*$S,col($im,'#b760ff',35));
imagefilledrectangle($im,$x+$r,$y+286*$S,$x+$w-$r,$y+$h,col($im,'#55169f',42));

$notch = 35*$S;
$bgCenter=col($im,'#1b0b2a');
imagefilledellipse($im,$x,$y+(int)($h/2),$notch*2,$notch*2,$bgCenter);
imagefilledellipse($im,$x+$w,$y+(int)($h/2),$notch*2,$notch*2,$bgCenter);

$p1=cubic([363*$S,101*$S],[285*$S,80*$S],[201*$S,93*$S],[163*$S,141*$S],70);
$p2=cubic([163*$S,141*$S],[126*$S,187*$S],[158*$S,228*$S],[235*$S,259*$S],60);
$p3=cubic([235*$S,259*$S],[320*$S,294*$S],[377*$S,324*$S],[363*$S,375*$S],60);
$p4=cubic([363*$S,375*$S],[349*$S,424*$S],[281*$S,440*$S],[193*$S,414*$S],70);
$points=array_merge($p1,array_slice($p2,1),array_slice($p3,1),array_slice($p4,1));
thickPath($im,$points,78*$S,col($im,'#2a0d3a'));

$final=imagecreatetruecolor(512,512);
imagealphablending($final,true);
imagecopyresampled($final,$im,0,0,0,0,512,512,$size,$size);
imagepng($final,$out,8);
imagedestroy($final);
imagedestroy($im);

if (!is_file($out) || filesize($out) < 1000) {
    fwrite(STDERR, "Falha ao gerar o ícone.\n");
    exit(1);
}

echo $out."\n";
