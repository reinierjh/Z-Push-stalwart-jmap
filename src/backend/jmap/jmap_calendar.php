<?php
/***********************************************
* File      :   jmap_calendar.php
* Project   :   Z-Push
* Descr     :   JSCalendar (RFC 8984) <-> SyncAppointment conversion
*               for the JMAP backend. No external dependencies.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

class JmapCalendarConverter {

    // -------------------------------------------------------------------------
    // JSCalendar Event → SyncAppointment
    // -------------------------------------------------------------------------

    public static function eventToSyncAppointment(array $event): SyncAppointment {
        $a = new SyncAppointment();

        $a->subject = $event['title'] ?? '(No Subject)';
        $a->uid     = $event['uid']   ?? '';

        // Time
        $start    = $event['start']    ?? '';
        $tzRaw    = is_array($start) ? ($start['timeZone'] ?? $event['timeZone'] ?? 'UTC') : ($event['timeZone'] ?? 'UTC');
        $tz       = is_array($tzRaw) ? 'UTC' : (string)$tzRaw;
        $startTs  = self::jmapTimeToTimestamp($start, $tz);
        $durSecs  = self::parseDuration($event['duration'] ?? 'PT1H');
        $a->dtstamp  = $startTs ?: time();
        if (class_exists('TimezoneUtil')) {
            $phpTz = TimezoneUtil::GetPhpSupportedTimezone($tz);
            try {
                $fullTz = TimezoneUtil::GetFullTZFromTZName($phpTz);
                $a->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($fullTz));
            } catch (\Throwable) {
                $a->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ(TimezoneUtil::GetFullTZ()));
            }
        }

        $a->starttime   = $startTs;
        $a->endtime     = $startTs + $durSecs;
        $a->alldayevent = !empty($event['showWithoutTime']) ? 1 : 0;

        // Description / body
        if (!empty($event['description'])) {
            $desc = $event['description'];
            $a->asbody = new SyncBaseBody();
            $a->asbody->type              = SYNC_BODYPREFERENCE_PLAIN;
            $a->asbody->data              = StringStreamWrapper::Open($desc);
            $a->asbody->estimatedDataSize = strlen($desc);
            $a->asbody->truncated         = 0;
        }

        // Location
        $locations = array_values($event['locations'] ?? []);
        if ($locations) $a->location = $locations[0]['name'] ?? null;

        // JSCalendar bis uses calendarAddress URIs and an explicit organizer.
        // Read legacy email/sendTo fields too, but never invent an organizer
        // from the first attendee.
        $attendees  = [];
        $organizerEmail = self::calendarAddressToEmail($event['organizerCalendarAddress'] ?? '');
        if ($organizerEmail === '') {
            foreach ($event['participants'] ?? [] as $p) {
                if (!empty($p['roles']['owner']) || !empty($p['roles']['organizer'])) {
                    $organizerEmail = self::participantEmail($p);
                    if ($organizerEmail !== '') break;
                }
            }
        }
        if ($organizerEmail !== '') {
            $a->organizeremail = $organizerEmail;
            $a->organizername = $organizerEmail;
        }
        foreach ($event['participants'] ?? [] as $p) {
            $roles = $p['roles'] ?? [];
            $name  = $p['name']  ?? '';
            $email = self::participantEmail($p);
            if ($email === '') continue;

            if ($organizerEmail !== '' && strcasecmp($email, $organizerEmail) === 0) {
                $a->organizername = $name ?: $organizerEmail;
            } else {
                $att = new SyncAttendee();
                $att->name  = $name;
                $att->email = $email;

                $att->attendeestatus = match(strtolower($p['participationStatus'] ?? 'needs-action')) {
                    'accepted'  => 3,
                    'declined'  => 4,
                    'tentative' => 2,
                    default     => 0,
                };
                $att->attendeetype = match(true) {
                    isset($roles['optional'])      => 2,
                    isset($roles['informational']) => 3,
                    default                        => 1,
                };
                $attendees[] = $att;
            }
        }
        if ($attendees) {
            $a->attendees = $attendees;
        }
        if ($organizerEmail !== '') {
            $a->meetingstatus = 1;
        }

        // Sensitivity
        $a->sensitivity = match($event['privacy'] ?? 'public') {
            'private', 'secret' => 2,
            'confidential'      => 3,
            default             => 0,
        };

        // Free/busy
        $a->busystatus = match($event['freeBusyStatus'] ?? 'busy') {
            'free'        => 0,
            'tentative'   => 1,
            'unavailable' => 3,
            default       => 2,
        };

        // Reminder (first alert with negative offset)
        foreach ($event['alerts'] ?? [] as $alert) {
            $offset = $alert['trigger']['offset'] ?? null;
            if ($offset && str_starts_with($offset, '-')) {
                $secs = self::parseDuration(ltrim($offset, '-'));
                $a->reminder = max(0, (int)($secs / 60));
                break;
            }
        }

        // Recurrence
        $rule = $event['recurrenceRule'] ?? $event['recurrenceRules'][0] ?? null;
        if (is_array($rule) && $rule) {
            $a->recurrence = self::jmapRuleToSyncRecurrence($rule, $tz);
        }

        // Ordinary iCalendar CATEGORIES are JSCalendar keywords. The separate
        // categories property contains concepts, not the user's text labels.
        $cats = array_keys(array_filter($event['keywords'] ?? []));
        if ($cats) $a->categories = $cats;

        return $a;
    }

    // -------------------------------------------------------------------------
    // SyncAppointment → JSCalendar Event
    // -------------------------------------------------------------------------

    public static function syncAppointmentToEvent(SyncAppointment $a, string $calendarId, ?array $existing = null): array {
        $startTs = $a->starttime ?? time();
        $endTs   = $a->endtime   ?? ($startTs + 3600);
        $allDay  = !empty($a->alldayevent);

        $event = [
            '@type'       => 'Event',
            'calendarIds' => [$calendarId => true],
            'title'       => $a->subject ?? '(No Subject)',
            'uid'         => !empty($a->uid) ? $a->uid : self::newUid(),
        ];

        if ($allDay) {
            $event['showWithoutTime'] = true;
            $event['start']           = gmdate('Y-m-d\T00:00:00', $startTs);
            $event['duration']        = 'P' . max(1, intdiv($endTs - $startTs, 86400)) . 'D';
            $event['timeZone']        = null;
        } else {
            // Keep an existing series in its local timezone, so editing it
            // does not shift future occurrences when daylight saving changes.
            $timeZone = $existing !== null ? ($existing['timeZone'] ?? null) : 'UTC';
            try {
                $tz = new \DateTimeZone($timeZone ?? 'UTC');
            } catch (\Throwable) {
                // Custom VTIMEZONE names may not be known to PHP. Keep the
                // device's absolute timestamp rather than failing the update.
                $timeZone = 'UTC';
                $tz = new \DateTimeZone('UTC');
            }
            $event['start']    = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz)->format('Y-m-d\TH:i:s');
            $event['duration'] = self::secondsToDuration($endTs - $startTs);
            $event['timeZone'] = $timeZone;
            $event['showWithoutTime'] = false;
        }

        // Location
        if (!empty($a->location)) {
            $event['locations'] = ['l1' => ['@type' => 'Location', 'name' => $a->location]];
        }

        // Description — handle both old-style body (string) and AirSyncBase body (SyncBaseBody)
        $descText = null;
        if (!empty($a->body)) {
            $descText = $a->body;
        } elseif (!empty($a->asbody) && $a->asbody instanceof SyncBaseBody) {
            $data = $a->asbody->data;
            if (is_resource($data)) {
                $descText = stream_get_contents($data);
                rewind($data);
            } elseif (is_string($data) && $data !== '') {
                $descText = $data;
            }
        }
        if ($descText !== null && $descText !== '') {
            $event['description'] = $descText;
        }

        // Organizer + attendees
        $participants = [];
        $organizerEmail = self::calendarAddressToEmail($a->organizeremail ?? '');
        if ($organizerEmail !== '') {
            $event['organizerCalendarAddress'] = 'mailto:' . $organizerEmail;
            $participants['org'] = [
                '@type' => 'Participant',
                'name'  => $a->organizername ?? $organizerEmail,
                'calendarAddress' => 'mailto:' . $organizerEmail,
                'roles' => ['owner' => true],
            ];
        }
        foreach ($a->attendees ?? [] as $i => $att) {
            $email = self::calendarAddressToEmail($att->email ?? '');
            if ($email === '' || ($organizerEmail !== '' && strcasecmp($email, $organizerEmail) === 0)) {
                continue;
            }
            $participants['att' . $i] = [
                '@type'               => 'Participant',
                'name'                => $att->name  ?? '',
                'calendarAddress'     => 'mailto:' . $email,
                'roles'               => match((int)($att->attendeetype ?? 1)) {
                    2 => ['optional'      => true],
                    3 => ['informational' => true],
                    default => ['required' => true],
                },
                'participationStatus' => match((int)($att->attendeestatus ?? 0)) {
                    3 => 'accepted',
                    4 => 'declined',
                    2 => 'tentative',
                    default => 'needs-action',
                },
            ];
        }
        if ($participants) {
            $event['participants'] = self::preserveParticipantIds($participants, $existing['participants'] ?? []);
        }

        // Sensitivity
        $event['privacy'] = match((int)($a->sensitivity ?? 0)) {
            2, 1 => 'private',
            3    => 'secret',
            default => 'public',
        };

        // Free/busy
        $event['freeBusyStatus'] = match((int)($a->busystatus ?? 2)) {
            0 => 'free',
            1 => 'tentative',
            3 => 'unavailable',
            default => 'busy',
        };

        // Reminder
        if (!empty($a->reminder)) {
            $mins = (int)$a->reminder;
            $event['alerts'] = ['al1' => [
                '@type'   => 'Alert',
                'trigger' => [
                    '@type'      => 'OffsetTrigger',
                    'offset'     => '-PT' . $mins . 'M',
                    'relativeTo' => 'start',
                ],
                'action'  => 'display',
            ]];
        }

        // Recurrence
        if (!empty($a->recurrence)) {
            $rule = self::syncRecurrenceToJmap($a->recurrence, $event['timeZone'] ?? 'UTC');
            if ($rule) $event['recurrenceRule'] = $rule;
        } elseif (isset($a->recurrence) || in_array('recurrence', $a->getUnsetVars() ?? [], true)) {
            $event['recurrenceRule'] = null;
        }

        // ActiveSync categories are an array; keep labels containing spaces.
        if (isset($a->categories) || in_array('categories', $a->getUnsetVars() ?? [], true)) {
            $cats = [];
            $catList = is_array($a->categories) ? $a->categories : [$a->categories ?? ''];
            foreach ($catList as $cat) {
                $cat = trim($cat);
                if ($cat !== '') $cats[$cat] = true;
            }
            $event['keywords'] = $cats ?: null;
        }

        return $event;
    }

    // -------------------------------------------------------------------------
    // Mod hash for change detection
    // -------------------------------------------------------------------------

    public static function eventModHash(array $event): string {
        $values = [];
        foreach (['title', 'start', 'timeZone', 'duration', 'description', 'locations',
                  'participants', 'organizerCalendarAddress', 'privacy', 'freeBusyStatus',
                  'keywords', 'showWithoutTime', 'alerts', 'updated'] as $property) {
            $values[$property] = $event[$property] ?? null;
        }
        $values['recurrenceRule'] = $event['recurrenceRule'] ?? $event['recurrenceRules'][0] ?? null;
        // Version the fingerprint so already-synced items receive the corrected
        // field mapping once, even if Stalwart's updated timestamp is unchanged.
        return sprintf('%08x', crc32('v2|' . json_encode($values)));
    }

    private static function calendarAddressToEmail(string $address): string {
        $address = trim($address);
        if (stripos($address, 'mailto:') === 0) {
            $address = rawurldecode(explode('?', substr($address, 7), 2)[0]);
        } elseif (str_contains($address, ':')) {
            return ''; // e.g. a urn:uuid participant without an email address
        }
        return str_contains($address, '@') ? $address : '';
    }

    private static function participantEmail(array $participant): string {
        foreach ([$participant['calendarAddress'] ?? '', $participant['email'] ?? '', $participant['sendTo']['imip'] ?? ''] as $address) {
            $email = self::calendarAddressToEmail($address);
            if ($email !== '') return $email;
        }
        return '';
    }

    private static function preserveParticipantIds(array $participants, array $existing): array {
        $result = [];
        foreach ($participants as $key => $participant) {
            $email = self::participantEmail($participant);
            foreach ($existing as $oldKey => $old) {
                if (!isset($result[$oldKey]) && strcasecmp($email, self::participantEmail($old)) === 0) {
                    $result[$oldKey] = array_merge($old, $participant);
                    continue 2;
                }
            }
            while (isset($existing[$key]) || isset($result[$key])) $key .= '_';
            $result[$key] = $participant;
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Recurrence helpers
    // -------------------------------------------------------------------------

    private static function jmapRuleToSyncRecurrence(array $rule, string $tz): SyncRecurrence {
        $r = new SyncRecurrence();

        $freq   = strtolower($rule['frequency'] ?? 'daily');
        $byDay  = $rule['byDay']      ?? [];
        $byPos  = $rule['bySetPosition'] ?? [];

        // JSCalendar can express the ordinal directly on NDay (e.g. last
        // Monday), or through bySetPosition. ActiveSync uses weekofmonth.
        if (!$byPos && count($byDay) === 1 && isset($byDay[0]['nthOfPeriod'])) {
            $byPos = [(int)$byDay[0]['nthOfPeriod']];
        }

        // Monthly nth-day vs plain monthly
        if ($freq === 'monthly' && $byDay && $byPos) {
            $r->type = 3; // Monthly nth day
        } elseif ($freq === 'yearly' && ($byDay || $byPos)) {
            $r->type = 6;
        } else {
            $r->type = match($freq) {
                'daily'   => 0,
                'weekly'  => 1,
                'monthly' => 2,
                'yearly'  => 5,
                default   => 0,
            };
        }

        $r->interval = max(1, (int)($rule['interval'] ?? 1));

        if (!empty($rule['count'])) {
            $r->occurrences = (int)$rule['count'];
        } elseif (!empty($rule['until'])) {
            $r->until = self::localToTimestamp($rule['until'], $tz);
        }

        // Day-of-week bitmask
        if ($byDay) {
            $map  = ['su' => 1, 'mo' => 2, 'tu' => 4, 'we' => 8, 'th' => 16, 'fr' => 32, 'sa' => 64];
            $mask = 0;
            foreach ($byDay as $d) {
                $mask |= $map[strtolower($d['day'] ?? '')] ?? 0;
            }
            if ($mask) $r->dayofweek = $mask;

            // nth occurrence within month
            if ($byPos) {
                $r->weekofmonth = (int)$byPos[0] === -1 ? 5 : (int)$byPos[0];
            }
        }

        if (!empty($rule['byMonthDay'])) $r->dayofmonth  = (int)$rule['byMonthDay'][0];
        if (!empty($rule['byMonth']))    $r->monthofyear = (int)$rule['byMonth'][0];

        return $r;
    }

    private static function syncRecurrenceToJmap(SyncRecurrence $r, string $tz): ?array {
        $type = (int)($r->type ?? 0);
        $rule = [
            '@type'     => 'RecurrenceRule',
            'frequency' => match($type) {
                0    => 'daily',
                1    => 'weekly',
                2, 3 => 'monthly',
                5, 6 => 'yearly',
                default => 'daily',
            },
        ];

        if (!empty($r->interval) && $r->interval > 1) $rule['interval'] = (int)$r->interval;

        if (!empty($r->occurrences)) {
            $rule['count'] = (int)$r->occurrences;
        } elseif (!empty($r->until)) {
            $rule['until'] = (new \DateTimeImmutable('@' . $r->until))
                ->setTimezone(new \DateTimeZone($tz))->format('Y-m-d\TH:i:s');
        }

        if (!empty($r->dayofweek)) {
            $map = [1 => 'su', 2 => 'mo', 4 => 'tu', 8 => 'we', 16 => 'th', 32 => 'fr', 64 => 'sa'];
            $byDay = [];
            $nDay  = [];
            foreach ($map as $bit => $day) {
                if ($r->dayofweek & $bit) {
                    $entry = ['@type' => 'NDay', 'day' => $day];
                    $byDay[] = $entry;
                    $nDay[]  = $day;
                }
            }
            $rule['byDay'] = $byDay;
            if (!empty($r->weekofmonth)) {
                $rule['bySetPosition'] = [(int)$r->weekofmonth === 5 ? -1 : (int)$r->weekofmonth];
            }
        }

        if (!empty($r->dayofmonth))  $rule['byMonthDay'] = [(int)$r->dayofmonth];
        if (!empty($r->monthofyear)) $rule['byMonth']    = [(string)(int)$r->monthofyear];

        return $rule;
    }

    // -------------------------------------------------------------------------
    // Time utilities
    // -------------------------------------------------------------------------

    public static function jmapTimeToTimestamp($jmapTime, string $defaultTz = 'UTC'): int {
        if (is_array($jmapTime)) {
            $dateStr = $jmapTime['date'] ?? '';
            $tzStr   = $jmapTime['timeZone'] ?? $defaultTz;
        } else {
            $dateStr = (string)$jmapTime;
            $tzStr   = $defaultTz;
        }
        if (!$dateStr) return 0;
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                $dateStr .= 'T00:00:00';
            }
            return (new \DateTime($dateStr, new \DateTimeZone($tzStr)))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function localToTimestamp(string $datetime, string $tz): int {
        try {
            $dt = new \DateTime($datetime, new \DateTimeZone($tz));
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function parseDuration(string $duration): int {
        $neg = str_starts_with($duration, '-');
        $d   = ltrim($duration, '-');
        if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $d, $m)) {
            return 0;
        }
        $secs = (int)($m[1] ?? 0) * 365 * 86400
              + (int)($m[2] ?? 0) * 30  * 86400
              + (int)($m[3] ?? 0) * 7   * 86400
              + (int)($m[4] ?? 0)       * 86400
              + (int)($m[5] ?? 0)       * 3600
              + (int)($m[6] ?? 0)       * 60
              + (int)($m[7] ?? 0);
        return $neg ? -$secs : $secs;
    }

    private static function secondsToDuration(int $seconds): string {
        if ($seconds <= 0) return 'PT0S';
        $days  = intdiv($seconds, 86400); $rem = $seconds % 86400;
        $hours = intdiv($rem, 3600);      $rem %= 3600;
        $mins  = intdiv($rem, 60);
        $secs  = $rem % 60;

        $s = 'P';
        if ($days)  $s .= $days  . 'D';
        $t = '';
        if ($hours) $t .= $hours . 'H';
        if ($mins)  $t .= $mins  . 'M';
        if ($secs)  $t .= $secs  . 'S';
        if ($t)     $s .= 'T' . $t;
        return $s ?: 'PT0S';
    }

    private static function newUid(): string {
        return sprintf('%s@zpush-jmap', bin2hex(random_bytes(16)));
    }
}
