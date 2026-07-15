<?php
/**
 * CSV Cell Sanitizer (v2.2 CNV-06).
 *
 * Neutralises CSV / formula injection (OWASP) in exported spreadsheets.
 * A cell whose first character is one of = + - @ (or a leading tab / CR)
 * can be interpreted as a formula by Excel / Google Sheets / LibreOffice
 * when the file is opened — turning attacker-authored chat text into code
 * execution on the merchant's machine. The standard mitigation is to
 * prefix such a cell with a single quote so the spreadsheet renders it as
 * literal text.
 *
 * Only string cells are touched; integers / floats produced by the
 * exporter (ids, counts, formatted revenue) are returned unchanged so a
 * legitimate negative number is never corrupted.
 *
 * @package TrillChatLite\Conversations
 * @since 2.2.0
 * @license GPL-2.0-or-later
 */

namespace TrillChatLite\Conversations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CsvCellSanitizer.
 *
 * SOLID: Single Responsibility — neutralise one CSV cell. Stateless, so
 * exposed as a pure static helper the exporter depends on.
 */
class CsvCellSanitizer {

    /**
     * Characters that make a spreadsheet treat a cell as a formula.
     *
     * @var string[]
     */
    private const RISKY_PREFIXES = [ '=', '+', '-', '@', "\t", "\r" ];

    /**
     * Neutralise a single CSV cell.
     *
     * @param mixed $value Raw cell value (string cells are guarded; other
     *                     scalar types pass through untouched).
     * @return mixed Safe cell value.
     */
    public static function sanitize( $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return $value;
        }

        if ( in_array( $value[0], self::RISKY_PREFIXES, true ) ) {
            return "'" . $value;
        }

        return $value;
    }
}
