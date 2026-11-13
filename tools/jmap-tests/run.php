<?php
/*
 * JMAP regression tests. Run: php tools/jmap-tests/run.php
 * Uses real Z-Push sync objects and an in-memory JMAP client; no server needed.
 * Released under the GNU Affero General Public License (AGPL), version 3.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
date_default_timezone_set('UTC');
define('TIMEZONE', 'UTC');
define('SYNC_CONTACTS_MAXPICTURESIZE', 49152);
$src = dirname(__DIR__, 2) . '/src';
set_include_path($src . PATH_SEPARATOR . get_include_path());
require_once $src . '/lib/core/zpushdefs.php';

// Avoid request/environment setup and filesystem logging in the test process.
class Request {
    public static function GetProtocolVersion() { return '14.0'; }
}
class ZLog {
    public static function Write($level, $message, $truncate = true) {}
}

$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/lib')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $classes[strtolower($file->getBasename('.php'))] = $file->getPathname();
    }
}
$classes['backenddiff'] = $src . '/lib/default/diffbackend/diffbackend.php';
spl_autoload_register(function ($class) use ($classes) {
    if (isset($classes[strtolower($class)])) {
        require_once $classes[strtolower($class)];
    }
});
require_once $src . '/backend/jmap/jmap.php';

class TestJmapClient extends JmapClient {
    public array $calls = [];
    public array $downloads = [];
    public array $responses = [];
    public array $blobs = [];

    public function __construct() {
        parent::__construct('https://example.invalid/jmap/session', 'test', 'test');
    }

    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        $this->calls[] = $methodCalls;
        if (!$this->responses) throw new RuntimeException('Unexpected JMAP request');
        return array_shift($this->responses);
    }

    public function downloadBlob(string $blobId, string $name = 'attachment', string $accept = 'application/octet-stream', ?string $accountId = null): string {
        $this->downloads[] = [$blobId, $accountId, $accept];
        if (!isset($this->blobs[$blobId])) throw new RuntimeException('Blob not found');
        return $this->blobs[$blobId];
    }
}

function backend(TestJmapClient $client): BackendJmap {
    $backend = new BackendJmap();
    foreach (['client' => $client, 'accountId' => 'mail-account', 'contactsAccountId' => 'contact-account'] as $name => $value) {
        (new ReflectionProperty(BackendJmap::class, $name))->setValue($backend, $value);
    }
    return $backend;
}

function invokeBackend(BackendJmap $backend, string $method, ...$args) {
    return (new ReflectionMethod(BackendJmap::class, $method))->invoke($backend, ...$args);
}

function same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function cardResponse(array $card): array {
    return [['ContactCard/get', ['accountId' => 'contact-account', 'list' => [$card]], '0']];
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$card = ['id' => 'c1', 'name' => ['full' => 'Test', 'components' => [['kind' => 'given', 'value' => 'Test']]]];
$media = [
    'logo' => ['kind' => 'logo', 'blobId' => 'logo-blob'],
    'photo-key' => ['kind' => 'photo', 'blobId' => 'photo-blob', 'mediaType' => 'image/png'],
    'sound' => ['kind' => 'sound', 'blobId' => 'sound-blob'],
];

$tests = [];
$tests['blob photo uses the response account and skips logos'] = function () use ($png, $card, $media) {
    $client = new TestJmapClient();
    $client->responses[] = cardResponse($card + ['media' => $media]);
    $client->blobs['photo-blob'] = $png;
    $contact = invokeBackend(backend($client), 'getContact', 'c1');
    same(base64_encode($png), $contact->picture);
    same([['photo-blob', 'contact-account', 'image/png']], $client->downloads);
};
$tests['legacy data URI photo still works'] = function () use ($png, $card) {
    $client = new TestJmapClient();
    $client->responses[] = cardResponse($card + ['media' => [
        'p' => ['kind' => 'photo', 'uri' => 'data:image/png;base64,' . base64_encode($png)],
    ]]);
    same(base64_encode($png), invokeBackend(backend($client), 'getContact', 'c1')->picture);
    same([], $client->downloads);
};
$tests['missing blob does not prevent contact delivery'] = function () use ($card, $media) {
    $client = new TestJmapClient();
    $client->responses[] = cardResponse($card + ['media' => $media]);
    $contact = invokeBackend(backend($client), 'getContact', 'c1');
    same('Test', $contact->firstname);
    same(false, isset($contact->picture));
};
$tests['external photo URLs are not decoded or fetched'] = function () use ($card) {
    $client = new TestJmapClient();
    $client->responses[] = cardResponse($card + ['media' => [
        'p' => ['kind' => 'photo', 'uri' => 'https://example.invalid/image,YWJj'],
    ]]);
    same(false, isset(invokeBackend(backend($client), 'getContact', 'c1')->picture));
    same([], $client->downloads);
};
$tests['photo replacement removes stale blobs and preserves other media'] = function () use ($png, $card, $media) {
    $client = new TestJmapClient();
    $client->responses = [
        cardResponse($card + ['media' => $media + ['extra-photo' => ['kind' => 'photo', 'blobId' => 'old']]]),
        [['ContactCard/set', ['updated' => ['c1' => null]], '0']],
    ];
    $contact = new SyncContact();
    $contact->picture = base64_encode($png);
    same('c1', invokeBackend(backend($client), 'saveContact', 'ab1', 'c1', $contact));
    $saved = $client->calls[1][0][1]['update']['c1']['media'];
    same(['logo', 'sound', 'photo-key'], array_keys($saved));
    same($media['logo'], $saved['logo']);
    same($media['sound'], $saved['sound']);
    same(false, isset($saved['photo-key']['blobId']));
    same('data:image/png;base64,' . base64_encode($png), $saved['photo-key']['uri']);
};
$tests['clearing photo preserves logos and sounds'] = function () use ($card, $media) {
    $client = new TestJmapClient();
    $client->responses = [cardResponse($card + ['media' => $media]), [['ContactCard/set', ['updated' => ['c1' => null]], '0']]];
    $contact = new SyncContact();
    $contact->picture = '';
    invokeBackend(backend($client), 'saveContact', 'ab1', 'c1', $contact);
    same(['logo' => $media['logo'], 'sound' => $media['sound']], $client->calls[1][0][1]['update']['c1']['media']);
};
$tests['omitted photo preserves existing blobs'] = function () use ($card, $media) {
    $client = new TestJmapClient();
    $client->responses = [cardResponse($card + ['media' => $media]), [['ContactCard/set', ['updated' => ['c1' => null]], '0']]];
    $contact = new SyncContact();
    $contact->firstname = 'Renamed';
    invokeBackend(backend($client), 'saveContact', 'ab1', 'c1', $contact);
    same($media, $client->calls[1][0][1]['update']['c1']['media']);
};
$tests['photo identity participates in contact change detection'] = function () use ($card, $media) {
    $before = $card + ['media' => $media];
    $after = $before;
    $after['media']['photo-key']['blobId'] = 'replacement';
    same(false, JmapContactConverter::cardModHash($before) === JmapContactConverter::cardModHash($after));
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    }
}
echo count($tests) . " tests, $failed failures\n";
exit($failed ? 1 : 0);
