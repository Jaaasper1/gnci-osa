<?php
// ============================================================
// OSA FAQ. Used by the OSA Guide page and the Ask OSA bubble.
// Staff: replace each TODO answer with the official OSA information.
// - Entries whose answer starts with "TODO" are hidden everywhere.
// - Entries marked 'guide_only' => true show in the OSA Guide only, not in the Ask OSA bubble.
// ============================================================
function osaFaqEntries(): array {
    $entries = [

       
    ];

    return array_values(array_filter($entries, function ($e) {
        $a = trim($e['a']);
        // Skip unfinished entries: TODO, N/A, or placeholder text such as "ilagay ..." or "[...]"
        if (strpos($a, 'TODO') === 0) return false;
        if (strcasecmp($a, 'N/A') === 0 || strcasecmp($a, 'N/A.') === 0) return false;
        if (stripos($a, 'ilagay') !== false || strpos($a, '[') !== false) return false;
        return $a !== '';
    }));
}