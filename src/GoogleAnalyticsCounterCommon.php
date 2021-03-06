<?php

namespace Drupal\google_analytics_counter;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Exception;

/**
 * Class GoogleAnalyticsCounterCommon.
 *
 * @package Drupal\google_analytics_counter
 */
class GoogleAnalyticsCounterCommon {

  use StringTranslationTrait;

  /**
   * The google_analytics_counter.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The state where all the tokens are saved.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The database connection to save the counters.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The language manager to get all languages for to get all aliases.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var
   */
  protected $prefixes;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an Importer object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state of the drupal site.
   * @param \Drupal\Core\Database\Connection $connection
   *   A database connection for reading and writing the path counts.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The path alias manager to find aliased resources.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, Connection $connection, AliasManagerInterface $alias_manager, LanguageManagerInterface $language, LoggerInterface $logger = NULL) {
    $this->config = $config_factory->get('google_analytics_counter.settings');
    $this->state = $state;
    $this->connection = $connection;
    $this->aliasManager = $alias_manager;
    $this->languageManager = $language;
    $this->logger = $logger;

    $this->prefixes = [];
    // The 'url' will return NULL when it is not a multilingual site.
    $language_url = $config_factory->get('language.negotiation')->get('url');
    if ($language_url) {
      $this->prefixes = $language_url['prefixes'];
    }

  }

  /**
   * Check to make sure we are authenticated with google.
   *
   * @return bool
   *   True if there is a refresh token set.
   */
  public function isAuthenticated() {
    return $this->state->get('google_analytics_counter.refresh_token') != NULL;
  }

  /**
   * Instantiate a new GoogleAnalyticsCounterFeed object.
   *
   * @return object
   *   GoogleAnalyticsCounterFeed object to authorize access and request data
   *   from the Google Analytics Core Reporting API.
   */
  public function newGaFeed() {
    $config = $this->config;

    if ($this->state->get('google_analytics_counter.access_token') && time() < $this->state->get('google_analytics_counter.expires_at')) {
      // If the access token is still valid, return an authenticated GAFeed.
      return new GoogleAnalyticsCounterFeed($this->state->get('google_analytics_counter.access_token'));
    }
    elseif ($this->state->get('google_analytics_counter.refresh_token')) {
      // If the site has an access token and refresh token, but the access
      // token has expired, authenticate the user with the refresh token.
      $client_id = $config->get('client_id');
      $client_secret = $config->get('client_secret');
      $refresh_token = $this->state->get('google_analytics_counter.refresh_token');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->refreshToken($client_id, $client_secret, $refresh_token);
        $this->state->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
        ]);
        return $gac_feed;
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }
    elseif (isset($_GET['code'])) {
      // If there is no access token or refresh token and client is returned
      // to the config page with an access code, complete the authentication.
      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->finishAuthentication($config->get('client_id'), $config->get('client_secret'), $this->getRedirectUri());

        $this->state->setMultiple([
          'google_analytics_counter.access_token' => $gac_feed->accessToken,
          'google_analytics_counter.expires_at' => $gac_feed->expiresAt,
          'google_analytics_counter.refresh_token' => $gac_feed->refreshToken,
        ]);
        $this->state->delete('google_analytics_counter.redirect_uri');
        drupal_set_message(t('You have been successfully authenticated.'), 'status', FALSE);
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }

    return NULL;

  }

  /**
   * Get the redirect uri to redirect the google oauth request back to.
   *
   * @return string
   *   The redirect Uri from the configuration or the path.
   */
  public function getRedirectUri() {

    if ($this->config->get('redirect_uri')) {
      return $this->config->get('redirect_uri');
    }

    $https = FALSE;
    if (!empty($_SERVER['HTTPS'])) {
      $https = $_SERVER['HTTPS'] == 'on';
    }
    $url = $https ? 'https://' : 'http://';
    $url .= $_SERVER['SERVER_NAME'];
    if ((!$https && $_SERVER['SERVER_PORT'] != '80') || ($https && $_SERVER['SERVER_PORT'] != '443')) {
      $url .= ':' . $_SERVER['SERVER_PORT'];
    }

    return $url . Url::fromRoute('google_analytics_counter.admin_auth_form')->toString();
  }

  /**
   * Get the list of available web properties.
   *
   * @return array
   */
  public function getWebPropertiesOptions() {
    if (!$this->isAuthenticated()) {
      // When not authenticated, there is noting to get.
      return [];
    }

    $feed = $this->newGaFeed();

    $webprops = $feed->queryWebProperties()->results->items;
    $profiles = $feed->queryProfiles()->results->items;
    $options = [];

    // Add optgroups for each web property.
    if (!empty($profiles)) {
      foreach ($profiles as $profile) {
        $webprop = NULL;
        foreach ($webprops as $webprop_value) {
          if ($webprop_value->id == $profile->webPropertyId) {
            $webprop = $webprop_value;
            break;
          }
        }

        $options[$webprop->name][$profile->id] = $profile->name . ' (' . $profile->id . ')';
      }
    }

    return $options;
  }


  /**
   * Sets the expiry timestamp for cached queries.Default is 1 day.
   *
   * @return int
   *   The UNIX timestamp to expire the query at.
   */
  public static function cacheTime() {
    return time() + \Drupal::config('google_analytics_counter.settings')
      ->get('cache_length');
  }

  /**
   * Convert seconds to hours, minutes and seconds.
   */
  public static function sec2hms($sec, $pad_hours = FALSE) {

    // Start with a blank string.
    $hms = "";

    // Do the hours first: there are 3600 seconds in an hour, so if we divide
    // the total number of seconds by 3600 and throw away the remainder, we're
    // left with the number of hours in those seconds.
    $hours = intval(intval($sec) / 3600);

    // Add hours to $hms (with a leading 0 if asked for).
    $hms .= ($pad_hours)
      ? str_pad($hours, 2, "0", STR_PAD_LEFT) . "h "
      : $hours . "h ";

    // Dividing the total seconds by 60 will give us the number of minutes
    // in total, but we're interested in *minutes past the hour* and to get
    // this, we have to divide by 60 again and then use the remainder.
    $minutes = intval(($sec / 60) % 60);

    // Add minutes to $hms (with a leading 0 if needed).
    $hms .= str_pad($minutes, 2, "0", STR_PAD_LEFT) . "m ";

    // Seconds past the minute are found by dividing the total number of seconds
    // by 60 and using the remainder.
    $seconds = intval($sec % 60);

    // Add seconds to $hms (with a leading 0 if needed).
    $hms .= str_pad($seconds, 2, "0", STR_PAD_LEFT);

    // done!
    return $hms . 's';
  }

  public function beginAuthentication() {
    $gafeed = new GoogleAnalyticsCounterFeed();
    $gafeed->beginAuthentication($this->config->get('client_id'), $this->getRedirectUri());
  }

  /**
   * Programatically revoke token.
   */
  public function revoke() {
    $gac_feed = $this->newGaFeed();
    $gac_feed->revokeToken();
    \Drupal::state()->setMultiple([
      'google_analytics_counter.access_token' => '',
      'google_analytics_counter.expires_at' => '',
      'google_analytics_counter.refresh_token' => '',
    ]);
  }

  /**
   * Save the view cound for a given node.
   *
   * @param integer $nid
   *   The node id for the node of which to save the data.
   */
  public function updateStorage($nid) {

    // Get all the aliases for a given node id.
    $aliases = [];
    $path = '/node/' . $nid;
    $aliases[] = $path;
    foreach ($this->languageManager->getLanguages() as $language) {
      $alias = $this->aliasManager->getAliasByPath($path, $language->getId());
      $aliases[] = $alias;
      if (array_key_exists($language->getId(), $this->prefixes) && $this->prefixes[$language->getId()]) {
        $aliases[] = '/' . $this->prefixes[$language->getId()] . $path;
        $aliases[] = '/' . $this->prefixes[$language->getId()] . $alias;
      }
    }

    // Add also all versions with a trailing slash.
    $aliases = array_merge($aliases, array_map(function ($path) {
      return $path . '/';
    }, $aliases));

    // Look up the count via the hash of the path.
    $aliases = array_unique($aliases);
    $hashes = array_map('md5', $aliases);
    $pathcounts = $this->connection->select('google_analytics_counter', 'gac')
      ->fields('gac', array('pageviews'))
      ->condition('pagepath_hash', $hashes, 'IN')
      ->execute();
    $sum_of_pageviews = 0;
    foreach ($pathcounts as $pathcount) {
      $sum_of_pageviews += $pathcount->pageviews;
    }

    // Always save the data in our table.
    $this->connection->merge('google_analytics_counter_storage')
      ->key(array('nid' => $nid))
      ->fields(array(
        'pageview_total' => $sum_of_pageviews,
      ))
      ->execute();

    // If we selected to override the storage of the statistics module.
    if ($this->config->get('overwrite_statistics')) {
      $this->connection->merge('node_counter')
        ->key(array('nid' => $nid))
        ->fields(array(
          'totalcount' => $sum_of_pageviews,
          'timestamp' => REQUEST_TIME,
        ))
        ->execute();
    }

  }

  /**
   * Get the results from google.
   *
   * @param int $index
   *   The index of the chunk to fetch so that it can be queued.
   * @return \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed
   *   The returned feed after the request has been made.
   */
  public function getChunkedResults($index = 0) {
    $parameters = [
      'profile_id' => 'ga:' . $this->config->get('profile_id'),
      'dimensions' => ['ga:pagePath'],
      // Date would not be necessary for totals, but we also calculate stats of
      // views per day, so we need it.
      'metrics' => ['ga:pageviews'],
      'start_date' => strtotime($this->config->get('start_date')),
      // Using 'tomorrow' to offset any timezone shift
      // between the hosting and Google servers.
      'end_date' => strtotime('tomorrow'),
      'start_index' => ($this->config->get('chunk_to_fetch') * $index) + 1,
      'max_results' => $this->config->get('chunk_to_fetch'),
    ];

    $cachehere = [
      'cid' => 'google_analytics_counter_' . md5(serialize($parameters)),
      'expire' => self::cacheTime(),
      'refresh' => FALSE,
    ];
    return $this->reportData($parameters, $cachehere);
  }

  /**
   * Update the path counts.
   *
   * This function is triggered by hook_cron().
   *
   * @param integer $index
   *   The index of the chunk to fetch and update.
   */
  public function updatePathCounts($index = 0) {
    $feed = $this->getChunkedResults($index);

    foreach ($feed->results->rows as $val) {
      // http://drupal.org/node/310085
      $this->connection->merge('google_analytics_counter')
        ->key(array('pagepath_hash' => md5($val['pagePath'])))
        ->fields(array(
          // Escape the path see https://www.drupal.org/node/2381703
          'pagepath' => htmlspecialchars($val['pagePath'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
          'pageviews' => htmlspecialchars($val['pageviews'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ))
        ->execute();
    }

    // Log the results.
    $this->log($this->t('Saved @count paths from Google Analytics into the database.', ['@count' => count($feed->results->rows)]));
  }

  /**
   * Get the count for a path in a span tag.
   *
   * @param string $path
   *   The path to look up
   * @return string
   *   The count wrapped in a span.
   */
  public function displayGaCount($path) {

    // Make sure the path starts with a slash
    $path = '/'. trim($path, ' /');
    // look up both with and without trailing slash
    $aliases = [
      $path,
      $path . '/'
    ];

    $hashes = array_map('md5', $aliases);
    $pathcounts = $this->connection->select('google_analytics_counter', 'gac')
      ->fields('gac', array('pageviews'))
      ->condition('pagepath_hash', $hashes, 'IN')
      ->execute();
    $sum_of_pageviews = 0;
    foreach ($pathcounts as $pathcount) {
      $sum_of_pageviews += $pathcount->pageviews;
    }

    // TODO: use this with a twig template.
    return '<span class="google-analytics-counter">' . number_format($sum_of_pageviews) . '</span>';
  }

  /**
   * Request report data.
   *
   * @param array $params
   *   An associative array containing:
   *   - profile_id: required [default=config('profile_id')]
   *   - metrics: required.
   *   - dimensions: optional [default=none]
   *   - sort_metric: optional [default=none]
   *   - filters: optional [default=none]
   *   - segment: optional [default=none]
   *   - start_date: optional [default=GA release date]
   *   - end_date: optional [default=today]
   *   - start_index: optional [default=1]
   *   - max_results: optional [default=10,000].
   * @param array $cache_options
   *   An optional associative array containing:
   *   - cid: optional [default=md5 hash]
   *   - expire: optional [default=CACHE_TEMPORARY]
   *   - refresh: optional [default=FALSE].
   *
   * @return \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed
   *   A new GoogleAnalyticsCounterFeed object
   */
  protected function reportData($params = array(), $cache_options = array()) {
    // Add defaults.
    $params += [
      'profile_id' => 'ga:' . $this->config->get('profile_id'),
      'start_index' => 1,
      'max_results' => $this->config->get('chunk_to_fetch'),
    ];

    /* @var \Drupal\google_analytics_counter\GoogleAnalyticsCounterFeed $ga_feed */
    $ga_feed = $this->newGaFeed();
    if (!$ga_feed) {
      throw new \RuntimeException($this->t('The GoogleAnalyticsCounterFeed could not be initialised, is it authenticated?'));
    }

    // Here would be a good point to catch how many requests were made to google
    // to stay below the api limit or alter the parameters in an alter hook etc.
    $ga_feed->queryReportFeed($params, $cache_options);

    // Handle errors here too.
    if (!empty($ga_feed->error)) {
      throw new \RuntimeException($ga_feed->error);
    }

    return $ga_feed;
  }

  /**
   * Log a message if the logger is set.
   *
   * @param string $message
   *   The message to log.
   * @param string $level
   *   The log level, 'info' by default.
   */
  protected function log($message, $level = LogLevel::INFO) {
    if ($this->logger) {
      $this->logger->log($level, $message);
    }
  }
}
