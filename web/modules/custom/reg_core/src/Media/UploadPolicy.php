<?php

namespace Drupal\reg_core\Media;

/**
 * Validates actual bytes rather than trusting extensions or client MIME.
 */
final class UploadPolicy {

  public const TYPES = [
    'pdf' => ['application/pdf'],
    'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
    'gif' => ['image/gif'], 'webp' => ['image/webp'],
    'mp4' => ['video/mp4'], 'mp3' => ['audio/mpeg'], 'wav' => ['audio/x-wav', 'audio/wav'], 'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
  ];

  public static function errors(string $path, string $filename): array {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!isset(self::TYPES[$extension])) {
      return ['This file type is not permitted in the public Media Library.'];
    }
    if (strlen($filename) > 180 || str_starts_with($filename, '.') || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $filename)
      || preg_match('/\.(php[0-9]*|phtml|phar|exe|com|bat|cmd|ps1|sh|js|html?|svg|jar|msi|scr|vbs)[_.]/i', $filename)) {
      return ['Use a safe filename without paths, control characters or executable extensions.'];
    }
    if (preg_match('/(^|[ ._-])(cv|resume|confidential|passport|personal|candidate|bid[ _-]?(submission|technical|financial)|job[ _-]?application)([ ._-]|$)/i', $filename)) {
      return ['Confidential submissions and personal documents must use private storage, not public Media.'];
    }
    if (!is_file($path) || !is_readable($path)) {
      return ['The uploaded file cannot be inspected.'];
    }
    $limit = in_array($extension, ['mp4'], TRUE) ? 100 : (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], TRUE) ? 10 : 25);
    if (filesize($path) <= 0 || filesize($path) > $limit * 1024 * 1024) {
      return ['File size must be greater than zero and no more than ' . $limit . ' MB.'];
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, self::TYPES[$extension], TRUE)) {
      return ['The detected MIME type does not match the permitted file extension.'];
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], TRUE) && !@getimagesize($path)) {
      return ['The uploaded image is invalid.'];
    }
    if (in_array($extension, ['docx', 'xlsx'], TRUE)) {
      $zip = new \ZipArchive();
      if ($zip->open($path) !== TRUE) {
        return ['The Office document is not a valid package.'];
      }
      $marker = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
      $unsafe = $zip->locateName('[Content_Types].xml') === FALSE || $zip->locateName($marker) === FALSE || $zip->numFiles > 5000;
      $expanded = 0;
      for ($i = 0; $i < $zip->numFiles && !$unsafe; $i++) {
        $entry = $zip->statIndex($i);
        $expanded += $entry['size'];
        $unsafe = $expanded > 200 * 1024 * 1024 || preg_match('/(^|\/)\.\.(\/|$)|vbaProject|(^|\/)(embeddings|activeX)\/|\.(exe|dll|com|bat|cmd|ps1|sh|py|pl|rb|jar|msi|scr|vbs|js|bin|html?|php[0-9]*|phtml|phar)$/i', $entry['name']);
      }
      $zip->close();
      if ($unsafe) {
        return ['Macro-enabled, embedded executable or oversized Office packages are not permitted.'];
      }
    }
    // Reject common active-content payloads, including image/PDF polyglots.
    $handle = fopen($path, 'rb');
    $tail = '';
    while (!feof($handle)) {
      $chunk = $tail . fread($handle, 65536);
      if (preg_match('/<\?(php|=)|<script\b|\/JavaScript\b|\/JS\b|\/Launch\b|\/EmbeddedFile\b/i', $chunk)) {
        fclose($handle);
        return ['Executable or active content is not permitted in public uploads.'];
      }
      $tail = substr($chunk, -64);
    }
    fclose($handle);
    return [];
  }

}
