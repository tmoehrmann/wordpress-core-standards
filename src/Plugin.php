<?php

/**
 * @file
 * Contains \Netzstrategen\CoreStandards\Plugin.
 */

namespace Netzstrategen\CoreStandards;

/**
 * Main front-end functionality.
 */
class Plugin {

  /**
   * Prefix for naming.
   *
   * @var string
   */
  const PREFIX = 'core-standards';

  /**
   * Gettext localization domain.
   *
   * @var string
   */
  const L10N = self::PREFIX;

  /**
   * @var string
   */
  private static $baseUrl;

  /**
   * @implements init
   */
  public static function init() {
    // Add Facebook to oEmbed providers.
    // @see https://developers.facebook.com/docs/plugins/oembed-endpoints
    // @see https://developer.mozilla.org/en-US/Firefox/Privacy/Tracking_Protection
    add_filter('oembed_providers', __CLASS__ . '::oembed_providers');

    // Ensure that Facebook embeds do not exceed available content width.
    add_filter('oembed_fetch_url', __CLASS__ . '::oembed_fetch_url', 10, 3);

    // Enforce youtube-nocookie and add wrapper to oEmbed elements.
    add_filter('embed_oembed_html', __CLASS__ . '::embed_oembed_html', 10, 4);

    // Allow SVG files in media library.
    add_filter('upload_mimes', __CLASS__ . '::upload_mime_types');

    // Prevent full-width images from getting wrapped into paragraphs.
    add_filter('the_content', __CLASS__ . '::the_content_before', 9);

    // Remove size attributes from SVG images, so they scale correctly without a CSS size reset.
    add_filter('the_content', __CLASS__ . '::the_content');
    add_filter('wp_get_attachment_metadata', __CLASS__ . '::removeSvgSizeAttributes', 10, 2);
    add_filter('post_thumbnail_html', __CLASS__ . '::removeSvgSizeAttributes', 10, 3);
    // Limit to two arguments since the third parameter is the image caption.
    add_filter('image_send_to_editor', __CLASS__ . '::removeSvgSizeAttributes', 10, 2);

    // Set proper From header for all emails.
    // Remove "[$blogname]" prefix in email subjects of user account mails.
    add_filter('wp_mail', __NAMESPACE__ . '\Mail::wp_mail');

    add_shortcode('user-login-form', __NAMESPACE__ . '\UserLoginForm::getOutput');

    // Removes X-Pingback HTTP header, disables insertion of rel="pingback" tags,
    // and disables trackbacks and pingbacks for existing and new posts.
    add_filter('xmlrpc_methods', __CLASS__ . '::xmlrpc_methods');
    add_filter('pings_open', '__return_false', 100);
    add_filter('pre_option_default_ping_status', '__return_zero');
    add_filter('pre_option_default_pingback_flag', '__return_zero');
    add_filter('wp_insert_post_data' , __CLASS__ . '::wp_insert_post_data', 100);

    if (is_admin()) {
      return;
    }
    // Add teaser image to RSS feeds.
    add_action('rss2_item', __NAMESPACE__ . '\Feed::rss2_item');

    UserFrontend::init();
    TrackingOptOut::init();
  }

  /**
   * @implements oembed_providers
   */
  public static function oembed_providers(array $providers) {
    $providers['#https?://(www\.)?facebook\.com/(.+/)?video.+#i'] = ['https://www.facebook.com/plugins/video/oembed.{format}', TRUE];
    $providers['#https?://(www\.)?facebook\.com/.+#i'] = ['https://www.facebook.com/plugins/post/oembed.{format}', TRUE];
    return $providers;
  }

  /**
   * Convert YouTube embeds and wrap oEmbed output for styling purposes.
   */
  public static function embed_oembed_html($html, $url, $attr, $post_id) {
    // Enforce no-cookie domain for both youtube.com and youtu.be embeds.
    if (strpos($url, 'youtu')) {
      $html = preg_replace('@youtube\.com|youtu\.be@', 'youtube-nocookie.com', $html);
    }
    return '<div class="oembed">' . $html . '</div>';
  }

  /**
   * @implements upload_mimes
   */
  public static function upload_mime_types(array $mimes) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
  }

  /**
   * @implements the_content
   */
  public static function the_content_before($html) {
    $html = preg_replace('@^(?:<a [^>]+>)?<img [^>]+>(?:</a>)?\s*$@m', '<figure>$0</figure>', $html);
    return $html;
  }

  /**
   * Removes SVG image size attributes from the content.
   *
   * @implements the_content
   */
  public static function the_content($content) {
    $content = preg_replace_callback('@<img [^>]*src=[^>]+?.svg[^>]*>@', function ($matches) {
      return static::removeSizeAttributes($matches[0]);
    }, $content);
    return $content;
  }

  /**
   * Removes size attributes from SVG images.
   *
   * @implements wp_get_attachment_metadata
   * @implements image_send_to_editor
   * @implements post_thumbnail_html
   */
  public static function removeSvgSizeAttributes($data, $post_id, $attachment_id = NULL) {
    if (!$data || get_post_mime_type($attachment_id ?? $post_id) !== 'image/svg+xml') {
      return $data;
    }
    // For wp_get_attachment_metadata, $data is an array.
    if (is_array($data)) {
      unset($data['width'], $data['height']);
    }
    else {
      $data = static::removeSizeAttributes($data);
    }
    return $data;
  }

  /**
   * Removes size attributes from the given HTML string.
   */
  public static function removeSizeAttributes($html) {
    return preg_replace('@(?:width|height)="1"\s*@', '', $html);
  }

  /**
   * The base URL path to this plugin's folder.
   *
   * Uses plugins_url() instead of plugin_dir_url() to avoid a trailing slash.
   */
  public static function getBaseUrl() {
    if (!isset(static::$baseUrl)) {
      static::$baseUrl = plugins_url('', static::getBasePath() . '/custom.php');
    }
    return static::$baseUrl;
  }

  /**
   * The absolute filesystem base path of this plugin.
   *
   * @return string
   */
  public static function getBasePath() {
    return dirname(__DIR__);
  }

  /**
   * Fix Facebook embeds to keep within wrapper borders.
   *
   * @implements oembed_fetch_url
   */
  public static function oembed_fetch_url($provider, $url, $args) {
    if (strpos($url, 'facebook.com')) {
      $provider = add_query_arg('maxwidth', '100%', $provider);
    }
    return $provider;
  }

  /**
   * @implements xmlrpc_methods
   */
  public static function xmlrpc_methods($methods) {
    unset($methods['pingback.ping']);
    unset($methods['pingback.extensions.getPingbacks']);
    return $methods;
  }

  /**
   * @implements wp_insert_post_data
   */
  public static function wp_insert_post_data($data) {
    $data['ping_status'] = 'closed';
    return $data;
  }

  /**
   * Loads the plugin textdomain.
   */
  public static function loadTextdomain() {
    load_plugin_textdomain(static::L10N, FALSE, static::L10N . '/languages/');
  }

  /**
   * Renders a given plugin template, optionally overridden by the theme.
   *
   * WordPress offers no built-in function to allow plugins to render templates
   * with custom variables, respecting possibly existing theme template overrides.
   * Inspired by Drupal (5-7).
   *
   * @param array $template_subpathnames
   *   An prioritized list of template (sub)pathnames within the plugin/theme to
   *   discover; the first existing wins.
   * @param array $variables
   *   An associative array of template variables to provide to the template.
   *
   * @throws \InvalidArgumentException
   *   If none of the $template_subpathnames files exist in the plugin itself.
   */
  public static function renderTemplate(array $template_subpathnames, array $variables = []) {
    $template_pathname = locate_template($template_subpathnames, FALSE, FALSE);
    extract($variables, EXTR_SKIP | EXTR_REFS);
    if ($template_pathname !== '') {
      include $template_pathname;
    }
    else {
      while ($template_pathname = current($template_subpathnames)) {
        if (file_exists($template_pathname = static::getBasePath() . '/' . $template_pathname)) {
          include $template_pathname;
          return;
        }
        next($template_subpathnames);
      }
      throw new \InvalidArgumentException("Missing template '$template_pathname'");
    }
  }

}
