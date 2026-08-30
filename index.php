<?php
/* Server-rendered upcoming events.

   Until 2026-08-26 this file was index.html and the listing existed only after
   events.js ran, so the page source carried no dates at all — an LLM or a
   non-rendering crawler saw "Upcoming dates are listed on Eventbrite" and
   nothing else. The events are now rendered here, from the same 15-minute cache
   api/events.php serves, plus schema.org JSON-LD.

   ⚠️ THIS BLOCK MUST NEVER BE ABLE TO TAKE THE HOMEPAGE DOWN. Before this
   change a PHP fault cost the events listing; now it could cost the whole page,
   which is a real increase in blast radius. Hence the catch-all: on any failure
   $eventsHtml and $eventsJsonLd stay empty and the static #eventsFallback below
   is what renders — exactly the behaviour this page had yesterday.

   \Throwable catches PHP 7 Errors as well as Exceptions; a bare `catch
   (Exception)` would let a TypeError through and blank the page. It also
   catches a ParseError from a broken include — verified, and contrary to the
   usual folklore: since PHP 7 a parse error in an INCLUDED file is a throwable
   ParseError, not an unreachable E_COMPILE_ERROR. A parse error in THIS file is
   still fatal and uncatchable, which is what the pre-deploy lint is for. */
/* ⚠️ SET HEADERS FIRST. Anything below that prints — a PHP warning from a
   missing include, with display_errors on — would be output, and a header()
   after output is "headers already sent" plus a broken doctype. Matches
   api/events.php: the listing must not be edge-cached, because an event pulled
   from sale has to disappear on the next request, not fifteen minutes later. */
header('Cache-Control: no-store, max-age=0');

$eventsHtml   = '';
$eventsJsonLd = '';
try {
    /* include, not require: a failed require is an E_COMPILE_ERROR that no
       catch block can reach, which would break the promise made above. A failed
       include is a warning, and the function_exists guard then turns it into
       the ordinary empty-listing path. */
    foreach (array('/api/events-lib.php', '/api/events-render.php') as $lib) {
        /* is_readable first: a plain include of a missing file PRINTS a warning,
           which becomes output before the doctype. Verified in php-wasm. */
        if (!is_readable(__DIR__ . $lib)) {
            throw new RuntimeException('missing ' . $lib);
        }
        include_once __DIR__ . $lib;
    }
    if (!function_exists('gsb_get_events') || !function_exists('gsb_render_event_list')) {
        throw new RuntimeException('events library unavailable');
    }
    $gsb = gsb_get_events();
    if ($gsb['ok'] && count($gsb['events'])) {
        $eventsHtml   = gsb_render_event_list($gsb['events']);
        $eventsJsonLd = gsb_events_jsonld($gsb['events']);
    }
} catch (\Throwable $e) {
    $eventsHtml   = '';
    $eventsJsonLd = '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Glasgow Soundbath</title>
  <meta name="description" content="Glasgow Soundbath offers guided sound bath sessions across Glasgow and surrounding areas, using gongs and singing bowls to support deep relaxation and rest. All welcome.">

  <!-- Preconnects for faster first byte -->
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="dns-prefetch" href="//fonts.gstatic.com">

  <!-- Poster first paint -->
  <link rel="preload" as="image" href="/assets/video-poster.webp">

  <!-- CSS -->
<link rel="stylesheet" href="/styles.css?v=20260206c">
<link rel="stylesheet" href="/events.css?v=202608211609">

  <!-- Fonts (optional, can be removed to save bytes) -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&display=swap" rel="stylesheet">

  <!-- Icons -->
  <link rel="icon" href="/assets/favicon.png" sizes="512x512" />
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png" sizes="180x180" />

  <!-- Canonical -->
  <link rel="canonical" href="https://www.glasgowsoundbath.com/" />

  <!-- Social -->
  <meta property="og:title" content="Glasgow Soundbath" />
  <meta property="og:description" content="Immersive soundbaths across Glasgow, a gentle return to stillness." />
  <meta property="og:image" content="https://www.glasgowsoundbath.com/assets/og.jpg" />
  <meta property="og:url" content="https://www.glasgowsoundbath.com/" />
  <meta property="og:type" content="website" />
  <meta name="twitter:card" content="summary_large_image" />

  <!-- Event structured data. This is what earns a Google event rich result and
       what an LLM reads first; it is generated from the same events rendered
       below, so the two cannot disagree. -->
  <?php echo $eventsJsonLd; ?>
</head>
<body>

  <!-- Background video, mobile-first with watchdog -->
  <video
    id="bg-video"
    class="bg-video"
    autoplay
    muted
    loop
    playsinline
    preload="metadata"
    poster="/assets/video-poster.webp"
    data-src-ultra="/assets/sea_ultra.mp4"
    data-src-desktop="/assets/sea.mp4"
    data-src-mobile="/assets/sea_mobile.mp4">
  </video>

  <div class="veil"></div>

  <!-- Top-right menu -->
  <div class="topbar">
    <button class="menu-btn icon-circle" id="menuBtn"
            aria-haspopup="true" aria-expanded="false"
            aria-label="Open menu">
      <span class="bar"></span>
      <span class="bar"></span>
      <span class="bar"></span>
    </button>

    <nav class="menu" id="menu" role="menu" aria-label="Site menu">
      <a href="#about"   role="menuitem">About</a>
      <a href="#events"  role="menuitem">Upcoming dates</a>
      <a href="#gallery" role="menuitem">Gallery</a>
      <a href="#contact" role="menuitem">Contact</a>
    </nav>
  </div>

  <!-- Hero -->
  <header class="hero-landing" id="top">
    <h1 class="hero-logo">
      <span class="line-1">Glasgow</span>
      <span class="line-2">Soundbath</span>
    </h1>
  </header>

  <main class="content">
   
  <p class="sr-only">
    Glasgow Soundbath offers guided sound bath sessions across Glasgow and surrounding areas, led by sound artist Iain Ross.
  </p>


    <!-- About -->
    <section id="about" class="section">
      <h2>About</h2>
      <p>
        Soundbaths in beautiful spaces across the city and beyond. Led by sound artist and musician Iain Ross,
        these immersive live sound experiences invite rest, presence, and gentle reconnection,
        with gongs, bowls, and quiet resonance held in still, spacious settings. Open to all.
        No experience necessary. A soft return to stillness.
      </p>
    </section>

     <!-- Upcoming Events -->
   <section id="events" class="section">
  <h2>Upcoming Events</h2>
  <div class="embed-wrap" id="eventsWrap" aria-live="polite"
       aria-busy="<?php echo $eventsHtml === '' ? 'true' : 'false'; ?>"
       <?php if ($eventsHtml !== '') { echo 'data-server-rendered="1" data-ready="1"'; } ?>>
<?php if ($eventsHtml !== ''): ?>
  <?php echo $eventsHtml; ?>
<?php else: ?>
  <div id="eventsSkeleton" class="events-skeleton" role="status">
    <div class="spinner" aria-hidden="true"></div>
    <p class="loading-text">Listings loading…</p>
    <p class="hint">If this takes too long you can
      <a href="https://glasgowsoundbath.eventbrite.com" target="_blank" rel="noopener">open the listings on Eventbrite</a>.
    </p>
  </div>

  <!-- Static fallback: present without JavaScript, and what remains if the
       server render above produced nothing. It has already earned its keep. -->
  <div class="events-fallback" id="eventsFallback">
    <p>Upcoming dates are listed on Eventbrite.</p>
    <a class="events-cta" href="https://glasgowsoundbath.eventbrite.com" target="_blank" rel="noopener">See all dates and book on Eventbrite</a>
  </div>
<?php endif; ?>
</div>

<p class="events-more" role="note">
    You can also view all dates on
    <a href="https://glasgowsoundbath.eventbrite.com" target="_blank" rel="noopener">
      my Eventbrite organiser page
    </a>
  </p>

</section>
    <!-- Gallery -->
    <section id="gallery" class="section">
      <h2>Event Gallery</h2>

      <div class="gallery" id="galleryGrid">
        <!-- Thumbs in the grid, originals in data-full -->
        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/arlington.webp" width="1200" height="1200"
               data-full="/assets/gallery/arlington.JPG"
               alt="Iain performing with crystal bowls in a bright hall, large gong behind." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/boa.webp" width="1200" height="1200"
               data-full="/assets/gallery/boa.jpg"
               alt="Room with red light, crystal bowls and candle centered, gong behind." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/breath.webp" width="1200" height="1200"
               data-full="/assets/gallery/breath.JPG"
               alt="Moody long exposure of a soundbath setup with teal and red trails." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/govanhill_atmos.webp" width="1200" height="1200"
               data-full="/assets/gallery/govanhill_atmos.JPG"
               alt="Atmospheric wide shot in a church hall with arched windows." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/govanhill_participant.webp" width="1200" height="1200"
               data-full="/assets/gallery/govanhill_participant.jpg"
               alt="Participant view from a mat, bowls and people softly out of focus." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/govanhill.webp" width="1200" height="1200"
               data-full="/assets/gallery/govanhill.JPG"
               alt="Iain performing under teal lighting with gong and chimes." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/skypark_group.webp" width="1200" height="1200"
               data-full="/assets/gallery/skypark_group.JPG"
               alt="Group soundbath in a high-floor space with city views." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/soundplay.webp" width="1200" height="1200"
               data-full="/assets/gallery/soundplay.JPG"
               alt="Side angle of crystal bowls, suspended gong above the setup." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/stmts.webp" width="1200" height="1200"
               data-full="/assets/gallery/stmts.JPG"
               alt="Community setting, crystal bowls on a patterned rug and a gong." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/hyndland.webp" width="1200" height="1200"
               data-full="/assets/gallery/hyndland.jpg"
               alt="Sunlight through arched windows onto a rug with bowls in a quiet Hyndland hall." />
        </figure>

        <figure class="card">
          <img loading="lazy" decoding="async"
               src="/assets/gallery/thumbs/nm.webp" width="1200" height="1200"
               data-full="/assets/gallery/nm.jpg"
               alt="Iain playing crystal bowls beneath a large Paiste gong in a bright white hall." />
        </figure>

        <!-- Video tile with SVG play badge -->
        <figure class="card video">
          <button class="lb-trigger" data-type="video" data-src="/assets/gallery/hub_video.mp4" aria-label="Play video">
            <video muted playsinline preload="metadata"
                   poster="/assets/gallery/thumbs/hub_video_poster.webp"
                   src="/assets/gallery/hub_video.mp4"></video>
            <svg aria-hidden="true" viewBox="0 0 80 80" class="play-badge">
              <defs><filter id="pb-blur"><feGaussianBlur stdDeviation="1.4"/></filter></defs>
              <circle cx="40" cy="40" r="30" fill="rgba(0,0,0,.55)" filter="url(#pb-blur)"/>
              <polygon points="32,26 32,54 54,40" fill="rgba(255,255,255,.98)"/>
            </svg>
          </button>
        </figure>
      </div>
    </section>

    <!-- Contact -->
    <section id="contact" class="section">
      <h2>Contact</h2>
      <p>Email <a href="mailto:info@glasgowsoundbath.com">info@glasgowsoundbath.com</a></p>
      <p>Instagram <a href="https://instagram.com/glasgowsoundbath" target="_blank" rel="noopener">@glasgowsoundbath</a></p>
    </section>
  </main>

  <footer class="site-footer">
    <p>© <span id="year"></span> Glasgow Soundbath · <a href="/privacy">Privacy</a></p>
  </footer>

  <!-- Floating sound button -->
  <button id="soundFab" class="sound-fab" aria-pressed="false" aria-label="Toggle sound">
    <span class="icon-wave" aria-hidden="true"></span>
    <span class="sound-label">Sound</span>
  </button>

  <!-- Site JS -->
<script src="script.js?v=202608232016" defer></script>
<script src="/events.js?v=202608260949" defer></script>
</body>
</html>
