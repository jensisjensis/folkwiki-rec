<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    @set_time_limit(0);
}

/*
 * Im Browser aufrufen oder im Projekt-Root starten:
 *   php update.php
 *
 * Optionaler Tanzname:
 *   php update.php "Vals"
 */

const MIDI_LOW = 48;
const MIDI_HIGH = 95;
const CONTOUR_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

const NATURAL = [
    'C' => 0, 'D' => 2, 'E' => 4, 'F' => 5,
    'G' => 7, 'A' => 9, 'B' => 11,
];

const MAJOR_SIGNATURES = [
    0 => ['C', 0], 1 => ['Db', -5], 2 => ['D', 2], 3 => ['Eb', -3],
    4 => ['E', 4], 5 => ['F', -1], 6 => ['F#', 6], 7 => ['G', 1],
    8 => ['Ab', -4], 9 => ['A', 3], 10 => ['Bb', -2], 11 => ['B', 5],
];

const SHARP_ORDER = ['F', 'C', 'G', 'D', 'A', 'E', 'B'];
const FLAT_ORDER = ['B', 'E', 'A', 'D', 'G', 'C', 'F'];

const MODE_OFFSETS = [
    '' => 0, 'maj' => 0, 'major' => 0, 'ion' => 0, 'ionian' => 0,
    'dor' => 2, 'dorian' => 2, 'phr' => 4, 'phrygian' => 4,
    'lyd' => 5, 'lydian' => 5, 'mix' => 7, 'mixolydian' => 7,
    'min' => 9, 'minor' => 9, 'm' => 9,
    'aeo' => 9, 'aeol' => 9, 'aeolian' => 9,
    'loc' => 11, 'locrian' => 11,
];

const MODE_SUFFIX = [
    '' => 'maj', 'maj' => 'maj', 'major' => 'maj', 'ion' => 'maj',
    'ionian' => 'maj', 'dor' => 'dor', 'dorian' => 'dor',
    'phr' => 'phr', 'phrygian' => 'phr', 'lyd' => 'lyd',
    'lydian' => 'lyd', 'mix' => 'mix', 'mixolydian' => 'mix',
    'min' => 'min', 'minor' => 'min', 'm' => 'min',
    'aeo' => 'min', 'aeol' => 'min', 'aeolian' => 'min',
    'loc' => 'loc', 'locrian' => 'loc',
];

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function readJsonObject(string $path, bool $optional = false): stdClass
{
    if (!is_file($path)) {
        if ($optional) {
            return new stdClass();
        }
        fail("Datei nicht gefunden: {$path}");
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        fail("Datei konnte nicht gelesen werden: {$path}");
    }

    try {
        $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        fail("Ungültiges JSON in {$path}: " . $error->getMessage());
    }

    if (!$value instanceof stdClass) {
        fail("In {$path} wird ein JSON-Objekt erwartet.");
    }

    return $value;
}

function writeJsonAtomically(string $path, string $json): void
{
    $temporary = $path . '.tmp.' . getmypid();

    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        fail("Konnte temporäre Datei nicht schreiben: {$temporary}");
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        fail("Konnte Datei nicht aktualisieren: {$path}");
    }
}

function slugify(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return 'untitled';
    }

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_D);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    } elseif (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = preg_replace('/[\x{0300}-\x{036f}]/u', '', $text) ?? $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
    $text = trim($text, '-');

    return $text !== '' ? $text : 'untitled';
}

function splitTunes(string $text): array
{
    $lines = explode("\n", str_replace("\r", '', $text));
    $blocks = [];
    $current = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*X\s*:/i', $line) && $current !== []) {
            $blocks[] = implode("\n", $current);
            $current = [];
        }
        $current[] = $line;
    }

    if ($current !== []) {
        $blocks[] = implode("\n", $current);
    }

    return array_values(array_filter(
        $blocks,
        static fn(string $block): bool =>
            (bool) preg_match('/^\s*(?:X|T|K)\s*:/mi', $block)
    ));
}

function headerField(string $block, string $name, string $fallback = ''): string
{
    $pattern = '/^\s*' . preg_quote($name, '/') . '\s*:\s*(.*)$/mi';

    if (preg_match($pattern, $block, $match)) {
        return trim($match[1]);
    }

    return $fallback;
}

function parseFraction(string $text, float $fallback): float
{
    if ($text === '') {
        return $fallback;
    }

    if (!preg_match('/^(\d+)(?:\/(\d+))?$/', trim($text), $match)) {
        return $fallback;
    }

    $numerator = (float) $match[1];
    $denominator = isset($match[2]) ? (float) $match[2] : 1.0;
    $value = $denominator != 0.0 ? $numerator / $denominator : 0.0;

    return is_finite($value) && $value > 0 ? $value : $fallback;
}

function pitchClass(string $letter, string $accidental): int
{
    $base = NATURAL[strtoupper($letter[0])];
    $offset = $accidental === '#'
        ? 1
        : ($accidental === 'b' ? -1 : 0);

    return ($base + $offset + 12) % 12;
}

function parseKey(string $rawKey): array
{
    $value = trim($rawKey !== '' ? $rawKey : 'C');

    if (preg_match('/^none(?:\s|$)/i', $value)) {
        return [
            'signature' => [],
            'metadataMode' => 'Cmaj',
            'displayKey' => 'C',
        ];
    }

    $parts = preg_split('/\s+/', $value);
    $first = $parts[0] ?? '';

    if (!preg_match('/^([A-Ga-g])([#b]?)(.*)$/', $first, $match)) {
        throw new RuntimeException("Ungültige Tonart: {$rawKey}");
    }

    $tonicLetter = strtoupper($match[1]);
    $tonicAcc = $match[2];
    $modeText = strtolower($match[3] ?? '');

    if ($modeText === '' && $match[1] === strtolower($match[1])) {
        $modeText = 'min';
    }

    if (!array_key_exists($modeText, MODE_OFFSETS)) {
        throw new RuntimeException("Nicht unterstützter Modus in K:{$rawKey}");
    }

    $tonicPc = pitchClass($tonicLetter, $tonicAcc);
    $relativeMajorPc = ($tonicPc - MODE_OFFSETS[$modeText] + 12) % 12;
    [$relativeMajorName, $signatureCount] = MAJOR_SIGNATURES[$relativeMajorPc];

    $signature = [];

    if ($signatureCount > 0) {
        foreach (array_slice(SHARP_ORDER, 0, $signatureCount) as $letter) {
            $signature[$letter] = 1;
        }
    } elseif ($signatureCount < 0) {
        foreach (array_slice(FLAT_ORDER, 0, -$signatureCount) as $letter) {
            $signature[$letter] = -1;
        }
    }

    return [
        'signature' => $signature,
        'metadataMode' => $tonicLetter . $tonicAcc . (MODE_SUFFIX[$modeText] ?? 'maj'),
        'displayKey' => $relativeMajorName,
    ];
}

function cleanMusicLines(array $lines): string
{
    $filteredLines = [];
    $insideSvg = false;

    foreach ($lines as $line) {
        if (preg_match('/^\s*%%beginsvg\b/i', $line)) {
            $insideSvg = true;
            continue;
        }

        if (preg_match('/^\s*%%endsvg\b/i', $line)) {
            $insideSvg = false;
            continue;
        }

        // SVG/XML-Zeilen dürfen nicht als ABC-Noten oder Rhythmuszeichen gelten.
        if ($insideSvg) {
            continue;
        }

        if (preg_match('/^\s*%/', $line) ||
            preg_match('/^\s*(?:[A-Za-z+]:|%%)/', $line) ||
            preg_match('/^\s*(?:<{7,}|={7,}|>{7,}|\|{7,})(?:\s|$)/', $line)) {
            continue;
        }

        $filteredLines[] = $line;
    }

    $text = implode("\n", $filteredLines);
    $text = preg_replace('~</?br\s*/?>~i', ' ', $text) ?? $text;
    $text = preg_replace('/"[^"]*"/', '', $text) ?? $text;
    $text = preg_replace('/![^!]*!|\+[^+]*\+/', '', $text) ?? $text;
    $text = preg_replace('/\{[^}]*\}/', '', $text) ?? $text;

    return $text;
}

function extractMusicBody(string $block): string
{
    $lines = explode("\n", str_replace("\r", '', $block));

    // Erste deklarierte Stimme ermitteln; nur bis zum Leerzeichen
    // innerhalb dieser Zeile lesen.
    $firstVoice = null;

    foreach ($lines as $line) {
        if (preg_match('/^[ \t]*V[ \t]*:[ \t]*([^\s]+)/i', $line, $match)) {
            $firstVoice = $match[1];
            break;
        }
    }

    // Gibt es keine Stimmen, den gesamten Musiktext verwenden.
    if ($firstVoice === null) {
        return cleanMusicLines($lines);
    }

    $activeVoice = null;
    $selectedLines = [];

    foreach ($lines as $line) {
        if (preg_match('/^[ \t]*V[ \t]*:[ \t]*([^\s]+)/i', $line, $match)) {
            $activeVoice = $match[1];
            continue;
        }

        if ($activeVoice !== $firstVoice) {
            continue;
        }

        // Tonartwechsel der ausgewählten Stimme für parseTune() erhalten.
        if (preg_match('/^[ \t]*K[ \t]*:[ \t]*(.*)$/i', $line, $match)) {
            $selectedLines[] = '[K:' . trim($match[1]) . ']';
        } else {
            $selectedLines[] = $line;
        }
    }

    return cleanMusicLines($selectedLines);
}
function parseNoteToken(string $token): ?array
{
    if (!preg_match(
        "~^(\\^{1,2}|_{1,2}|=)?([A-Ga-g])([,']*)(\\d+)?(/+\\d*)?-?$~",
        $token,
        $match
    )) {
        return null;
    }

    $explicitAcc = $match[1] ?? '';
    $letter = $match[2];
    $octaveMarks = $match[3] ?? '';
    $digits = $match[4] ?? '';
    $slash = $match[5] ?? '';

    $upper = strtoupper($letter);
    $lengthFactor = $digits !== '' ? (float) $digits : 1.0;

    if ($slash !== '') {
        $slashCount = substr_count($slash, '/');

        if (preg_match('~/(\d+)~', $slash, $denominatorMatch)) {
            $lengthFactor *= 1 / (float) $denominatorMatch[1];
        } else {
            $lengthFactor *= 1 / (2 ** $slashCount);
        }
    }

    $octave = ctype_lower($letter) ? 5 : 4;

    foreach (str_split($octaveMarks) as $mark) {
        $octave += $mark === "'" ? 1 : -1;
    }

    return [
        'letter' => $upper,
        'explicitAcc' => $explicitAcc,
        'octave' => $octave,
        'lengthFactor' => $lengthFactor,
        'midiWithoutAlter' => 12 * ($octave + 1) + NATURAL[$upper],
    ];
}

function resolveMidi(array $note, array $signature, array &$accidentalAtBar): int
{
    $key = $note['letter'] . $note['octave'];
    $alter = $signature[$note['letter']] ?? 0;

    switch ($note['explicitAcc']) {
        case '^':
            $alter = 1;
            break;
        case '^^':
            $alter = 2;
            break;
        case '_':
            $alter = -1;
            break;
        case '__':
            $alter = -2;
            break;
        case '=':
            $alter = 0;
            break;
    }

    if ($note['explicitAcc'] !== '') {
        $accidentalAtBar[$key] = $alter;
    } elseif (array_key_exists($key, $accidentalAtBar)) {
        $alter = $accidentalAtBar[$key];
    }

    return $note['midiWithoutAlter'] + $alter;
}

function parseChordToken(
    string $token,
    array $signature,
    array &$accidentalAtBar
): ?array {
    if (!preg_match('~^\[([^\]]+)\](\d*(?:/+\d*)?)-?$~', $token, $match)) {
        return null;
    }

    preg_match_all(
        "~(?:\\^{1,2}|_{1,2}|=)?[A-Ga-g][,']*~",
        $match[1],
        $noteMatches
    );

    $resolved = [];

    foreach ($noteMatches[0] ?? [] as $chordNoteToken) {
        $chordNote = parseNoteToken($chordNoteToken);

        if ($chordNote !== null) {
            $resolved[] = resolveMidi($chordNote, $signature, $accidentalAtBar);
        }
    }

    if ($resolved === []) {
        return null;
    }

    $durationNote = parseNoteToken('C' . ($match[2] !== '' ? $match[2] : ''));

    return [
        'midi' => max($resolved),
        'lengthFactor' => $durationNote['lengthFactor'] ?? 1.0,
    ];
}

function applyMidiRange(array &$events): array
{
    $midis = array_column($events, 'midi');
    $low = min($midis);
    $high = max($midis);

    $minimumOctaves = (int) ceil((MIDI_LOW - $low) / 12);
    $maximumOctaves = (int) floor((MIDI_HIGH - $high) / 12);
    $warnings = [];

    if ($minimumOctaves <= $maximumOctaves) {
        $octaves = max($minimumOctaves, min($maximumOctaves, 0));

        if ($octaves !== 0) {
            foreach ($events as &$event) {
                $event['midi'] += 12 * $octaves;
            }
            unset($event);

            $direction = $octaves > 0 ? 'nach oben' : 'nach unten';
            $warnings[] =
                'Melodie um ' . abs($octaves) . " Oktave(n) {$direction} verschoben.";
        }

        return $warnings;
    }

    $midpoint = ($low + $high) / 2;
    $globalOctaves = (int) round((71.5 - $midpoint) / 12);

    if ($globalOctaves !== 0) {
        foreach ($events as &$event) {
            $event['midi'] += 12 * $globalOctaves;
        }
        unset($event);
    }

    $individuallyAdjusted = 0;

    foreach ($events as &$event) {
        while ($event['midi'] < MIDI_LOW) {
            $event['midi'] += 12;
            $individuallyAdjusted++;
        }

        while ($event['midi'] > MIDI_HIGH) {
            $event['midi'] -= 12;
            $individuallyAdjusted++;
        }
    }
    unset($event);

    if ($globalOctaves !== 0) {
        $direction = $globalOctaves > 0 ? 'nach oben' : 'nach unten';
        $warnings[] =
            'Melodie global um ' . abs($globalOctaves) . " Oktave(n) {$direction} verschoben.";
    }

    if ($individuallyAdjusted > 0) {
        $warnings[] = "{$individuallyAdjusted} einzelne Note(n) zusätzlich oktavweise " .
            'in MIDI ' . MIDI_LOW . '–' . MIDI_HIGH . ' angepasst.';
    }

    return $warnings;
}

/**
 * Wendet ein Broken-Rhythm-Zeichen auf das vorherige und das aktuelle
 * Rhythmusereignis an. Das vorherige Ereignis ist Note oder Pause.
 */
function applyBrokenRhythm(
    array $pendingBroken,
    float $currentDuration,
    array &$events,
    array &$lastRhythmItem
): float {
    $transferFraction = 1 - (1 / (2 ** $pendingBroken['count']));
    $previous = $lastRhythmItem;

    if ($previous === null) {
        return $currentDuration;
    }

    $previousDuration = $previous['type'] === 'note'
        ? $events[$previous['index']]['exactQuavers']
        : $previous['duration'];

    if ($pendingBroken['direction'] === '>') {
        $transferred = $currentDuration * $transferFraction;

        if ($previous['type'] === 'note') {
            $events[$previous['index']]['exactQuavers'] += $transferred;
        } else {
            $lastRhythmItem['duration'] += $transferred;
        }

        $currentDuration -= $transferred;
    } else {
        $transferred = $previousDuration * $transferFraction;

        if ($previous['type'] === 'note') {
            $events[$previous['index']]['exactQuavers'] -= $transferred;
        } else {
            $lastRhythmItem['duration'] -= $transferred;
        }

        $currentDuration += $transferred;
    }

    return $currentDuration;
}

function isClefOnlyKey(string $rawKey): bool
{
    return (bool) preg_match(
        '/^(?:clef\s*=\s*)?(?:treble|bass|alto|tenor|perc|percussion|none)(?:\s|$)/i',
        trim($rawKey)
    );
}

function estimateKeyFromBlock(string $block): ?array
{
    $majorProfile = [6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88];
    $minorProfile = [6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17];

    $body = extractMusicBody($block);
    $body = preg_replace('/"[^"]*"/', ' ', $body) ?? $body;
    $body = preg_replace('~\[(?:K|M|L):[^\]]*\]~i', ' ', $body) ?? $body;

    $pitchCounts = array_fill(0, 12, 0);

    preg_match_all(
        "~(\\^{1,2}|_{1,2}|=)?([A-Ga-g])[,']*(?:\\d+)?(?:/+\\d*)?~",
        $body,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $letter = strtoupper($match[2]);
        $accidental = $match[1] ?? '';

        $alteration = match ($accidental) {
            '^' => 1,
            '^^' => 2,
            '_' => -1,
            '__' => -2,
            default => 0,
        };

        $pitchClass = (NATURAL[$letter] + $alteration + 12) % 12;
        $pitchCounts[$pitchClass]++;
    }

    if (array_sum($pitchCounts) < 8) {
        return null;
    }

    $tonicNames = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];
    $bestScore = -INF;
    $bestKey = null;

    foreach (['maj' => $majorProfile, 'min' => $minorProfile] as $mode => $profile) {
        for ($tonic = 0; $tonic < 12; $tonic++) {
            $meanCounts = array_sum($pitchCounts) / 12;
            $meanProfile = array_sum($profile) / 12;
            $numerator = 0.0;
            $countVariance = 0.0;
            $profileVariance = 0.0;

            for ($pc = 0; $pc < 12; $pc++) {
                $rotatedProfileValue = $profile[($pc - $tonic + 12) % 12];
                $countDifference = $pitchCounts[$pc] - $meanCounts;
                $profileDifference = $rotatedProfileValue - $meanProfile;

                $numerator += $countDifference * $profileDifference;
                $countVariance += $countDifference ** 2;
                $profileVariance += $profileDifference ** 2;
            }

            $denominator = sqrt($countVariance * $profileVariance);
            $score = $denominator > 0 ? $numerator / $denominator : 0.0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $keyText = $tonicNames[$tonic] . ($mode === 'min' ? 'min' : '');
                $bestKey = parseKey($keyText);
            }
        }
    }

    if ($bestKey !== null) {
        $bestKey['estimated'] = true;
        $bestKey['confidence'] = $bestScore;
    }

    return $bestKey;
}

final class NoMelodyNotesException extends RuntimeException
{
}


function parseTune(string $block): array
{
    preg_match_all('/^\s*T\s*:\s*(.*)$/mi', $block, $titleMatches);

    $titles = array_values(array_filter(
        array_map('trim', $titleMatches[1] ?? []),
        'strlen'
    ));

    $title = $titles[0] ?? 'Untitled tune';
    $meter = headerField($block, 'M', '4/4');
    $unit = parseFraction(headerField($block, 'L', ''), 1 / 8);
    $parseWarnings = [];

    /*
     * Normalisiert Tonartvarianten, bevor parseKey() sie erhält:
     * Hm wird Bm; Am/C wird Am. Andere ungültige Angaben bleiben ungültig
     * und lösen weiter unten eine Schätzung aus.
     */
    $prepareKey = static function (string $rawKey): string {
        $value = trim($rawKey);

        $value = preg_replace_callback(
            '/^[Hh]/',
            static fn(array $match): string =>
                $match[0] === 'h' ? 'b' : 'B',
            $value,
            1
        ) ?? $value;

        $value = preg_replace(
            '~^([A-Ga-g][#b]?[^/\s]*)/.*$~',
            '$1',
            $value
        ) ?? $value;

        return $value;
    };

    $rawInitialKey = headerField($block, 'K', 'C');

    if (isClefOnlyKey($rawInitialKey)) {
        $initialKey = parseKey('C');
    } else {
        try {
            $initialKey = parseKey($prepareKey($rawInitialKey));
        } catch (RuntimeException $error) {
            $initialKey = estimateKeyFromBlock($block);

            if ($initialKey === null) {
                $initialKey = parseKey('C');
                $parseWarnings[] =
                    "{$title}: ungültige Tonartangabe K:{$rawInitialKey}; " .
                    'keine Schätzung möglich, C-Dur verwendet.';
            } else {
                $parseWarnings[] =
                    "{$title}: ungültige Tonartangabe K:{$rawInitialKey}; " .
                    'Tonart heuristisch als ' . $initialKey['metadataMode'] .
                    ' geschätzt.';
            }
        }
    }

    $reportedMode = $initialKey['metadataMode'];
    $signature = $initialKey['signature'];
    $accidentalAtBar = [];

    $tupleFactor = 1.0;
    $tupleNotesLeft = 0;
    $pendingBroken = null;
    $lastRhythmItem = null;

    $events = [];
    $body = extractMusicBody($block);

    $tokenPattern = <<<'REGEX'
~\[K:[^\]]+\]|\[L:[^\]]+\]|\[M:[^\]]+\]|\[[^\]]*\]\d*(?:/+\d*)?-?|\|:|:\||:\|:|\|\]|\[\||\|\||\||\(\d+(?::\d+(?::\d+)?)?|[<>]+|(?:\^{1,2}|_{1,2}|=)?[A-Ga-g][,']*(?:\d+)?(?:/+\d*)?-?|[zxZX]\d*(?:/+\d*)?~
REGEX;

    preg_match_all($tokenPattern, $body, $allMatches, PREG_OFFSET_CAPTURE);
    $cursor = 0;

    foreach ($allMatches[0] as [$token, $offset]) {
        $skipped = substr($body, $cursor, $offset - $cursor);

        if (preg_match('/[><]/', $skipped)) {
            throw new RuntimeException(
                "{$title}: Broken-Rhythm-Zeichen konnte nicht verarbeitet werden."
            );
        }

        $cursor = $offset + strlen($token);

        if (preg_match('/^\[K:([^\]]+)\]$/i', $token, $keyMatch)) {
            $rawInlineKey = trim($keyMatch[1]);

            // Clef-Angaben sind keine Tonart und ändern die aktuelle Tonart nicht.
            if (isClefOnlyKey($rawInlineKey)) {
                continue;
            }

            try {
                $parsedKey = parseKey($prepareKey($rawInlineKey));
            } catch (RuntimeException $error) {
                $parsedKey = estimateKeyFromBlock($block);

                if ($parsedKey === null) {
                    $parseWarnings[] =
                        "{$title}: Inline-Tonart K:{$rawInlineKey} ignoriert.";
                    continue;
                }

                $parseWarnings[] =
                    "{$title}: Inline-Tonart K:{$rawInlineKey} ungültig; " .
                    'Tonart heuristisch als ' . $parsedKey['metadataMode'] .
                    ' geschätzt.';
            }

            $signature = $parsedKey['signature'];
            $accidentalAtBar = [];

            continue;
        }

        if (preg_match('/^\[(?:L|M):/i', $token)) {
            continue;
        }

        if (str_starts_with($token, '[') &&
            !preg_match('~^\[[^\]]*\]\d*(?:/+\d*)?-?$~', $token)) {
            continue;
        }

        if (preg_match('/^(?:\||:)/', $token)) {
            if ($pendingBroken !== null) {
                $parseWarnings[] =
                    "{$title}: alleinstehendes Broken-Rhythm-Zeichen vor einem " .
                    'Taktstrich ignoriert; die vorhergehende Note behält ihre notierte Dauer.';
                $pendingBroken = null;
            }

            $accidentalAtBar = [];
            $lastRhythmItem = null;
            continue;
        }

        if (preg_match('/^\((\d+)(?::(\d+))?(?::(\d+))?$/', $token, $tuplet)) {
            $n = (int) $tuplet[1];

            $inTimeOf = isset($tuplet[2]) && $tuplet[2] !== ''
                ? (int) $tuplet[2]
                : ($n === 2 ? 3 : (($n === 3 || $n === 4) ? 2 : $n - 1));

            $tupleFactor = $n > 0 ? $inTimeOf / $n : 1.0;
            $tupleNotesLeft = isset($tuplet[3]) && $tuplet[3] !== ''
                ? (int) $tuplet[3]
                : $n;

            if ($n <= 0 || $inTimeOf <= 0 || $tupleNotesLeft <= 0) {
                $parseWarnings[] =
                    "{$title}: ungültige Tuplet-Angabe {$token} ignoriert.";
                $tupleFactor = 1.0;
                $tupleNotesLeft = 0;
            }

            continue;
        }

        if (preg_match('/^[<>]+$/', $token)) {
            if ($pendingBroken !== null) {
                throw new RuntimeException(
                    "{$title}: Mehrere getrennte Broken-Rhythm-Gruppen " .
                    'hintereinander werden nicht unterstützt.'
                );
            }

            if ($lastRhythmItem === null) {
                $parseWarnings[] =
                    "{$title}: führendes Broken-Rhythm-Zeichen {$token} ohne " .
                    'vorhergehende Note oder Pause ignoriert.';
                continue;
            }

            $pendingBroken = [
                'direction' => $token[0],
                'count' => strlen($token),
            ];

            continue;
        }

        $note = parseNoteToken($token);
        $midi = null;

        if ($note === null && str_starts_with($token, '[')) {
            $chord = parseChordToken($token, $signature, $accidentalAtBar);

            if ($chord !== null) {
                $note = ['lengthFactor' => $chord['lengthFactor']];
                $midi = $chord['midi'];
            }
        }

        if ($note === null) {
            if (preg_match('~^[zxZX](\d+)?(/+\d*)?$~', $token, $restMatch)) {
                $restToken = 'C' . ($restMatch[1] ?? '') . ($restMatch[2] ?? '');
                $restNote = parseNoteToken($restToken);
                $restQuavers = $unit *
                    ($restNote['lengthFactor'] ?? 1.0) *
                    8 *
                    $tupleFactor;

                if ($pendingBroken !== null) {
                    $restQuavers = applyBrokenRhythm(
                        $pendingBroken,
                        $restQuavers,
                        $events,
                        $lastRhythmItem
                    );
                    $pendingBroken = null;
                }

                if ($restQuavers <= 0) {
                    throw new RuntimeException(
                        "{$title}: Broken Rhythm ergab eine ungültige Pausendauer."
                    );
                }

                $lastRhythmItem = [
                    'type' => 'rest',
                    'duration' => $restQuavers,
                ];
            }

            if ($tupleNotesLeft > 0) {
                $tupleNotesLeft--;
                if ($tupleNotesLeft === 0) {
                    $tupleFactor = 1.0;
                }
            }

            continue;
        }

        if (($note['lengthFactor'] ?? 1.0) <= 0.0) {
            $parseWarnings[] =
                "{$title}: Note {$token} mit Dauer 0 ignoriert.";

            if ($pendingBroken !== null) {
                $parseWarnings[] =
                    "{$title}: Broken-Rhythm-Zeichen vor der Note {$token} ignoriert.";
                $pendingBroken = null;
            }

            if ($tupleNotesLeft > 0) {
                $tupleNotesLeft--;
                if ($tupleNotesLeft === 0) {
                    $tupleFactor = 1.0;
                }
            }

            continue;
        }

        if ($midi === null) {
            $midi = resolveMidi($note, $signature, $accidentalAtBar);
        }

        $exactQuavers = $unit * $note['lengthFactor'] * 8 * $tupleFactor;

        if ($pendingBroken !== null) {
            $exactQuavers = applyBrokenRhythm(
                $pendingBroken,
                $exactQuavers,
                $events,
                $lastRhythmItem
            );
            $pendingBroken = null;
        }

        if ($exactQuavers <= 0) {
            throw new RuntimeException(
                "{$title}: Broken Rhythm ergab eine ungültige Notendauer."
            );
        }

        $events[] = [
            'midi' => $midi,
            'exactQuavers' => $exactQuavers,
            'token' => $token,
        ];

        $lastRhythmItem = [
            'type' => 'note',
            'index' => count($events) - 1,
        ];

        if ($tupleNotesLeft > 0) {
            $tupleNotesLeft--;
            if ($tupleNotesLeft === 0) {
                $tupleFactor = 1.0;
            }
        }
    }

    if (preg_match('/[><]/', substr($body, $cursor))) {
        throw new RuntimeException(
            "{$title}: Broken-Rhythm-Zeichen konnte nicht verarbeitet werden."
        );
    }

    if ($pendingBroken !== null) {
        $parseWarnings[] =
            "{$title}: alleinstehendes Broken-Rhythm-Zeichen am Ende ignoriert; " .
            'die vorhergehende Note behält ihre notierte Dauer.';
    }

    if ($events === []) {
        throw new NoMelodyNotesException(
            "{$title}: keine Melodienoten erkannt."
        );
    }

    $warnings = array_merge($parseWarnings, applyMidiRange($events));
    $contour = '';

    foreach ($events as $event) {
        $quavers = max(1, (int) round($event['exactQuavers']));
        $character = CONTOUR_CHARS[$event['midi'] - MIDI_LOW] ?? null;

        if ($character === null) {
            throw new RuntimeException(
                "{$title}: MIDI-Note liegt außerhalb des Konturbereichs."
            );
        }

        $contour .= str_repeat($character, $quavers);
    }

    return [
        'title' => $title,
        'titles' => $titles !== [] ? $titles : [$title],
        'meter' => $meter,
        'mode' => $reportedMode,
        'contour' => $contour,
        'warnings' => $warnings,
    ];
}

function tunePositionKey(
    string $tunebook,
    bool $isGroupTune,
    string $group,
    int $position
): string {
    return json_encode(
        [$tunebook, $isGroupTune ? 'group' : 'index', $group, $position],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '';
}

function removePositionEntries(
    stdClass $index,
    array &$existingByPosition,
    string $positionKey
): void {
    foreach ($existingByPosition[$positionKey] ?? [] as $oldId) {
        unset($index->settings->{$oldId});
        unset($index->aliases->{$oldId});
    }

    unset($existingByPosition[$positionKey]);
}

function countTuneMeasures(string $block): int
{
    $body = extractMusicBody($block);
    $body = preg_replace('~\[(?:K|M|L):[^\]]*\]~i', ' ', $body) ?? $body;

    $measures = preg_split(
        '~::|:\|:|\|:|:\||\|\]|\[\||\|\||\|~',
        $body
    ) ?: [];

    $count = 0;

    foreach ($measures as $measure) {
        if (preg_match(
            "~(?:\\^{1,2}|_{1,2}|=)?[A-Ga-g][,']*(?:\\d+)?(?:/+\\d*)?~",
            $measure
        )) {
            $count++;
        }
    }

    return $count;
}

function buildIndex(stdClass $index, array $sources, string $dance): array
{
    if (!isset($index->settings) || !$index->settings instanceof stdClass) {
        $index->settings = new stdClass();
    }

    if (!isset($index->aliases) || !$index->aliases instanceof stdClass) {
        $index->aliases = new stdClass();
    }

    /*
     * Bestehende Einträge einmalig nach Tunebook-Position erfassen.
     * Damit entfällt die vollständige Indexsuche bei jedem Fehler.
     */
    $existingByPosition = [];

    foreach ($index->settings as $id => $entry) {
        if (!$entry instanceof stdClass || !isset($entry->tunebook)) {
            continue;
        }

        $entryTunebook = (string) $entry->tunebook;

        if (isset($entry->group, $entry->settuneindex)) {
            $positionKey = tunePositionKey(
                $entryTunebook,
                true,
                (string) $entry->group,
                (int) $entry->settuneindex
            );
        } elseif (isset($entry->index)) {
            $positionKey = tunePositionKey(
                $entryTunebook,
                false,
                '',
                (int) $entry->index
            );
        } else {
            continue;
        }

        $existingByPosition[$positionKey][] = (string) $id;
    }

    $report = [];
    $errorDetails = [];

    foreach ($sources as $source) {
        $tunebook = trim($source['tunebook']);
        $nextTuneIndex = 1;
        $setTuneCounts = [];

        foreach (splitTunes($source['text']) as $block) {
            $isGroupTune = (bool) preg_match('/^\s*G\s*:/mi', $block);
            $group = trim(headerField($block, 'G', ''));

            if ($isGroupTune) {
                $setTuneCounts[$group] = ($setTuneCounts[$group] ?? 0) + 1;
                $setTuneIndex = $setTuneCounts[$group];
                $position = $setTuneIndex;
            } else {
                $tuneIndex = $nextTuneIndex++;
                $position = $tuneIndex;
            }

            $positionKey = tunePositionKey(
                $tunebook,
                $isGroupTune,
                $group,
                $position
            );

            try {
                $tune = parseTune($block);
                $measureCount = countTuneMeasures($block);
                if ($measureCount < 4) {
                    removePositionEntries($index, $existingByPosition, $positionKey);
                    $report[] = 'ÜBERSPRUNGEN ' . $tunebook .
                        ': nur ' . $measureCount . ' Takt(e); mindestens 4 erforderlich.';
                    continue;
                }


                /*
                 * Entferne den alten Eintrag an dieser Position vor der
                 * ID-Auswahl. So kann die bisherige Tune-ID erhalten bleiben.
                 */
                removePositionEntries($index, $existingByPosition, $positionKey);

                $baseId = slugify($tune['title']);
                $id = $baseId;
                $suffix = 2;

                while (property_exists($index->settings, $id)) {
                    $id = $baseId . '-' . $suffix++;
                }

                $entry = [
                    'tune_id' => $id,
                    'meter' => $tune['meter'],
                    'mode' => $tune['mode'],
                    'dance' => $dance,
                    'contour' => $tune['contour'],
                    'tunebook' => $tunebook,
                ];

                if ($isGroupTune) {
                    $entry['group'] = $group;
                    $entry['settuneindex'] = $setTuneIndex;

                    $report[] = "OK {$id}: Tune {$setTuneIndex} im Set „" .
                        ($group !== '' ? $group : '(ohne Gruppennamen)') . '“';
                } else {
                    $entry['index'] = $tuneIndex;

                    $report[] = 'OK ' . $id . ': Tune ' . $tuneIndex . ' in ' .
                        ($tunebook !== '' ? $tunebook : 'Tunebook');
                }

                $index->settings->{$id} = (object) $entry;
                $index->aliases->{$id} = array_values(array_unique($tune['titles']));

                foreach ($tune['warnings'] as $warning) {
                    $report[] = "WARNUNG {$id}: {$warning}";
                }
            } catch (NoMelodyNotesException $error) {
                removePositionEntries($index, $existingByPosition, $positionKey);
                $report[] = 'ÜBERSPRUNGEN ' . $tunebook . ': ' . $error->getMessage();
            } catch (Throwable $error) {
                $message = $error->getMessage();

                /*
                 * Fehlerhafte Tunes – darunter Tunes ohne Melodienoten –
                 * werden nicht indexiert. Einen alten Eintrag derselben
                 * Position ebenfalls entfernen.
                 */
                removePositionEntries($index, $existingByPosition, $positionKey);

                $report[] = 'FEHLER ' . $message;

                $errorDetails[] = [
                    'message' => $message,
                    'tunebook' => $tunebook,
                    'abc' => trim($block),
                ];
            }
        }
    }

    return [$index, $report, $errorDetails];
}

function html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$root = __DIR__;
$tunebooksDirectory = $root . DIRECTORY_SEPARATOR . 'tunebooks';
$indexPath = $root . DIRECTORY_SEPARATOR . 'folkfriend-non-user-data.json';
$metaPath = $root . DIRECTORY_SEPARATOR . 'nud-meta.json';

$errorDetails = [];

try {
    $dance = trim($argv[1] ?? '');

    if (!is_dir($tunebooksDirectory)) {
        fail("Verzeichnis nicht gefunden: {$tunebooksDirectory}");
    }

    $abcFiles = glob($tunebooksDirectory . DIRECTORY_SEPARATOR . '*.abc');

    if ($abcFiles === false) {
        fail("Konnte ABC-Dateien in {$tunebooksDirectory} nicht auflisten.");
    }

    sort($abcFiles, SORT_NATURAL | SORT_FLAG_CASE);

    if ($abcFiles === []) {
        fail("Keine .abc-Dateien in {$tunebooksDirectory} gefunden.");
    }

    $sources = [];

    foreach ($abcFiles as $path) {
        $text = file_get_contents($path);

        if ($text === false) {
            fail('Konnte ABC-Datei nicht lesen: ' . basename($path));
        }

        $sources[] = [
            'text' => $text,
            'tunebook' => basename($path),
        ];
    }

    $index = readJsonObject($indexPath, true);

    if (is_file($indexPath) &&
        (!isset($index->settings) || !$index->settings instanceof stdClass ||
         !isset($index->aliases) || !$index->aliases instanceof stdClass)) {
        fail('Die bestehende JSON muss Objektfelder „settings“ und „aliases“ enthalten.');
    }

    [$index, $report, $errorDetails] = buildIndex($index, $sources, $dance);

    $indexJson = json_encode(
        $index,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $meta = readJsonObject($metaPath, true);
    $meta->v = gmdate('Y-m-d\TH:i:s\Z');

    $metaJson = json_encode(
        $meta,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    writeJsonAtomically($indexPath, $indexJson);
    writeJsonAtomically($metaPath, $metaJson);

    $errorCount = count(array_filter(
        $report,
        static fn(string $line): bool => str_starts_with($line, 'FEHLER ')
    ));

    $successCount = count(array_filter(
        $report,
        static fn(string $line): bool => str_starts_with($line, 'OK ')
    ));

    $pageTitle = 'ABC-Update abgeschlossen';
    $summary = [
        'Verarbeitete ABC-Dateien: ' . count($abcFiles),
        'Erfolgreiche Tunes: ' . $successCount,
        'Fehler: ' . $errorCount,
        'Übersprungene Tunes: ' . count(array_filter(
            $report,
            static fn(string $line): bool => str_starts_with($line, 'ÜBERSPRUNGEN ')
        )),
        'Zeitstempel: ' . $meta->v,
    ];
} catch (Throwable $error) {
    $pageTitle = 'Fehler beim ABC-Update';
    $summary = [];
    $report = ['FEHLER ' . $error->getMessage()];
    $errorCount = 1;
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=UTF-8');
}

echo '<!doctype html>';
echo '<html lang="de"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>' . html($pageTitle) . '</title>';
echo '<style>
    body {
        font: 16px/1.6 system-ui, sans-serif;
        max-width: 960px;
        margin: 2rem auto;
        padding: 0 1rem;
        color: #17212b;
    }
    h1 { font-size: 1.5rem; }
    .summary {
        padding: 1rem;
        background: #eef6fb;
        border-left: 4px solid #1681b5;
        margin-bottom: 1.5rem;
    }
    .report {
        overflow-wrap: anywhere;
        white-space: normal;
    }
    .report-line { margin: 0 0 .5rem; }
    .error { color: #a21d1d; }
    .warning { color: #805b00; }
    .skipped { color: #805b00; }
    .success { color: #176b35; }
    .copy-error {
        margin-left: .5rem;
        padding: .25rem .6rem;
        border: 1px solid #a21d1d;
        border-radius: .3rem;
        background: #fff;
        color: #a21d1d;
        cursor: pointer;
        font: inherit;
        font-size: .9rem;
    }
    .copy-error:hover { background: #fff0f0; }
    .copy-status {
        margin-left: .35rem;
        font-size: .9rem;
        color: #17212b;
    }
</style></head><body>';

echo '<h1>' . html($pageTitle) . '</h1>';

if ($summary !== []) {
    echo '<div class="summary">';

    foreach ($summary as $line) {
        echo html($line) . '<br>';
    }

    echo '</div>';
}

echo '<div class="report">';

$errorDetailIndex = 0;

foreach ($report as $line) {
    $class = 'report-line';

    if (str_starts_with($line, 'FEHLER ')) {
        $class .= ' error';
    } elseif (str_starts_with($line, 'ÜBERSPRUNGEN ')) {
        $class .= ' skipped';
    } elseif (str_starts_with($line, 'WARNUNG ')) {
        $class .= ' warning';
    } elseif (str_starts_with($line, 'OK ')) {
        $class .= ' success';
    }

    echo '<div class="' . $class . '">';
    echo '<span>' . html($line) . '</span>';

    if (str_starts_with($line, 'FEHLER ')) {
        $detail = $errorDetails[$errorDetailIndex] ?? null;
        $errorDetailIndex++;

        if ($detail !== null) {
            $copyText =
                'FEHLER: ' . $detail['message'] . "\n" .
                'ABC-Datei: ' . $detail['tunebook'] . "\n\n" .
                $detail['abc'];

            $copyPayload = json_encode(
                $copyText,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT |
                JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($copyPayload === false) {
                $copyPayload = '""';
            }

            echo ' <button type="button" class="copy-error" data-copy="' .
                html($copyPayload) .
                '">Fehlermeldung + ABC-Tune kopieren</button>';
            echo ' <span class="copy-status" aria-live="polite"></span>';
        }
    }

    echo '</div>';
}

echo <<<'HTML'
</div>
<script>
document.addEventListener('click', async function (event) {
    const button = event.target.closest('.copy-error');
    if (!button) {
        return;
    }

    const status = button.parentElement.querySelector('.copy-status');
    let text;

    try {
        text = JSON.parse(button.dataset.copy);
    } catch (error) {
        if (status) {
            status.textContent = 'Kopieren fehlgeschlagen.';
        }
        return;
    }

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            const copied = document.execCommand('copy');
            textarea.remove();

            if (!copied) {
                throw new Error('Kopieren fehlgeschlagen');
            }
        }

        if (status) {
            status.textContent = 'Kopiert!';
        }
    } catch (error) {
        if (status) {
            status.textContent = 'Kopieren fehlgeschlagen.';
        }
    }
});
</script>
</body></html>
HTML;
