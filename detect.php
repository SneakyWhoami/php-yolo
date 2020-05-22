#!/usr/bin/env php7.4
<?php

use FFI\CData;

// the box struct is accessed like a regular object,
// but if we just want the coords then this will express them a little more tersely.
// i found that the x and y values were in the middle of the box, not a corner.
function bbox2obj(CData $t)
{
    return (object)['x' => $t->x, 'y' => $t->y, 'w' => $t->w, 'h' => $t->h];
}

// the detection struct is a little opaque to php. i just want name, probability and boix. here it is
function detection2object(CDATA $det, $k, $v)
{
    // NB bbox2obj
    return (object)['label' => $v, 'confidence' => $det->prob[$k], 'box' => bbox2obj($det->bbox)];
}

// seems i have to go through several steps to "do a detection"
function detect($darknet, $net, $filename, $meta, $num_dets, $thresh = .5, $hier_thresh = .5, $nms = .45)
{
    // first, load the image. we don't HAVE to specify width or height so i've used 0
    $img = $darknet->load_image_color($filename, 0, 0);
    // this is where the actual detection happens
    $darknet->network_predict_image($net, $img);

    // the returned list of detections contains every possible item, which effectively means that sometimes an object is detected several times, overlapping
    // note the pass-by-reference on $num_dets because it's int* - we give pointer addr instead of value.
    $detections = $darknet->get_network_boxes($net, $img->w, $img->h, $thresh, $hier_thresh, null, 0, FFI::addr($num_dets));

    // this is some kind of sort, yeah, but $nms is a threshold which allows it to collapse similar objects.
    // So if $detections contained the same chair with 3 overlapping bboxes, this squishes those down into the one bbox like you hope for
    $darknet->do_nms_sort($detections, $num_dets->cdata, $meta->classes, $nms);

    // so we've fully processed our detections as far as the c bit goes and just spit it back out to parent scope
    return $detections;
}

// the bbox x and y coords are actually the center of the object.
// gd expects coords of the top left and bottom right corners (of a rect)
// this is just a little sugar to convert the bbox representation to the rect representation
function box2gd(object $b)
{
    $h = $b->h / 2;
    $w = $b->w / 2;
    $x = $b->x;
    $y = $b->y;
    return (object)['x1' => $x - $w, 'y1' => $y - $h, 'x2' => $x + $w, 'y2' => $y + $h];
}

// this takes a filename, loads the image into gd,
// draws each of the $inp boxes onto the image,
// and passes a string representation of the object (in jpg format) back to parent
// this bit is probably almost-word-for-word in php docs, but commented hard in case you've never used it
function drawboxes($filename, $inp)
{
    // gd always wants to print, so we capture the output in a buffer
    ob_start();
    // load the picture
    $im = imagecreatefromjpeg($filename);
    // refer to this colour so we don't have to create it every iteration
    $red = imagecolorallocate($im, 255, 0, 0);
    foreach ($inp as $i) {
        // get gd's rect's coords of the bbox from the ones we took from the bbox struct
        $b = box2gd($i->box);
        // draw the box on the image in the colour
        imagerectangle($im, $b->x1, $b->y1, $b->x2, $b->y2, $red);
    }
    // imagejpeg() actually sends the jpg stream to stdout. but we're in a buffer so you won't see it
    imagejpeg($im);
    // oho, destroying a resource, look at that
    imagedestroy($im);
    // copy the content of the output buffer and destroy it.
    $res = ob_get_contents();
    ob_end_clean();
    // so this string contains the output from above, namely the bytes for the jpg image we made with boxes drawn on it
    return $res;
}

// this is why you're here, probably.
// the header file is just where we declare what we want to allow ffi to look for in our object file.
// it's not identical to the original C API header from the lib, but it's very close.
$darknet = FFI::load(__DIR__ . "/dn_api.h");

// note that every time i use a method or property of $darknet i'm actually directly using something i declared in the header file
// so this means that $darknet is effectively our ffi handle on our darknet c api
// every time you see $darknet, we're talking through the ffi

// actual net
$configPath = "cfg/yolov3.cfg";
// weights for the net
$weightPath = "yolov3.weights";
// names of items in net
$metaPath = "cfg/coco.data";
// so $net and $meta are what computer will work with. This may take a while
$net = $darknet->load_network($configPath, $weightPath, 0);
// you want this bit so that you have names for the detected objects
$meta = $darknet->get_metadata($metaPath);
// for convenience, i put all the meta classes (person, bike, dog, chair...) into php array
// it's like [0=>'person', 1=>'bicycle', 2=>'horse'] or whatever.
// the indices are what we get as labels from the actual detector, so putting them in a native php hash simplifies our life
$classes = [];
// there's a pointer to a pointer lol i can't figure out how i'm supposed to iterate over it
// BUT "classes" is the number of items in the list of names, so we can just run over the array index
for ($i = 0; $i < $meta->classes; $i++) {
    // we don't know how long these strings (char arrays) are, but in C it's null terminated.
    // php-ffi provides this static convenience method which will read everything up to that null into a string.
    // so this saves you from having to iterate over the string yourself to convert it to a php type
    $classes[$i] = FFI::string($meta->names[$i]);
}

// if you want to load something else, this is where you are interested in hacking.
$filenames = glob('in/*.jpg');

// ie, for each "in/sample.jpg" or similar from the bash-style glob above
foreach ($filenames as $filename) {
    // $int will hold the number of detections.
    // We give its pointer, and later we find that the value has changed.
    // PHP has this in the form of passing arguments by reference (func(&arg))
    // with the FFI this means i can create my c type and give it a variable to handle it,
    // and then later on i can pass the pointer address instead of the value
    $num_dets = FFI::new('int32_t');
    // this is interesting, because watch. $num_dets functions like an object.
    // even though it looks like we're passing by value, php is recycling the reference to it.
    // this means i can pass it straight into the function like a value and php can figure it out.
    // we've just realised that c types don't appear as primitive literals - they're wrapped up like objects.
    $detections = detect($darknet, $net, $filename, $meta, $num_dets, 0.5, 0.5, 0.45);

    // so our list of detections is pretty cool but we don't need it after this.
    // we loop through it and just grab the bits we want
    // notice that although the $detections is technically iterable, i'm still using an old fashioned for loop.
    $out = [];
    // also see how i have to actually pull the value out of the int object instead of it just auto-casting or whatever
    for ($ob = 0; $ob < $num_dets->cdata; $ob++) {
        // see we're using that "native" php list of labels from earlier, it's tidy
        foreach ($classes as $k => $v) {
            // although i couldn't foreach() over the $detections, I can access them with the array accessor literals
            // here, if there's a chance we detected the thing, we grab the data we want
            // we're checking for a falsey value (eg 0),
            // but if we stay within the bounds of the array, remember we did give libdarknet a "threshold" earlier
            // it's still important though because libdarknet.so might be a black box that you don't know the insides of.
            // and we're accessing with array literals, but there's no check on bounds.
            // if you get five results, you can ask for the 10th. that's probably not good use of your time.
            if ($detections[$ob]->prob[$k]) {
                $out[] = detection2object($detections[$ob], $k, $v);
            }
        }
    }
    // why not get length of array??
    // sizeof() and count() don't do what you think they do if you give them c arrays. you should use FFI::sizeof().
    // libdarknet let us give it a pointer to an int so it could tell us the number of detections in the list.
    // nice.

    // so you know how you're supposed to free resources like when you use eg libgd...
    // well this is no exception. libdarknet wants you to release this when you're done with it.
    // there's a nice convenience function for doing so.
    $darknet->free_detections($detections, $num_dets->cdata);

    // just to give you a little visual representation in the terminal since my drawing function is rudimential
    var_dump(json_encode($out));
    // aforementioned drawing function gives us the jpg file contents
    $im = drawboxes($filename, $out);
    $filename = basename($filename);
    // copy those jpg file contents from this memory to that filesystem thing
    file_put_contents("out/$filename", $im);
    // open the written picture on screen with imagick so you can admire the rectangles
    $filename = escapeshellarg($filename);
    `display out/$filename`;
}


// so there you go. fixes are welcome. hopefully it just works.
// you will notice that it is very slow, especially on startup.
// - you want to daemonize it somehow if you call it repeatedly. manual says use preloading so you only have to parse the stuff once.
// - but woe unto him that uses this abomination in a long-lived process. There are far more mature projects on github that do this job and more.
// - also, i just commented out all the CUDA stuff because i can't do that. so it's almost certainly running in cpu mode, which means that even all the deep, dark C bits will be running SLOWLY
// but if you wanted to see some naive php-ffi that's baked into php7.4, here's my play with it
// grep this file for "$darknet" to see most of the ffi operations.






