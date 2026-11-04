<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Ical\IcalFetcher;
use Illuminate\Support\Facades\Http;

const FETCH_FEED_URL = 'https://calendar.example.com/feed.ics?s=fict';


function fetchErrorCode(string $url): IcalErrorCode
{
    try {
        app(IcalFetcher::class)->fetch($url);
    } catch (IcalFetchException $e) {
        return $e->errorCode;
    }

    throw new RuntimeException('The fetch should have failed.');
}

beforeEach(function () {
    Http::preventStrayRequests();

    fakeDns([
        'calendar.example.com' => ['52.10.20.30'],
        'cdn.example.com' => ['52.10.20.31'],
        'internal.example.com' => ['10.0.0.5'],
        'mixed.example.com' => ['52.10.20.32', '127.0.0.1'],
        'missing.example.com' => [],
    ]);
});

it('downloads a feed from a public https url', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);

    expect(app(IcalFetcher::class)->fetch(FETCH_FEED_URL))
        ->toBe(icalFixture('airbnb-valid.ics'));

    Http::assertSentCount(1);
});

it('rejects urls that are not public https', function (string $url) {
    Http::fake();

    expect(fetchErrorCode($url))->toBe(IcalErrorCode::UrlNotAllowed);

    Http::assertNothingSent();
})->with([
    'http' => 'http://calendar.example.com/feed.ics',
    'other port' => 'https://calendar.example.com:8443/feed.ics',
    'credentials' => 'https://user:secret@calendar.example.com/feed.ics',
    'not a url' => 'calendario',
]);

it('rejects hosts on private or local networks', function (string $url) {
    Http::fake();

    expect(fetchErrorCode($url))->toBe(IcalErrorCode::UrlNotAllowed);

    Http::assertNothingSent();
})->with([
    'loopback' => 'https://127.0.0.1/feed.ics',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'ipv6 loopback' => 'https://[::1]/feed.ics',
    'name resolving to a private ip' => 'https://internal.example.com/feed.ics',
    'name with one private ip' => 'https://mixed.example.com/feed.ics',
]);

it('follows a redirect to another public host', function () {
    Http::fake([
        'calendar.example.com/*' => Http::response('', 302, ['Location' => 'https://cdn.example.com/feed.ics']),
        'cdn.example.com/*' => Http::response(icalFixture('airbnb-valid.ics')),
    ]);

    expect(app(IcalFetcher::class)->fetch(FETCH_FEED_URL))
        ->toBe(icalFixture('airbnb-valid.ics'));

    Http::assertSentCount(2);
});

it('does not follow a redirect to a private network', function () {
    Http::fake([
        'calendar.example.com/*' => Http::response('', 302, ['Location' => 'https://internal.example.com/admin']),
    ]);

    expect(fetchErrorCode(FETCH_FEED_URL))->toBe(IcalErrorCode::UrlNotAllowed);

    Http::assertSentCount(1);
});

it('gives up after too many redirects', function () {
    Http::fake([
        'calendar.example.com/*' => Http::response('', 302, ['Location' => FETCH_FEED_URL]),
    ]);

    expect(fetchErrorCode(FETCH_FEED_URL))->toBe(IcalErrorCode::UrlNotAllowed);

    Http::assertSentCount(IcalFetcher::MAX_REDIRECTS + 1);
});

it('fails when the server answers with an error', function () {
    Http::fake(['calendar.example.com/*' => Http::response('Not found', 404)]);

    expect(fetchErrorCode(FETCH_FEED_URL))->toBe(IcalErrorCode::FetchFailed);
});

it('fails when the server cannot be reached', function () {
    Http::fake(['calendar.example.com/*' => Http::failedConnection()]);

    expect(fetchErrorCode(FETCH_FEED_URL))->toBe(IcalErrorCode::FetchFailed);
});

it('fails when the host does not exist', function () {
    Http::fake();

    expect(fetchErrorCode('https://missing.example.com/feed.ics'))->toBe(IcalErrorCode::FetchFailed);

    Http::assertNothingSent();
});

it('rejects feeds larger than the limit', function () {
    Http::fake([
        'calendar.example.com/*' => Http::response(str_repeat('A', IcalFetcher::MAX_BYTES + 1)),
    ]);

    expect(fetchErrorCode(FETCH_FEED_URL))->toBe(IcalErrorCode::FeedTooLarge);
});