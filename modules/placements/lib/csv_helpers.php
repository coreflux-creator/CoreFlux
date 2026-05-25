<?php
/**
 * Side-effect-free helpers for the placements CSV import path. Lives in
 * /modules/placements/lib so smoke tests can require it without invoking
 * the api_bootstrap / api_require_auth chain that the /api file pulls in.
 */
declare(strict_types=1);

if (!function_exists('placementsCsvNormaliseEmail')) {
    /**
     * Normalise a CSV email cell before lookup. Strips Unicode whitespace
     * (NBSP `\u00A0`, BOM `\uFEFF`, zero-width chars, etc.) that Excel /
     * Google Sheets exports frequently embed and that survive `trim()`.
     *
     * Without this, `cecibelbravo691@gmail.com<NBSP>` from a sheet column
     * misses an exact-match against the DB's clean string and the operator
     * sees "not found in this tenant's People" for a person who clearly
     * exists — observed 2026-02 in a 1099-classification sewing-machine
     * operator import.
     */
    function placementsCsvNormaliseEmail(string $raw): string
    {
        // \p{Z} = all separators (space, NBSP, line/paragraph separator)
        // \p{C} = control + format chars (BOM, zero-width joiner, etc.)
        $clean = preg_replace('/[\p{Z}\p{C}]/u', '', $raw);
        return strtolower(trim((string) $clean));
    }
}
