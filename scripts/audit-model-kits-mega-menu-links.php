<?php

declare(strict_types=1);

$paths = [
    '/collections/latest-arrivals', '/collections/gunpla', '/collections/beginner-kits', '/collections/entry-grade-eg',
    '/collections/sd-gundam', '/collections/sd-ex-standard', '/collections/sd-cross-silhouette', '/collections/sd-world-heroes',
    '/collections/sd-bb-senshi', '/collections/sd-g-generation', '/collections/sd-build-fighters', '/collections/sd-gunpla-kun',
    '/collections/action-base', '/collections/high-grade-hg', '/collections/hg-universal-century', '/collections/hg-gundam-seed',
    '/collections/hg-after-colony', '/collections/hg-iron-blooded-orphans', '/collections/hg-build-fighters', '/collections/hg-build-divers',
    '/collections/real-grade-rg', '/collections/master-grade-mg', '/collections/mg-standard', '/collections/mg-ver-ka',
    '/collections/mgex', '/collections/master-grade-sd-mgsd', '/collections/perfect-grade-pg', '/collections/gunpla-option-parts',
    '/collections/gundam-universal-century', '/collections/gundam-alternate-universes', '/collections/gundam-other-uc-series',
    '/collections/gundam-other-au-series', '/collections/gundam-mobile-suit-gundam', '/collections/gundam-zeta', '/collections/gundam-zz',
    '/collections/gundam-chars-counterattack', '/collections/gundam-0080', '/collections/gundam-0083', '/collections/gundam-seed',
    '/collections/gundam-wing', '/collections/gundam-00', '/collections/gundam-iron-blooded-orphans', '/collections/gundam-witch-from-mercury',
    '/collections/gundam-unicorn', '/collections/g-gundam', '/collections/gundam-build-fighters', '/collections/gundam-build-divers',
    '/collections/gundam-age', '/collections/gundam-hathaway', '/collections/30-minutes-missions', '/collections/30-minutes-sisters',
    '/collections/30-minutes-fantasy', '/collections/30-minutes-preference', '/collections/30-minutes-accessories', '/collections/pokemon',
    '/collections/kotobukiya', '/collections/moderoid', '/collections/keroro', '/collections/snaa', '/collections/mechatrowego',
    '/collections/plamax', '/collections/evangelion', '/collections/other-series', '/collections/doraemon', '/collections/mazinger',
    '/collections/getter-robo', '/collections/kotetsu-jeeg', '/collections/patlabor', '/collections/macross-delta',
    '/collections/armored-trooper-votoms', '/collections/sakura-wars', '/collections/linebarrels-of-iron', '/collections/eureka-seven',
    '/collections/one-piece', '/collections/super-robot-wars', '/collections/armored-core',
];

$base = 'https://operationvstudio.com';
$preview = '?preview_theme_id=196218716241';
$pass = 0;
$fail = [];

foreach ($paths as $i => $path) {
    $url = $base.$path.$preview;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'OVS-MegaMenuLinkAudit/1.0',
    ]);
    $html = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $title = '';
    if (is_string($html) && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
        $title = trim(html_entity_decode($m[1]));
    }

    $is404 = $status === 404 || stripos($title, '404') !== false || stripos($title, 'not found') !== false;
    $isSearch = str_contains($path, '/search') || (is_string($html) && str_contains($html, 'templates/search'));
    $hasGrid = is_string($html) && (str_contains($html, 'ProductGridContainer') || str_contains($html, 'collection--empty'));
    $ok = $status === 200 && ! $is404 && ! $isSearch && $hasGrid;

    if ($ok) {
        $pass++;
    } else {
        $fail[] = [
            'path' => $path,
            'status' => $status,
            'title' => $title,
            'reason' => implode(',', array_filter([
                $status !== 200 ? "http-{$status}" : null,
                $is404 ? '404' : null,
                $isSearch ? 'search' : null,
                ! $hasGrid ? 'no-grid' : null,
            ])),
        ];
    }

    if ($i < count($paths) - 1) {
        usleep(900_000);
    }
}

// Extract live mega menu hrefs from homepage
$homeCh = curl_init($base.'/'.$preview);
curl_setopt_array($homeCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
$homeHtml = curl_exec($homeCh);
curl_close($homeCh);

$domHrefs = [];
if (is_string($homeHtml) && preg_match('/<div class="ovs-model-mega[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>/s', $homeHtml, $block)) {
    preg_match_all('/href="(\/collections\/[^"#?]+)"/', $block[1], $m);
    $domHrefs = array_values(array_unique($m[1] ?? []));
}

$expected = array_values(array_unique($paths));
sort($expected);
$domSorted = $domHrefs;
sort($domSorted);
$missingInDom = array_values(array_diff($expected, $domSorted));
$extraInDom = array_values(array_diff($domSorted, $expected));

echo json_encode([
    'total' => count($paths),
    'pass' => $pass,
    'failCount' => count($fail),
    'failures' => $fail,
    'domLinkCount' => count($domHrefs),
    'missingInDom' => $missingInDom,
    'extraInDom' => $extraInDom,
], JSON_PRETTY_PRINT).PHP_EOL;

exit($fail === [] && $missingInDom === [] ? 0 : 1);
