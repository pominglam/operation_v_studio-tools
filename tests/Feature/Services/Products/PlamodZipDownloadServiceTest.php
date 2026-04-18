<?php

declare(strict_types=1);

use App\Services\Products\Http\PlamodScraper;
use App\Services\Products\PlamodZipDownloadService;

it('retries transient scraper errors and succeeds', function (): void {
    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper
        ->shouldReceive('downloadZip')
        ->times(2)
        ->with('5060001')
        ->andReturn(
            ['ok' => false, 'error_message' => 'timeout'],
            ['ok' => true, 'zip_storage_path' => 'plamod/raw_zips/5060001/x.zip'],
        );

    $svc = new PlamodZipDownloadService($scraper);
    $out = $svc->downloadZip('5060001');

    expect($out['ok'])->toBeTrue();
    expect($out['zip_storage_path'])->toBe('plamod/raw_zips/5060001/x.zip');
});

it('does not retry non-transient errors', function (): void {
    $scraper = Mockery::mock(PlamodScraper::class);
    $scraper
        ->shouldReceive('downloadZip')
        ->once()
        ->with('5060002')
        ->andReturn(['ok' => false, 'error_message' => 'Could not find "Download ZIP" button/link on Plamod PDP']);

    $svc = new PlamodZipDownloadService($scraper);
    $out = $svc->downloadZip('5060002');

    expect($out['ok'])->toBeFalse();
    expect($out['error_message'])->toContain('Download ZIP');
});
