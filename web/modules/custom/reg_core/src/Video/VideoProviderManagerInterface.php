<?php

namespace Drupal\reg_core\Video;

/**
 * Resolves editor-selected video providers into safe public playback data.
 */
interface VideoProviderManagerInterface {

  /**
   * Returns normalized playback data, or an empty array for an invalid URL.
   *
   * @return array{
   *   provider: string,
   *   public_url: string,
   *   embed_url: string,
   *   direct_video_url: string,
   *   thumbnail_url: string
   * }
   */
  public function resolve(string $provider, string $url): array;

  /**
   * Returns the controlled providers presented to editors.
   *
   * @return array<string, string>
   */
  public function providerLabels(): array;

}
