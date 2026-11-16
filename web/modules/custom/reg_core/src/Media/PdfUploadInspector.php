<?php

namespace Drupal\reg_core\Media;

/**
 * Checks PDF structure without mistaking compressed scan bytes for actions.
 */
final class PdfUploadInspector {

  private const ACTIVE_NAMES = [
    'js', 'javascript', 'launch', 'embeddedfile', 'embeddedfiles',
    'openaction', 'aa', 'richmedia', 'xfa', 'objstm', 'encrypt',
    'submitform', 'importdata', 'gotor', 'gotoe', 'rendition',
    'ef', 'filespec',
  ];

  /**
   * TRUE when the document contains active features or cannot be inspected.
   */
  public static function isUnsafe(string $path): bool {
    $pdf = @file_get_contents($path);
    if (!is_string($pdf) || !preg_match('/\A%PDF-[0-9]\.[0-9]/', $pdf)
      || !preg_match('/%%EOF[\x00\x09\x0A\x0C\x0D\x20]*\z/', $pdf)) {
      return TRUE;
    }

    $length = strlen($pdf);
    $position = 0;
    while ($position < $length) {
      $char = $pdf[$position];
      if ($char === '%') {
        // PDF comments, including the header, end at the next newline.
        $position += strcspn($pdf, "\r\n", $position);
        if ($position < $length) {
          $position++;
        }
        continue;
      }
      if ($char === '(') {
        // A PDF literal string is inert even when it contains "/JS".
        $level = 1;
        $position++;
        while ($position < $length && $level > 0) {
          if ($pdf[$position] === '\\') {
            $position += 2;
            continue;
          }
          if ($pdf[$position] === '(') {
            $level++;
          }
          elseif ($pdf[$position] === ')') {
            $level--;
          }
          $position++;
        }
        if ($level !== 0) {
          return TRUE;
        }
        continue;
      }
      if ($char === '<' && ($pdf[$position + 1] ?? '') === '<') {
        $position += 2;
        continue;
      }
      if ($char === '<') {
        // PDF hex strings are inert, but malformed ones are rejected.
        $end = strpos($pdf, '>', $position + 1);
        if ($end === FALSE || preg_match('/[^0-9a-f\s]/i', substr($pdf, $position + 1, $end - $position - 1))) {
          return TRUE;
        }
        $position = $end + 1;
        continue;
      }
      if ($char === '/') {
        // Only names outside strings/streams can define PDF actions.
        $start = ++$position;
        while ($position < $length && !self::isSeparator($pdf[$position])) {
          $position++;
        }
        $name = substr($pdf, $start, $position - $start);
        // Names can hex-escape letters, e.g. /J#53 means /JS.
        $name = preg_replace_callback('/#([0-9a-f]{2})/i', static fn(array $m): string => chr(hexdec($m[1])), $name);
        if (in_array(strtolower($name), self::ACTIVE_NAMES, TRUE)) {
          return TRUE;
        }
        continue;
      }
      if (($char === '<' && ($pdf[$position + 1] ?? '') === '?')
        || strncasecmp(substr($pdf, $position, 7), '<script', 7) === 0) {
        return TRUE;
      }
      if (($position === 0 || self::isSeparator($pdf[$position - 1]))
        && substr($pdf, $position, 6) === 'stream'
        && in_array($pdf[$position + 6] ?? '', ["\r", "\n"], TRUE)) {
        $begin = $position + 7;
        if ($pdf[$position + 6] === "\r" && ($pdf[$position + 7] ?? '') === "\n") {
          $begin++;
        }
        $end = strpos($pdf, 'endstream', $begin);
        // A valid endstream marker begins immediately after an EOL.
        while ($end !== FALSE && !in_array($pdf[$end - 1] ?? '', ["\r", "\n"], TRUE)) {
          $end = strpos($pdf, 'endstream', $end + 9);
        }
        if ($end === FALSE) {
          return TRUE;
        }
        $position = $end + 9;
        continue;
      }
      $position++;
    }
    return FALSE;
  }

  private static function isSeparator(string $char): bool {
    return ord($char) <= 32 || ord($char) === 127 || str_contains('()<>[]{}/%', $char);
  }

}
