<?php
/*
 * MIME fallback regressions. All message contents are synthetic.
 * Released under the GNU Affero General Public License (AGPL), version 3.
 */

function emptyMail(): array {
    return [
        'id' => 'm1', 'blobId' => 'mime-blob', 'subject' => 'Test',
        'textBody' => [], 'htmlBody' => [], 'bodyValues' => [], 'attachments' => [],
    ];
}

function fallbackBody(string $mime, array $preferences, ?array $email = null): array {
    $client = new TestJmapClient();
    $client->blobs['mime-blob'] = $mime;
    $result = invokeBackend(backend($client), 'selectBody', $email ?? emptyMail(), $preferences);
    same(1, count($client->downloads), 'Download raw MIME at most once');
    return $result;
}

$legacyMime = "Subject: Test\r\nContent-Type: text/plain; charset=iso-8859-1\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\ncaf" . chr(233) . " & tea";

$tests['HTML-only fallback decodes legacy MIME rather than sending raw bytes'] = function () use ($legacyMime) {
    [$type, $body] = fallbackBody($legacyMime, [SYNC_BODYPREFERENCE_HTML]);
    same(SYNC_BODYPREFERENCE_HTML, $type);
    same(true, str_contains($body, 'café &amp; tea'));
    same(1, preg_match('//u', $body));
    same(false, str_contains($body, 'Content-Type:'));
};
$tests['plain-only fallback decodes transfer encoding and charset'] = function () {
    [$type, $body] = fallbackBody(
        "Content-Type: text/plain; charset=windows-1252\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nPrice =80; caf=E9",
        [SYNC_BODYPREFERENCE_PLAIN]
    );
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('Price €; café', $body);
};
$tests['plain-only request converts an HTML JMAP body'] = function () {
    $email = emptyMail();
    $email['htmlBody'] = [['partId' => 'h']];
    $email['bodyValues']['h'] = ['value' => '<p>Café &amp; tea</p>'];
    $client = new TestJmapClient();
    [$type, $body] = invokeBackend(backend($client), 'selectBody', $email, [SYNC_BODYPREFERENCE_PLAIN]);
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('Café & tea', $body);
    same([], $client->downloads);
};
$tests['HTML-only request escapes a plain JMAP body'] = function () {
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => 'Hello <friend> & team'];
    $client = new TestJmapClient();
    [$type, $body] = invokeBackend(backend($client), 'selectBody', $email, [SYNC_BODYPREFERENCE_HTML]);
    same(SYNC_BODYPREFERENCE_HTML, $type);
    same(true, str_contains($body, '&lt;friend&gt; &amp; team'));
    same([], $client->downloads);
};
$tests['fallback uses the real inline body, not a text attachment or attached email'] = function () {
    $mime = "Content-Type: multipart/mixed; boundary=b\r\n\r\n"
        . "--b\r\nContent-Type: text/plain; name=secret.txt\r\nContent-Disposition: attachment; filename=secret.txt\r\n\r\nNOT THE BODY\r\n"
        . "--b\r\nContent-Type: message/rfc822\r\n\r\nSubject: Attached\r\nContent-Type: text/plain\r\n\r\nALSO NOT THE BODY\r\n"
        . "--b\r\nContent-Type: text/html; charset=utf-8\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . base64_encode('<p>Actual body</p>') . "\r\n--b--\r\n";
    [$type, $body] = fallbackBody($mime, [SYNC_BODYPREFERENCE_HTML]);
    same(SYNC_BODYPREFERENCE_HTML, $type);
    same('<p>Actual body</p>', trim($body));
};
$tests['MIME request rebuilds unsafe legacy bytes using decoded fallback body'] = function () use ($legacyMime) {
    [$type, $body] = fallbackBody($legacyMime, [SYNC_BODYPREFERENCE_MIME]);
    same(SYNC_BODYPREFERENCE_MIME, $type);
    same(1, preg_match('//u', $body));
    same(true, str_contains($body, base64_encode('café & tea')));
    same(false, $body === $legacyMime);
};
$tests['clean requested MIME stays byte-identical'] = function () {
    $mime = "Subject: Test\r\nContent-Type: text/plain\r\n\r\nHello\r\n";
    [$type, $body] = fallbackBody($mime, [SYNC_BODYPREFERENCE_MIME]);
    same(SYNC_BODYPREFERENCE_MIME, $type);
    same($mime, $body);
};
$tests['signed MIME is preserved even when attachment metadata is missing'] = function () {
    $mime = "Content-Type: multipart/signed;\r\n protocol=\"application/pgp-signature\"; boundary=s\r\n\r\n"
        . "--s\r\nContent-Type: text/plain; charset=iso-8859-1\r\n\r\ncaf" . chr(233)
        . "\r\n--s\r\nContent-Type: application/pgp-signature\r\n\r\nSIGNATURE\r\n--s--\r\n";
    [$type, $body] = fallbackBody($mime, [SYNC_BODYPREFERENCE_MIME]);
    same(SYNC_BODYPREFERENCE_MIME, $type);
    same($mime, $body);
};
$tests['encrypted MIME is preserved without rewriting binary content'] = function () {
    $mime = "Content-Type: application/pkcs7-mime; smime-type=enveloped-data\r\n\r\n\x00\xff\xfe";
    [$type, $body] = fallbackBody($mime, [SYNC_BODYPREFERENCE_MIME]);
    same(SYNC_BODYPREFERENCE_MIME, $type);
    same($mime, $body);
};
$tests['calendar MIME is preserved without rebuilding the invitation'] = function () {
    $mime = "Content-Type: text/calendar; charset=iso-8859-1\r\n\r\nBEGIN:VCALENDAR\r\nSUMMARY:caf"
        . chr(233) . "\r\nEND:VCALENDAR\r\n";
    [$type, $body] = fallbackBody($mime, [SYNC_BODYPREFERENCE_MIME]);
    same(SYNC_BODYPREFERENCE_MIME, $type);
    same($mime, $body);
};
$tests['opaque encrypted mail never becomes unsolicited MIME for an HTML request'] = function () {
    [$type, $body] = fallbackBody("Content-Type: application/pkcs7-mime\r\n\r\nENCRYPTED", [SYNC_BODYPREFERENCE_HTML]);
    same(SYNC_BODYPREFERENCE_HTML, $type);
    same(false, str_contains($body, 'ENCRYPTED'));
    same(false, str_contains($body, 'Content-Type:'));
};
$tests['empty JMAP body is valid and does not trigger MIME download'] = function () {
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => ''];
    $client = new TestJmapClient();
    [$type, $body] = invokeBackend(backend($client), 'selectBody', $email, [SYNC_BODYPREFERENCE_PLAIN]);
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('', $body);
    same([], $client->downloads);
};
$tests['unavailable body produces a retryable error, not a permanent blank message'] = function () {
    $client = new TestJmapClient();
    try {
        invokeBackend(backend($client), 'selectBody', emptyMail(), [SYNC_BODYPREFERENCE_HTML]);
    } catch (StatusException $e) {
        return;
    }
    throw new RuntimeException('Expected StatusException');
};
$tests['mail text control characters are filtered'] = function () {
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => "Good\x00\x01 text"];
    [$type, $body] = invokeBackend(backend(new TestJmapClient()), 'selectBody', $email, [SYNC_BODYPREFERENCE_PLAIN]);
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('Good text', $body);
};
$tests['MIME is not byte-truncated by the generic body limit'] = function () {
    $mime = "Content-Type: application/pkcs7-mime\r\n\r\n" . str_repeat('A', 1024);
    $client = new TestJmapClient();
    $client->blobs['mime-blob'] = $mime;
    $parameters = new ContentParameters();
    $parameters->BodyPreference(SYNC_BODYPREFERENCE_MIME);
    $parameters->SetTruncation(SYNC_TRUNCATION_512B);
    $mail = invokeBackend(backend($client), 'buildSyncMail', emptyMail(), $parameters);
    same($mime, stream_get_contents($mail->asbody->data));
    same(0, $mail->asbody->truncated);
};
$tests['plain body truncation preserves UTF-8 and original estimated size'] = function () {
    $body = str_repeat('a', 511) . 'é end';
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => $body];
    $parameters = new ContentParameters();
    $parameters->BodyPreference(SYNC_BODYPREFERENCE_PLAIN);
    $parameters->SetTruncation(SYNC_TRUNCATION_512B);
    $mail = invokeBackend(backend(new TestJmapClient()), 'buildSyncMail', $email, $parameters);
    $sent = stream_get_contents($mail->asbody->data);
    same(1, preg_match('//u', $sent));
    same(str_repeat('a', 511), $sent);
    same(1, $mail->asbody->truncated);
    same(strlen($body), $mail->asbody->estimatedDataSize);
};
$tests['missing original MIME is never replaced with a potentially unsigned copy'] = function () {
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => 'Display text without signature metadata'];
    try {
        invokeBackend(backend(new TestJmapClient()), 'selectBody', $email, [SYNC_BODYPREFERENCE_MIME]);
    } catch (StatusException $e) {
        return;
    }
    throw new RuntimeException('Expected failure when original MIME is unavailable');
};
$tests['unlisted text attachment values are not used as the message body'] = function () {
    $email = emptyMail();
    $email['bodyValues']['attachment'] = ['value' => 'Attachment contents'];
    [$type, $body] = fallbackBody("Content-Type: text/plain\r\n\r\nActual body", [SYNC_BODYPREFERENCE_PLAIN], $email);
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('Actual body', $body);
};
$tests['truncated JMAP body values fall back to the full MIME body'] = function () {
    $email = emptyMail();
    $email['textBody'] = [['partId' => 't']];
    $email['bodyValues']['t'] = ['value' => 'Part', 'isTruncated' => true];
    [$type, $body] = fallbackBody("Content-Type: text/plain\r\n\r\nPartial data recovered in full", [SYNC_BODYPREFERENCE_PLAIN], $email);
    same(SYNC_BODYPREFERENCE_PLAIN, $type);
    same('Partial data recovered in full', $body);
};
$tests['HTML entities remain escaped in an HTML response'] = function () {
    $email = emptyMail();
    $email['htmlBody'] = [['partId' => 'h']];
    $email['bodyValues']['h'] = ['value' => '<p>&lt;script&gt; &amp; text</p>'];
    [$type, $body] = invokeBackend(backend(new TestJmapClient()), 'selectBody', $email, [SYNC_BODYPREFERENCE_HTML]);
    same(SYNC_BODYPREFERENCE_HTML, $type);
    same($email['bodyValues']['h']['value'], $body);
};
$tests['invalid base64 text is reported as a decode failure'] = function () {
    try {
        fallbackBody("Content-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\n!!!", [SYNC_BODYPREFERENCE_PLAIN]);
    } catch (StatusException $e) {
        return;
    }
    throw new RuntimeException('Expected decode failure');
};
