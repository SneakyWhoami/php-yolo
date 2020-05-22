php-yolo
========

Intro
-----
Don't take this seriously. I spent more time documenting this than what I did writing it.
This repository shows a quick and dirty example of using libdarknet through php ffi.
It should be run with php7.4 in CLI. It does not take arguments as it's meant to provide a quick demo, nothing more.
It does object detection on images in the given directory.
It does draw the bounding boxes on copies of the images, which it saves, and then displays to you.
The point of this is so that you can see some things happening with php-ffi, such as:
 - arrays of arrays of char
 - funny structs
 - dirty hacks for clock time and file handles
 - passing things (an int) by reference into c functions


Pretty cool if you can show how to do more with this. I tried yolov4 originally but switched to 3 so that I could use things from Ubuntu repos.

Setup
-------
- install or upgrade to ubuntu focal (optional but assumed)
- install imagemagick-6.q16 or similar from repository (only for `display` command used in script)
- ensure you have php7.4 CLI - you will need ffi (to detect) and gd (to draw), but I think they're enabled by default
- install darknet from repository
- clone the repository
- `mkdir cfg data out`
- wget https://raw.githubusercontent.com/pjreddie/darknet/master/cfg/coco.data into cfg directory
- https://raw.githubusercontent.com/pjreddie/darknet/master/cfg/yolov3.cfg into cfg
- https://raw.githubusercontent.com/pjreddie/darknet/master/data/coco.names into data
- https://pjreddie.com/media/files/yolov3.weights into this directory

Use
---
Put some images into "in", and run the script in a terminal. They will be presented to you one-at-a-time and saved into out folder. It's as simple as that. Yay, it works.

Advanced or Alternative Use
---------------------------
If you understand what's happening here, go hack something! If you want to fool around with this, it's up to you to change it.

More Info
---------
dn_api.h is adapted from /usr/include/libdarknet.h (from darknet package). It tells php-ffi which symbols from /usr/lib/libdarknet.so (also provided by darknet deb) to find and hook into php.
This example was created mostly to try out talking directly to a library with only a small amount of glue code so that it explores a very slightly different perspective (cf php tensorflow with ffi, which is a work of art).
You can find yolo4 information (and a lot of yolo3 data) in AlexAB/darknet and the upstream pjreddie repository. I learned a lot by studying https://github.com/AlexeyAB/darknet/blob/master/darknet.py which uses python's cffi to do similar (but more comprehensive) work.

Bugs
----
I am not troubled by any bugs, as long as my script runs for you.

Licence
-------
To be clearish: I hereby release detect.php into the public domain, and where that's not possible, you are free to use it under the terms of the WTFPL or CC0. dn_api.h is included free of charge under the yolo licence since it's largely transcribed from the actual api header. So basically do what you want.
