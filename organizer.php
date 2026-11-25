<?php
declare(strict_types=1);

const CAMPUS_CITY_OPTIONS = [
    'University of Lagos (UNILAG)',
    'Federal University Oye-Ekiti (FUOYE)',
    'University of Ibadan (UI)',
    'Federal University of Technology, Akure (FUTA)',
    'Obafemi Awolowo University (OAU Ife)',
    'Ahmadu Bello University (ABU Zaria)',
    'Bayero University Kano (BUK)',
    'University of Nigeria, Nsukka (UNN)',
    'University of Benin (UNIBEN)',
    'University of Ilorin (UNILORIN)',
    'Lagos State University (LASU)',
    'University of Port Harcourt (UNIPORT)',
    'Nnamdi Azikiwe University (UNIZIK)',
    'Federal University of Technology, Owerri (FUTO)',
    'Federal University of Agriculture, Abeokuta (FUNAAB)',
    'University of Calabar (UNICAL)',
    'University of Uyo (UNIUYO)',
    'Ekiti State University (EKSU)',
    'Ladoke Akintola University of Technology (LAUTECH)',
    'Olabisi Onabanjo University (OOU)',
    'Delta State University (DELSU)',
    'Ambrose Alli University (AAU Ekpoma)',
    'Kwara State University (KWASU)',
    'University of Jos (UNIJOS)',
    'University of Maiduguri (UNIMAID)',
    'Covenant University, Ota',
    'Babcock University',
    'Afe Babalola University (ABUAD)',
    'Yaba College of Technology (YABATECH)',
    'Lagos',
    'Abuja (FCT)',
    'Kano',
    'Ibadan',
    'Port Harcourt',
    'Enugu',
    'Kaduna',
    'Benin City',
    'Ado-Ekiti, Ekiti',
    'Akure, Ondo',
    'Abeokuta, Ogun',
    'Osogbo, Osun',
    'Ilorin, Kwara',
    'Jos, Plateau',
    'Owerri, Imo',
    'Awka, Anambra',
    'Onitsha, Anambra',
    'Warri, Delta',
    'Asaba, Delta',
    'Umuahia, Abia',
    'Aba, Abia',
    'Calabar, Cross River',
    'Uyo, Akwa Ibom',
    'Yenagoa, Bayelsa',
    'Abakaliki, Ebonyi',
    'Makurdi, Benue',
    'Lokoja, Kogi',
    'Minna, Niger',
    'Lafia, Nasarawa',
    'Maiduguri, Borno',
    'Sokoto',
    'Katsina',
    'Zaria, Kaduna',
    'Bauchi',
    'Gombe',
    'Yola, Adamawa',
    'Jalingo, Taraba',
    'Damaturu, Yobe',
    'Gusau, Zamfara',
    'Birnin Kebbi, Kebbi',
    'Dutse, Jigawa',
];

const LOCATION_RULES = [
    // Tier 1: Nigerian Universities, Polytechnics & Campuses
    ['University of Lagos (UNILAG)', ['/\bunilag\b/iu', '/university of lagos/iu', '/\bakoka\b/iu']],
    ['Federal University Oye-Ekiti (FUOYE)', ['/\bfuoye\b/iu', '/federal university oye/iu', '/oye[\s\-]+ekiti/iu']],
    ['University of Ibadan (UI)', ['/university of ibadan/iu', '/\bu\.i\b/iu', '/\bui\s+ibadan\b/iu']],
    ['Federal University of Technology, Akure (FUTA)', ['/\bfuta\b/iu', '/federal university of technology,?\s*akure/iu']],
    ['Obafemi Awolowo University (OAU Ife)', ['/\boau\b/iu', '/obafemi awolowo university/iu', '/ile[\s\-]+ife/iu']],
    ['Ahmadu Bello University (ABU Zaria)', ['/\babu\s+zaria\b/iu', '/ahmadu bello university/iu']],
    ['Bayero University Kano (BUK)', ['/\bbuk\b/iu', '/bayero university/iu']],
    ['University of Nigeria, Nsukka (UNN)', ['/\bunn\b/iu', '/university of nigeria/iu', '/\bnsukka\b/iu']],
    ['University of Benin (UNIBEN)', ['/\buniben\b/iu', '/university of benin/iu']],
    ['University of Ilorin (UNILORIN)', ['/\bunilorin\b/iu', '/university of ilorin/iu']],
    ['Lagos State University (LASU)', ['/\blasu\b/iu', '/lagos state university/iu']],
    ['University of Port Harcourt (UNIPORT)', ['/\buniport\b/iu', '/university of port harcourt/iu']],
    ['Nnamdi Azikiwe University (UNIZIK)', ['/\bunizik\b/iu', '/nnamdi azikiwe university/iu']],
    ['Federal University of Technology, Owerri (FUTO)', ['/\bfuto\b/iu', '/federal university of technology,?\s*owerri/iu']],
    ['Federal University of Agriculture, Abeokuta (FUNAAB)', ['/\bfunaab\b/iu', '/federal university of agriculture,?\s*abeokuta/iu']],
    ['University of Calabar (UNICAL)', ['/\bunical\b/iu', '/university of calabar/iu']],
    ['University of Uyo (UNIUYO)', ['/\buniuyo\b/iu', '/university of uyo/iu']],
    ['Ekiti State University (EKSU)', ['/\beksu\b/iu', '/ekiti state university/iu']],
    ['Ladoke Akintola University of Technology (LAUTECH)', ['/\blautech\b/iu', '/ladoke akintola/iu', '/\bogbomoso\b/iu']],
    ['Olabisi Onabanjo University (OOU)', ['/\boou\b/iu', '/olabisi onabanjo/iu', '/ago[\s\-]+iwoye/iu']],
    ['Delta State University (DELSU)', ['/\bdelsu\b/iu', '/delta state university/iu', '/\babraka\b/iu']],
    ['Ambrose Alli University (AAU Ekpoma)', ['/\baau\b/iu', '/ambrose alli/iu', '/\bekpoma\b/iu']],
    ['Kwara State University (KWASU)', ['/\bkwasu\b/iu', '/kwara state university/iu']],
    ['University of Jos (UNIJOS)', ['/\bunijos\b/iu', '/university of jos/iu']],
    ['University of Maiduguri (UNIMAID)', ['/\bunimaid\b/iu', '/university of maiduguri/iu']],
    ['Usmanu Danfodiyo University (UDUS)', ['/\budus\b/iu', '/usmanu danfodiyo/iu']],
    ['Federal University of Technology, Minna (FUTMINNA)', ['/\bfutminna\b/iu']],
    ['Covenant University, Ota', ['/covenant university/iu']],
    ['Babcock University', ['/babcock university/iu']],
    ['Afe Babalola University (ABUAD)', ['/\babuad\b/iu', '/afe babalola/iu']],
    ['Yaba College of Technology (YABATECH)', ['/\byabatech\b/iu', '/yaba college of technology/iu']],
    ['Auchi Polytechnic', ['/\bauchipoly\b/iu', '/auchi polytechnic/iu']],
    ['Federal Polytechnic, Nekede', ['/poly\s+nekede/iu', '/\bfpno\b/iu', '/\bnekede\b/iu']],

    // Tier 2: All 36 Nigerian States + FCT + Major Cities
    ['Abuja (FCT)', ['/\babuja\b/iu', '/\bfct\b/iu', '/\baso\s+rock\b/iu', '/\bgarki\b/iu', '/\bwuse\b/iu', '/\bmaitama\b/iu']],
    ['Lagos', ['/\blagos\b/iu', '/\bikeja\b/iu', '/\blekki\b/iu', '/\bikoyi\b/iu', '/\byaba\b/iu', '/\bsurulere\b/iu', '/\bvictoria\s+island\b/iu', '/\bbadagry\b/iu', '/\bikorodu\b/iu', '/\bepe\b/iu']],
    ['Ibadan', ['/\bibadan\b/iu']],
    ['Port Harcourt', ['/port\s+harcourt/iu', '/\bph\s+city\b/iu']],
    ['Ado-Ekiti, Ekiti', ['/ado[\s\-]+ekiti/iu', '/\bekiti\b/iu']],
    ['Akure, Ondo', ['/\bakure\b/iu', '/\bondo\b/iu']],
    ['Abeokuta, Ogun', ['/\babeokuta\b/iu', '/\bogun\b/iu', '/\bsagamu\b/iu', '/\bijebu\b/iu']],
    ['Osogbo, Osun', ['/\bosogbo\b/iu', '/\bosun\b/iu', '/\bede\b/iu']],
    ['Ilorin, Kwara', ['/\bilorin\b/iu', '/\bkwara\b/iu']],
    ['Benin City', ['/benin\s+city/iu', '/\bedo\s+state\b/iu']],
    ['Warri, Delta', ['/\bwarri\b/iu']],
    ['Asaba, Delta', ['/\basaba\b/iu', '/\bdelta\s+state\b/iu']],
    ['Enugu', ['/\benugu\b/iu']],
    ['Awka, Anambra', ['/\bawka\b/iu', '/\banambra\b/iu']],
    ['Onitsha, Anambra', ['/\bonitsha\b/iu', '/\bnnewi\b/iu']],
    ['Owerri, Imo', ['/\bowerri\b/iu', '/\bimo\s+state\b/iu']],
    ['Umuahia, Abia', ['/\bumuahia\b/iu', '/\babia\b/iu']],
    ['Aba, Abia', ['/\baba\b/iu']],
    ['Calabar, Cross River', ['/\bcalabar\b/iu', '/cross\s+river/iu']],
    ['Uyo, Akwa Ibom', ['/\buyo\b/iu', '/akwa\s+ibom/iu']],
    ['Yenagoa, Bayelsa', ['/\byenagoa\b/iu', '/\bbayelsa\b/iu']],
    ['Abakaliki, Ebonyi', ['/\babakaliki\b/iu', '/\bebonyi\b/iu']],
    ['Kaduna', ['/\bkaduna\b/iu']],
    ['Zaria, Kaduna', ['/\bzaria\b/iu']],
    ['Kano', ['/\bkano\b/iu']],
    ['Katsina', ['/\bkatsina\b/iu']],
    ['Sokoto', ['/\bsokoto\b/iu']],
    ['Maiduguri, Borno', ['/\bmaiduguri\b/iu', '/\bborno\b/iu']],
    ['Jos, Plateau', ['/\bjos\b/iu', '/\bplateau\b/iu']],
    ['Makurdi, Benue', ['/\bmakurdi\b/iu', '/\bbenue\b/iu']],
    ['Lokoja, Kogi', ['/\blokoja\b/iu', '/\bkogi\b/iu']],
    ['Minna, Niger', ['/\bminna\b/iu', '/\bniger\s+state\b/iu']],
    ['Lafia, Nasarawa', ['/\blafia\b/iu', '/\bnasarawa\b/iu']],
    ['Bauchi', ['/\bbauchi\b/iu']],
    ['Gombe', ['/\bgombe\b/iu']],
    ['Yola, Adamawa', ['/\byola\b/iu', '/\badamawa\b/iu']],
    ['Jalingo, Taraba', ['/\bjalingo\b/iu', '/\btaraba\b/iu']],
    ['Damaturu, Yobe', ['/\bdamaturu\b/iu', '/\byobe\b/iu']],
    ['Gusau, Zamfara', ['/\bgusau\b/iu', '/\bzamfara\b/iu']],
    ['Birnin Kebbi, Kebbi', ['/birnin\s+kebbi/iu', '/\bkebbi\b/iu']],
    ['Dutse, Jigawa', ['/\bdutse\b/iu', '/\bjigawa\b/iu']],

    // Tier 3: Global / Foreign Cities, US States & Countries
    ['Florida, USA', ['/\bflorida\b/iu', '/\bmiami\b/iu', '/\borlando\b/iu', '/brevard\s+county/iu', '/\btampa\b/iu']],
    ['San Francisco, USA', ['/san\s+francisco/iu', '/\bsilicon\s+valley\b/iu']],
    ['Seattle, USA', ['/\bseattle\b/iu', '/seattle[\s\-]+tacoma/iu']],
    ['California, USA', ['/\bcalifornia\b/iu', '/los\s+angeles/iu', '/\bhollywood\b/iu', '/san\s+diego/iu']],
    ['New York, USA', ['/new\s+york/iu', '/\bmanhattan\b/iu', '/\bbrooklyn\b/iu', '/\bwall\s+street\b/iu']],
    ['Washington, D.C., USA', ['/washington,?\s*d\.?c\.?/iu', '/capitol\s+hill/iu', '/white\s+house/iu', '/\bu\.?s\.?\s+senate\b/iu', '/\bpentagon\b/iu']],
    ['Arizona, USA', ['/\barizona\b/iu', '/\bphoenix\b/iu']],
    ['Ohio, USA', ['/\bcincinnati\b/iu', '/\bohio\b/iu', '/\bcleveland\b/iu']],
    ['Texas, USA', ['/\btexas\b/iu', '/\bhouston\b/iu', '/\bdallas\b/iu', '/\baustin\b/iu']],
    ['Chicago, USA', ['/\bchicago\b/iu', '/\billinois\b/iu']],
    ['Atlanta, USA', ['/\batlanta\b/iu', '/\bgeorgia\b/iu']],
    ['United Kingdom', ['/\buk\b/iu', '/united\s+kingdom/iu', '/\bbritain\b/iu', '/\bbritish\b/iu', '/\blondon\b/iu', '/\bmanchester\b/iu', '/\bbrexit\b/iu', '/prime\s+minister\s+theresa\s+may/iu', '/andy\s+burnham/iu']],
    ['North Atlantic', ['/north\s+atlantic/iu', '/\biceland\b/iu']],
    ['Iran', ['/\biran\b/iu', '/\btehran\b/iu']],
    ['Italy', ['/\bitaly\b/iu', '/\brome\b/iu', '/\bmilan\b/iu']],
    ['Spain', ['/\bspain\b/iu', '/\bmadrid\b/iu', '/\bbarcelona\b/iu']],
    ['France', ['/\bfrance\b/iu', '/\bparis\b/iu']],
    ['Germany', ['/\bgermany\b/iu', '/\bberlin\b/iu']],
    ['South Africa', ['/south\s+africa/iu', '/\bjohannesburg\b/iu']],
    ['Ghana', ['/\bghana\b/iu', '/\baccra\b/iu']],
    ['Kenya', ['/\bkenya\b/iu', '/\bnairobi\b/iu']],
    ['Egypt', ['/\begypt\b/iu', '/\bcairo\b/iu']],
    ['Morocco', ['/\bmorocco\b/iu', '/\brabat\b/iu']],
    ['Canada', ['/\bcanada\b/iu', '/\btoronto\b/iu', '/\bottawa\b/iu']],
    ['India', ['/\bindia\b/iu', '/\bmumbai\b/iu', '/\bdelhi\b/iu', '/\bchennai\b/iu']],
    ['China', ['/\bchina\b/iu', '/\bbeijing\b/iu', '/\bshanghai\b/iu']],
    ['Russia', ['/\brussia\b/iu', '/\bmoscow\b/iu']],
    ['Ukraine', ['/\bukraine\b/iu', '/\bkyiv\b/iu']],
    ['Israel', ['/\bisrael\b/iu', '/\btel\s+aviv\b/iu', '/\bjerusalem\b/iu']],
    ['Palestine / Gaza', ['/\bgaza\b/iu', '/\bpalestine\b/iu']],
    ['Saudi Arabia', ['/saudi\s+arabia/iu', '/\briyadh\b/iu']],
    ['UAE / Dubai', ['/\bdubai\b/iu', '/\buae\b/iu', '/abu\s+dhabi/iu']],
    ['Australia', ['/\baustralia\b/iu', '/\bsydney\b/iu', '/\bmelbourne\b/iu']],
    ['Japan', ['/\bjapan\b/iu', '/\btokyo\b/iu']],
    ['Brazil', ['/\bbrazil\b/iu']],
    ['Nigeria', ['/\bnigeria\b/iu', '/\bnigerian\b/iu', '/\bnysc\b/iu', '/\bnelfund\b/iu']],
    ['United States', ['/\busa\b/iu', '/united\s+states/iu']],
];

function detect_actual_location(string $fullText, string $sourceContext = ''): string
{
    foreach (LOCATION_RULES as [$canonicalLabel, $patterns]) {
        foreach ($patterns as $pat) {
            if (preg_match($pat, $fullText)) {
                return $canonicalLabel;
            }
        }
    }

    if (trim($sourceContext) !== '') {
        foreach (LOCATION_RULES as [$canonicalLabel, $patterns]) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $sourceContext)) {
                    return $canonicalLabel;
                }
            }
        }
    }

    if (preg_match('/\b(?:in|at|from|across|throughout)\s+([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+){0,2}\s+(?:County|State|City|University|College|Polytechnic|Island|Airport))/', $fullText, $m)) {
        return trim($m[1]);
    }

    return '';
}

function is_comment_ui_line(string $line): bool
{
    $noise = [
        'like', 'comment', 'share', 'send', 'all reactions:', 'most relevant',
        'write a comment…', 'write a comment...', 'view more comments',
        'see more', 'see less', 'read more', 'view channel', 'facebook',
        'reels', 'more', 'forwarded', 'added you',
    ];
    if (in_array(mb_strtolower($line), $noise, true)) {
        return true;
    }
    return (bool)preg_match(
        '/^(?:view\s+(?:all\s+|more\s+|previous\s+)?\d*\s*(?:replies|reply|comments?)|\d+(?:[.,]\d+)?[kmb]?\s+(?:comments?|replies|reply|shares?|views?|reactions?|followers?)|\d{1,2}:\d{2}\s*(?:am|pm)?|most\s+relevant|all\s+comments|top\s+fan|author|follow|following|original\s+audio|forwarded)$/iu',
        $line
    );
}

function organize_post_for_elo54(string $rawCaption, ?string $explicitLink = null): array
{
    preg_match_all('/https?:\/\/\S+/iu', $rawCaption, $urlMatches);
    $urlsInText = $urlMatches[0] ?? [];
    $sourceLink = ($explicitLink !== null && trim($explicitLink) !== '')
        ? trim($explicitLink)
        : (!empty($urlsInText) ? rtrim($urlsInText[0], '.,;)') : null);

    $emptySocialOrDomainPattern = '/^(?:(?:linkedin|facebook|twitter|x|tiktok|youtube|instagram|telegram|whatsapp|website|portal|link|source|subscribe|don\'t forget to subscribe)\s*[:\-–—]?|(?:www\.)?[a-z0-9\-]+\.(?:com|org|net|edu\.ng|gov\.ng|ng|co\.uk|tv|io)|select\s+your\s+answer)$/iu';

    $bodyLines = [];
    $seenNorm = [];

    foreach (preg_split('/\r?\n/', $rawCaption) as $line) {
        $cleaned = trim((string)preg_replace('/https?:\/\/\S+/iu', '', $line));
        $cleaned = trim((string)preg_replace('/(?:\s*(?:\.\.\.|…)?\s*\b(?:see\s+more|see\s+less|read\s+more)\b)+\s*$/iu', '', $cleaned));
        $cleaned = trim((string)preg_replace('/(?:\s*(?:read\s+more|full\s+story|more\s+info|learn\s+more|details|source|link|don\'t\s+forget\s+to\s+subscribe)\s*[:\-–—]?\s*)+$/iu', '', $cleaned));

        if (str_ends_with($cleaned, '[…]') || str_ends_with($cleaned, '[...]')) {
            continue;
        }
        if ($cleaned === '' || ctype_digit($cleaned) || is_comment_ui_line($cleaned) || preg_match($emptySocialOrDomainPattern, $cleaned)) {
            continue;
        }

        $norm = rtrim((string)preg_replace('/\s+/u', ' ', mb_strtolower($cleaned)), ".-–— ");
        if (isset($seenNorm[$norm])) {
            continue;
        }
        $seenNorm[$norm] = true;
        $bodyLines[] = $cleaned;
    }

    // Remove long prefix lines (>= 35 chars) that are exact prefixes of another line
    $filteredLines = [];
    foreach ($bodyLines as $line) {
        $norm = rtrim((string)preg_replace('/\s+/u', ' ', mb_strtolower($line)), ".-–— ");
        $isPrefixOfAnother = false;
        if (mb_strlen($norm) >= 35) {
            foreach ($bodyLines as $other) {
                if ($other !== $line) {
                    $otherNorm = (string)preg_replace('/\s+/u', ' ', mb_strtolower($other));
                    if (str_starts_with($otherNorm, $norm) && mb_strlen($other) > mb_strlen($line) + 5) {
                        $isPrefixOfAnother = true;
                        break;
                    }
                }
            }
        }
        if (!$isPrefixOfAnother) {
            $filteredLines[] = $line;
        }
    }

    $details = !empty($filteredLines) ? implode("\n\n", $filteredLines) : trim($rawCaption);
    $firstLine = !empty($filteredLines) ? $filteredLines[0] : $details;
    $sentences = preg_split('/(?<=[.!?])\s+/u', $firstLine);
    $headline = trim((string)($sentences[0] ?? $firstLine));
    if ($headline === '') {
        $headline = $firstLine;
    }

    // Convert ALL CAPS headline into sentence case
    preg_match_all('/[A-Za-z]/', $headline, $alphaMatches);
    $alphaCount = count($alphaMatches[0] ?? []);
    if ($alphaCount > 0) {
        preg_match_all('/[A-Z]/', $headline, $upperMatches);
        $upperCount = count($upperMatches[0] ?? []);
        if ($upperCount / $alphaCount > 0.65) {
            $lower = mb_strtolower($headline);
            $headline = mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        }
    }

    $headline = rtrim($headline, '.');
    if (mb_strlen($headline) > 140) {
        $cut = mb_substr($headline, 0, 137);
        $lastSpace = mb_strrpos($cut, ' ');
        $headline = ($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut) . '...';
    }

    if (count($filteredLines) > 1 && mb_strtolower(rtrim($filteredLines[0], '.')) === mb_strtolower($headline)) {
        $details = trim(implode("\n\n", array_slice($filteredLines, 1)));
    }

    return [$headline, $details, $sourceLink];
}

function clean_facebook_redirect_url(string $rawHref): ?string
{
    if ($rawHref === '') {
        return null;
    }
    $parts = parse_url($rawHref);
    if (!$parts) {
        return null;
    }
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';

    if (str_contains($host, 'facebook.com') && str_starts_with($path, '/l.php')) {
        parse_str($parts['query'] ?? '', $qs);
        if (empty($qs['u'])) {
            return null;
        }
        $target = urldecode((string)$qs['u']);
    } elseif ($host !== '' && !str_contains($host, 'facebook.com') && !str_contains($host, 'fb.com') && !str_contains($host, 'fb.watch') && !str_contains($host, 'fbcdn.net')) {
        $target = $rawHref;
    } else {
        return null;
    }

    $tp = parse_url($target);
    $scheme = strtolower($tp['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    parse_str($tp['query'] ?? '', $qParams);
    unset($qParams['fbclid']);
    $cleanQuery = http_build_query($qParams);
    $port = isset($tp['port']) ? ':' . $tp['port'] : '';
    return $scheme . '://' . ($tp['host'] ?? '') . $port . ($tp['path'] ?? '') . ($cleanQuery !== '' ? '?' . $cleanQuery : '');
}
