<?php
require_once __DIR__ . '/../config/app.php';

$introVideoUrl = htmlspecialchars(tv_asset_url('videos/intro.mp4'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<title>TV Intro</title>

<style>

html,
body {
    width: 100%;
    height: 100%;
    margin: 0;
    overflow: hidden;
    background: #000;
}

.intro-slide {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    background: #000;
}

#introVideo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center center;
    display: block;
    background: #000;
}

</style>
</head>
<body>

<div class="intro-slide">

    <video
        id="introVideo"
        autoplay
        muted
        playsinline
        preload="auto"
    >
        <source
            src="<?= $introVideoUrl ?>"
            type="video/mp4"
        >
    </video>

</div>

<script>

(function () {

    const video =
        document.getElementById(
            'introVideo'
        );

    let completed = false;

    function notifyParent() {

        if (completed) {
            return;
        }

        completed = true;

        window.parent.postMessage(
            {
                type: 'slideComplete',
                slide: 'intro'
            },
            '*'
        );
    }

    video.addEventListener(
        'ended',
        notifyParent
    );

    video.addEventListener(
        'error',
        function () {

            setTimeout(
                notifyParent,
                7000
            );

        }
    );

    setTimeout(
        function () {

            if (
                video.readyState === 0
            ) {
                notifyParent();
            }

        },
        7000
    );

})();
</script>

</body>
</html>
