<!DOCTYPE html>
<html>
<head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <title>HTML5 MediaElement</title>

        <script src="mediaelement/build/jquery.js"></script>
        <script src="mediaelement/build/mediaelement-and-player.min.js"></script>
        <link rel="stylesheet" href="mediaelement/build/mediaelementplayer.min.css" />
</head>
<body>

<video style="width: 1080px;" src="mp4-streamer.php?file=<?=$_GET['file']?>" type="video/mp4" id="player1"  controls="controls" preload="none">
<!-- Flash fallback for non-HTML5 browsers without JavaScript -->
    <object width="320" height="240" type="application/x-shockwave-flash" data="mediaelement/build/flashmediaelement.swf">
        <param name="movie" value="flashmediaelement.swf" />
        <param name="flashvars" value="mp4-streamer.php?file=<?=$_GET['file']?>" />
    </object>
</video>
</body>
</html>
