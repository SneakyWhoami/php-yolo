#!/usr/bin/env php7.4
<?php

use FFI\CData;


function bbox2obj(CData $box)
{
    $t = &$box;
    return (object)['x' => $t->x, 'y' => $t->y, 'w' => $t->w, 'h' => $t->h];
}

function detection2object(CDATA $det, $k, $v)
{
    return (object)['label' => $v, 'confidence' => $det->prob[$k], 'box' => bbox2obj($det->bbox)];
}

function detect($darknet, $net, $filename, $meta, $num_dets, $thresh = .5, $hier_thresh = .5, $nms = .45)
{
    // don't have to specify width or height so i've used 0
    $img = $darknet->load_image_color($filename, 0, 0);
    // this is where the actual detection happens
    $darknet->network_predict_image($net, $img);

    // this list of results has lots of noise but it's quite spatially efficient
    $detections = $darknet->get_network_boxes($net, $img->w, $img->h, $thresh, $hier_thresh, null, 0, FFI::addr($num_dets));

    // it's a multi-sort and it seems to remove redundancy in results
    $darknet->do_nms_sort($detections, $num_dets->cdata, $meta->classes, $nms);
    return $detections;
}

function box2gd(object $b)
{
    $h = $b->h / 2;
    $w = $b->w / 2;
    $x = $b->x;
    $y = $b->y;
    return (object)['x1' => $x - $w, 'y1' => $y - $h, 'x2' => $x + $w, 'y2' => $y + $h];
}

function drawboxes($filename, $inp)
{
    ob_start();
    $im = imagecreatefromjpeg($filename);
    $red = imagecolorallocate($im, 255, 0, 0);
    foreach ($inp as $i) {
        $b = box2gd($i->box);
        imagerectangle($im, $b->x1, $b->y1, $b->x2, $b->y2, $red);
    }
    imagejpeg($im);
    imagedestroy($im);
    $res = ob_get_contents();
    ob_end_clean();
    return $res;
}

$darknet = FFI::load(__DIR__ . "/dn_api.h");

// actual net
$configPath = "cfg/yolov3.cfg";
// weights for the net
$weightPath = "yolov3.weights";
// names of items in net
$metaPath = "cfg/coco.data";
$net = $darknet->load_network($configPath, $weightPath, 0);
$meta = $darknet->get_metadata($metaPath);
// for convenience, i put all the meta classes (person, bike, dog, chair...) into php array
$classes = [];
for ($i = 0; $i < $meta->classes; $i++) {
    $classes[$i] = FFI::string($meta->names[$i]);
}

$filenames = glob('in/*.jpg');

foreach ($filenames as $filename) {
    // $int will hold the number of detections. We give its pointer to get detections's length
    $num_dets = FFI::new('int32_t');
    $detections = detect($darknet, $net, $filename, $meta, $num_dets, 0.5, 0.5, 0.45);

    $out = [];
    for ($ob = 0; $ob < $num_dets->cdata; $ob++) {
        //var_dump($detections[$ob]);
        foreach ($classes as $k => $v) {
            if ($detections[$ob]->prob[$k]) {
                $out[] = detection2object($detections[$ob], $k, $v);
            }
        }
    }
    $darknet->free_detections($detections, $num_dets->cdata);

    var_dump(json_encode($out));
    $im = drawboxes($filename, $out);
    $filename = basename($filename);
    file_put_contents("out/$filename", $im);
    $filename = escapeshellarg($filename);
    `display out/$filename`;
}






