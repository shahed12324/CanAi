<?php

use App\Clients\ClientForm;
use App\Reports\ReportService;
use Tests\Support\FakeProposalEngine;
use Tests\Support\FakeReportRepo;

function clfpng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

function clfjpg(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDgqKKK8M/VD//Z');
}

it('accepts only real PNG or JPEG logos within 600 KB', function () {
    expect(ClientForm::logo(clfpng()))->toBe(base64_encode(clfpng()));
    expect(ClientForm::logo(clfjpg()))->toBe(base64_encode(clfjpg()));
    expect(ClientForm::logo(''))->toBeNull()->and(ClientForm::logo('GIF89a....'))->toBeNull()->and(ClientForm::logo("\x89PNG\r\n\x1a\n not really"))->toBeNull()->and(ClientForm::logo("\xFF\xD8\xFF garbage"))->toBeNull();
    expect(ClientForm::logo(clfpng().str_repeat('0', 600 * 1024)))->toBeNull();
    expect(ClientForm::logo(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')))->toBeNull();
    expect(ClientForm::logo('<svg xmlns="http://www.w3.org/2000/svg"/>'))->toBeNull();
});

it('normalises colours', function () {
    expect(ClientForm::colour('#AABB0c'))->toBe('#aabb0c')->and(ClientForm::colour('#abc'))->toBeNull()->and(ClientForm::colour('red'))->toBeNull()->and(ClientForm::colour("#aabbcc\n"))->toBeNull()->and(ClientForm::colour('#aabbccd'))->toBeNull();
});

it('has a Bengali message for every database result', function () {
    foreach (['save' => ['ok', 'exists', 'limit', 'missing'], 'archive' => ['ok', 'missing'], 'assign' => ['ok', 'account', 'client']] as $kind => $codes) {
        foreach ($codes as $c) {
            expect(ClientForm::message($kind, $c) !== 'The action could not be done.')->toBeTrue();
        }
    }
    expect(ClientForm::message('save', 'weird'))->toBe('The action could not be done.')->and(ClientForm::message('nope', 'ok'))->toBe('The action could not be done.');
    expect(ClientForm::message('save', 'exists') !== ClientForm::message('save', 'limit'))->toBeTrue();
});

function clfrow(): array
{
    return ['account_id' => 'A1', 'account' => 'Acc', 'currency' => 'BDT', 'synced_at' => '2027-01-15 09:00:00', 'campaign' => '111', 'name' => 'Eid', 'spend' => '100.00', 'impressions' => 5000, 'clicks' => 90, 'results' => '3'];
}

it('the report service passes the client to every read, and none when there is none', function () {
    $mk = function () {
        $repo = new FakeReportRepo;
        $repo->ads['meta:2027-01-08'] = [clfrow()];

        return [new ReportService($repo, new FakeProposalEngine), $repo];
    };
    [$s, $repo] = $mk();
    $b = $s->build(['kind' => 'week', 'language' => 'en', 'client_id' => 'C0000000000000000000000001'], 'Asia/Dhaka', true, new DateTimeImmutable('2027-01-15 10:00:00', new DateTimeZone('Asia/Dhaka')));
    expect($b['error'])->toBeNull()->and(count($repo->clients))->toBe(6)->and(array_unique($repo->clients))->toBe(['C0000000000000000000000001']);
    [$s, $repo] = $mk();
    $s->build(['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, new DateTimeImmutable('2027-01-15 10:00:00', new DateTimeZone('Asia/Dhaka')));
    expect(array_unique($repo->clients))->toBe([null]);
    [$s, $repo] = $mk();
    $s->build(['kind' => 'week', 'language' => 'en', 'client_id' => ''], 'Asia/Dhaka', false, new DateTimeImmutable('2027-01-15 10:00:00', new DateTimeZone('Asia/Dhaka')));
    expect(array_unique($repo->clients))->toBe([null]);
});
