<?php
/*
 * Synthetic fixtures using Stalwart 1.1 / JSCalendar bis field names.
 * Released under the GNU Affero General Public License (AGPL), version 3.
 */

$event = [
    '@type' => 'Event',
    'id' => 'e1',
    'uid' => 'weekly@example.invalid',
    'calendarIds' => ['cal1' => true],
    'title' => 'Weekly meeting',
    'start' => '2026-03-23T09:00:00',
    'timeZone' => 'Europe/Amsterdam',
    'duration' => 'PT1H',
    'organizerCalendarAddress' => 'mailto:owner@example.invalid',
    'participants' => [
        'server-attendee' => [
            'name' => 'Guest',
            'calendarAddress' => 'mailto:guest@example.invalid',
            'roles' => ['optional' => true],
            'participationStatus' => 'accepted',
            'scheduleSequence' => 7,
        ],
        'server-owner' => [
            'name' => 'Organizer',
            'calendarAddress' => 'mailto:owner@example.invalid',
            'roles' => ['owner' => true],
        ],
    ],
    'recurrenceRule' => [
        '@type' => 'RecurrenceRule',
        'frequency' => 'weekly',
        'interval' => 2,
        'byDay' => [['@type' => 'NDay', 'day' => 'mo'], ['@type' => 'NDay', 'day' => 'we']],
        'until' => '2026-06-30T09:00:00',
    ],
    'keywords' => ['Project Alpha' => true, 'Team' => true],
    'categories' => ['https://example.invalid/concepts/meeting' => true],
    'recurrenceOverrides' => ['2026-04-06T09:00:00' => ['title' => 'Exception']],
];

$tests['calendar reads canonical recurrence, participants and labels'] = function () use ($event) {
    $appt = JmapCalendarConverter::eventToSyncAppointment($event);
    same(strtotime('2026-03-23T08:00:00Z'), $appt->starttime);
    same('owner@example.invalid', $appt->organizeremail);
    same('Organizer', $appt->organizername);
    same(1, count($appt->attendees));
    same('guest@example.invalid', $appt->attendees[0]->email);
    same(2, $appt->attendees[0]->attendeetype);
    same(3, $appt->attendees[0]->attendeestatus);
    same(1, $appt->recurrence->type);
    same(2, $appt->recurrence->interval);
    same(10, $appt->recurrence->dayofweek);
    same(strtotime('2026-06-30T07:00:00Z'), $appt->recurrence->until);
    same(['Project Alpha', 'Team'], $appt->categories);
};
$tests['calendar round trip uses bis fields and keeps series timezone'] = function () use ($event) {
    $appt = JmapCalendarConverter::eventToSyncAppointment($event);
    $saved = JmapCalendarConverter::syncAppointmentToEvent($appt, 'cal1', $event);
    same('2026-03-23T09:00:00', $saved['start']);
    same('Europe/Amsterdam', $saved['timeZone']);
    same('2026-06-30T09:00:00', $saved['recurrenceRule']['until']);
    same('weekly', $saved['recurrenceRule']['frequency']);
    same(false, isset($saved['recurrenceRules']));
    same(false, isset($saved['categories']));
    same($event['keywords'], $saved['keywords']);
    same($event['organizerCalendarAddress'], $saved['organizerCalendarAddress']);
    same(['server-owner', 'server-attendee'], array_keys($saved['participants']));
    same('mailto:guest@example.invalid', $saved['participants']['server-attendee']['calendarAddress']);
    same(7, $saved['participants']['server-attendee']['scheduleSequence']);
    same(['owner' => true], $saved['participants']['server-owner']['roles']);
    same(['optional' => true], $saved['participants']['server-attendee']['roles']);
};
$tests['calendar does not invent an organizer'] = function () {
    $appt = JmapCalendarConverter::eventToSyncAppointment([
        'participants' => ['p' => ['calendarAddress' => 'mailto:guest@example.invalid', 'roles' => ['required' => true]]],
    ]);
    same(false, isset($appt->organizeremail));
    same(false, isset($appt->meetingstatus));
    same('guest@example.invalid', $appt->attendees[0]->email);
};
$tests['explicit organizer wins over unrelated owner role'] = function () {
    $appt = JmapCalendarConverter::eventToSyncAppointment([
        'organizerCalendarAddress' => 'MAILTO:boss%2Bcalendar@example.invalid',
        'participants' => ['p' => ['calendarAddress' => 'mailto:other@example.invalid', 'roles' => ['owner' => true]]],
    ]);
    same('boss+calendar@example.invalid', $appt->organizeremail);
    same('other@example.invalid', $appt->attendees[0]->email);
};
$tests['legacy email and recurrence input remains readable'] = function () {
    $appt = JmapCalendarConverter::eventToSyncAppointment([
        'recurrenceRules' => [['frequency' => 'daily', 'count' => 5]],
        'participants' => [
            'org' => ['email' => 'owner@example.invalid', 'roles' => ['organizer' => true]],
            'att' => ['sendTo' => ['imip' => 'mailto:guest@example.invalid']],
        ],
    ]);
    same(5, $appt->recurrence->occurrences);
    same('owner@example.invalid', $appt->organizeremail);
    same('guest@example.invalid', $appt->attendees[0]->email);
};
$tests['non-email participants are not emitted as invalid attendee addresses'] = function () {
    $appt = JmapCalendarConverter::eventToSyncAppointment([
        'participants' => [
            'urn' => ['calendarAddress' => 'urn:uuid:123'],
            'fallback' => ['calendarAddress' => 'urn:uuid:456', 'email' => 'guest@example.invalid'],
        ],
    ]);
    same(1, count($appt->attendees));
    same('guest@example.invalid', $appt->attendees[0]->email);
};
$tests['monthly last weekday ordinal survives conversion'] = function () {
    $appt = JmapCalendarConverter::eventToSyncAppointment([
        'recurrenceRule' => ['frequency' => 'monthly', 'byDay' => [['day' => 'mo', 'nthOfPeriod' => -1]]],
    ]);
    same(3, $appt->recurrence->type);
    same(5, $appt->recurrence->weekofmonth);
    $appt->recurrence->weekofmonth = '5'; // WBXML values can be strings.
    $saved = JmapCalendarConverter::syncAppointmentToEvent($appt, 'cal1');
    same([-1], $saved['recurrenceRule']['bySetPosition']);
};
$tests['all-day events are floating midnight date-times'] = function () {
    $appt = new SyncAppointment();
    $appt->alldayevent = 1;
    $appt->starttime = strtotime('2026-11-10T00:00:00Z');
    $appt->endtime = strtotime('2026-11-12T00:00:00Z');
    $saved = JmapCalendarConverter::syncAppointmentToEvent($appt, 'cal1');
    same('2026-11-10T00:00:00', $saved['start']);
    same(null, $saved['timeZone']);
    same('P2D', $saved['duration']);
    $read = JmapCalendarConverter::eventToSyncAppointment($saved);
    same($appt->starttime, $read->starttime);
    same($appt->endtime, $read->endtime);
};
$tests['unknown server timezone does not abort an update'] = function () {
    $appt = new SyncAppointment();
    $appt->starttime = strtotime('2026-11-10T09:00:00Z');
    $appt->endtime = $appt->starttime + 3600;
    $saved = JmapCalendarConverter::syncAppointmentToEvent($appt, 'cal1', ['timeZone' => '/custom/unknown-zone']);
    same('2026-11-10T09:00:00', $saved['start']);
    same('UTC', $saved['timeZone']);
};
$tests['calendar update changes recurrence and clears keywords, preserving concepts and exceptions'] = function () use ($event) {
    $client = new TestJmapClient();
    $client->responses = [
        [['CalendarEvent/get', ['list' => [$event]], '0']],
        [['CalendarEvent/set', ['updated' => ['e1' => ['updated' => '2026-11-13T00:00:00Z']]], '0']],
    ];
    $appt = JmapCalendarConverter::eventToSyncAppointment($event);
    $appt->categories = [];
    $appt->recurrence->interval = 3;
    same('e1', invokeBackend(backend($client), 'saveCalendarEvent', 'cal1', 'e1', $appt));
    $saved = $client->calls[1][0][1]['update']['e1'];
    same(3, $saved['recurrenceRule']['interval']);
    same(null, $saved['keywords']);
    same($event['categories'], $saved['categories']);
    same($event['recurrenceOverrides'], $saved['recurrenceOverrides']);
    same(7, $saved['participants']['server-attendee']['scheduleSequence']);
};
$tests['explicitly unset recurrence is not restored by the server-field merge'] = function () use ($event) {
    $client = new TestJmapClient();
    $client->responses = [
        [['CalendarEvent/get', ['list' => [$event]], '0']],
        [['CalendarEvent/set', ['updated' => ['e1' => null]], '0']],
    ];
    $appt = new SyncAppointment();
    $appt->starttime = strtotime('2026-03-23T08:00:00Z');
    $appt->endtime = $appt->starttime + 3600;
    $appt->emptySupported([SYNC_POOMCAL_RECURRENCE]);
    invokeBackend(backend($client), 'saveCalendarEvent', 'cal1', 'e1', $appt);
    same(null, $client->calls[1][0][1]['update']['e1']['recurrenceRule']);
};
$tests['calendar fingerprints detect recurrence, organizer, address and keyword changes'] = function () use ($event) {
    foreach ([
        ['recurrenceRule', ['frequency' => 'monthly']],
        ['organizerCalendarAddress', 'mailto:new@example.invalid'],
        ['participants', ['p' => ['calendarAddress' => 'mailto:new@example.invalid']]],
        ['keywords', ['Project Beta' => true]],
        ['timeZone', 'America/New_York'],
    ] as [$field, $value]) {
        $changed = $event;
        $changed[$field] = $value;
        same(false, JmapCalendarConverter::eventModHash($event) === JmapCalendarConverter::eventModHash($changed), $field);
    }
};
$tests['calendar stat requests canonical fields'] = function () use ($event) {
    $client = new TestJmapClient();
    $client->responses = [[['CalendarEvent/get', ['list' => [$event]], '0']]];
    invokeBackend(backend($client), 'statCalendarEvent', 'e1');
    $properties = $client->calls[0][0][1]['properties'];
    foreach (['recurrenceRule', 'keywords', 'organizerCalendarAddress', 'timeZone'] as $field) {
        same(true, in_array($field, $properties, true), $field);
    }
    same(false, in_array('recurrenceRules', $properties, true));
    same(false, in_array('categories', $properties, true));
};
