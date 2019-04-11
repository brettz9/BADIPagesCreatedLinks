<?php

require('CheckBADIPagesCreatedLinks.php');

// https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
// https://www.mediawiki.org/wiki/Manual:Database_access
// https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers

/*
// Enable to get system messages during testing
$wgMainCacheType = CACHE_NONE;
$wgCacheDirectory = false;
*/

/**
 *
 * @param array $replace
 * @param array $subject
 * @return string
 */
function str_replace_assoc (array $replace, $subject) {
   return str_replace(array_keys($replace), array_values($replace), $subject);
}

function phpTimeToSQLTimestamp ($time) {
  return date('Y-m-d H:i:s', $time); // substr($time, 0, -3));
}

class BADIPagesCreatedLinks {
  /**
   * Utility to determine whether a page is created already (false if not);
   * relies on built-in PHP function, `get_headers()`, which makes a quick
   * HEAD request and which we use to obtain its `Last-Modified` header; if
   * it exists, it has been created already, and if not, it has not yet been
   * created.
   * @private
   * @param string $url The URL of the site to detect
   * @param string $currentPageTitle The page title
   * @param array $wgBADIConfig The extension config object
   * @return string `["existing"|"missing"|"checking"|"erred"]` Created state of the page
   */
  public static function getCreatedStateForSite ($url, $currentPageTitle, $wgBADIConfig) {
    $insertCache = false;
    $updateCache = false;
    $rowID = null;
    $row = null;
    $currTime = time();

    $table = 'ext_badipagescreatedlinks';

    if (!$wgBADIConfig['no_cache']) {
      // $insertCache = true; // We are instead handling `insert` below for now
      $dbr = wfGetDB(DB_SLAVE);
      $res = $dbr->select(
        $table,
        [
          'remote_status', 'last_checked', 'id'
        ],
        ['url' => $url],
        __METHOD__
      );
      if ($res) {
        $row = $res->fetchRow();
      }
      if ($row) {
        $updateCache = true;
        $rowID = $row['id'];
        if (($row['remote_status'] === 'existing' && $wgBADIConfig['cache_existing']) ||
          ($row['remote_status'] === 'missing' && $wgBADIConfig['cache_nonexisting'])
        ) {
          $timeout = $row['remote_status'] === 'existing'
            ? $wgBADIConfig['cache_existing_timeout'] // Default: About a year
            : $wgBADIConfig['cache_nonexisting_timeout']; // Default: About a month

          if ($currTime <= (strtotime($row['last_checked']) + $timeout)) {
            return $row['remote_status'];
          }
        }
      } else {
        // Though we could avoid this performance hit, besides being
        //    useful for debugging, this "checking" `remote_status`
        //    for the URL also helps prevent multiple and potentially
        //    redundant checking SQL jobs (if the same URL were visited
        //    before a job executed to insert a record).

        // This next line should be commented out if letting job do inserts
        $updateCache = true;
        $dbr->insert($table, [
          'url' => $url,
          'remote_status' => 'checking',
          'last_checked' => phpTimeToSQLTimestamp($currTime)
        ], __METHOD__);
        $rowID = $dbr->insertId();
      }

      CheckBADIPagesCreatedLinks::queue([
        // Not sure if global is available during jobs, so saving a
        //   local copy
        'table' => $table,
        'url' => $url,
        'articleTitle' => $currentPageTitle,
        'badiConfig' => $wgBADIConfig,
        'insertCache' => $insertCache,
        'updateCache' => $updateCache,
        'row_id' => $rowID,
        'curr_time' => phpTimeToSQLTimestamp($currTime)
      ]);
      return 'checking';
    }
    // Todo: Call `run()` instead here
    return 'existing';
  }
  /**
   * Hook (`BaseTemplateToolbox`) for starting function after table
   * creation.
   * Adds links to the Toolbox according to a user-configurable and
   * localizable list of links and titles, and styles links differently
   * depending on whether the link has been created at the target site
   * yet or not
   * @param BaseTemplate $baseTemplate
   * @param array $toolbox Passed by reference
   * @return boolean Whether any links were added
   */
  public static function addPageCreatedLinks (BaseTemplate $baseTemplate, array &$toolbox) {
    // GET LOCALE MESSAGES
    global $wgLanguageCode, // Ok as not deprecated
      $wgBADIConfig; // Ok as still recommended way

    $currentPageTitleObj = $baseTemplate->getSkin()->getTitle();
    // The namespace-prefixed, underscored title of the current article
    $currentPageTitle = $currentPageTitleObj->getPrefixedDBKey();
    $titleNamespace = $currentPageTitleObj->getNamespace();

    if (isset($wgBADIConfig['no_namespaces']) &&
      $wgBADIConfig['no_namespaces'] &&
      $titleNamespace !== NS_MAIN
    ) {
      return false;
    }
    if (isset($wgBADIConfig['namespace_whitelist']) &&
      !in_array($titleNamespace, $wgBADIConfig['namespace_whitelist'])
    ) {
      return false;
    }
    if (isset($wgBADIConfig['namespace_blacklist']) &&
      in_array($titleNamespace, $wgBADIConfig['namespace_blacklist'])
    ) {
      return false;
    }

    $badi_sites = isset($wgBADIConfig['sites'][$wgLanguageCode])
      ? $wgBADIConfig['sites'][$wgLanguageCode]
      : (isset($wgBADIConfig['sites']['default'])
        ? // Allow user to set own default
          $wgBADIConfig['sites']['default'] :
          // Finally, if none specified at all, use our default
          [wfMessage('site')->escaped()]);

    $badi_sites_editing = isset($wgBADIConfig['sites_editing'][$wgLanguageCode])
      ? $wgBADIConfig['sites_editing'][$wgLanguageCode]
      : (isset($wgBADIConfig['sites_editing']['default'])
        // Allow user to set own default
        ? $wgBADIConfig['sites_editing']['default']
        // Finally, if none specified at all, use our default
        : [wfMessage('site-editing')->escaped()]);

    $badi_titles = isset($wgBADIConfig['titles'][$wgLanguageCode])
      ? $wgBADIConfig['titles'][$wgLanguageCode]
      : (isset($wgBADIConfig['titles']['default'])
        // Allow user to set own default
        ? $wgBADIConfig['titles']['default']
        // Finally, if none specified at all, use our default
        : [wfMessage('title')->escaped()]);

    $exclusions = [];
    if ($wgBADIConfig['exclusions_path'] !== '') {
      $exclusions = json_decode(file_get_contents($wgBADIConfig['exclusions_path']), true);
    }

    $link_items = '';
    foreach ($badi_sites as $i => $badi_site) {
      // If the site is explicitly unspecified for the given language
      //   (or default), ignore it
      if ($badi_site == null) {
        continue;
      }

      $exclusionValue = null;
      if (array_key_exists($badi_site, $exclusions) &&
        array_key_exists($currentPageTitle, $exclusions[$badi_site])) {
        $exclusionValue = $exclusions[$badi_site][$currentPageTitle];
        if ($exclusionValue === false) {
          continue;
        }
      }

      // Let user be able to dynamically determine URL (in this
      //  case one can define an array exclusively as 'default'
      //  which is our fallback)
      $site = str_replace('{{LANGUAGE}}', $wgLanguageCode, $badi_site);
      $site_editing = str_replace(
        '{{LANGUAGE}}',
        $wgLanguageCode,
        $badi_sites_editing[$i]
      );

      $siteTitle = $badi_titles[$i];

      $siteWithTitle = str_replace_assoc([
        '{{CURRENT_PAGE_TITLE}}' => $currentPageTitle,
        '{{SITE}}' => $site
      ], $wgBADIConfig['site_and_title_templates']);

      if (isset($exclusionValue) && in_array($exclusionValue, [
        'existing', 'missing', 'checking', 'erred'
      ])) {
        $createdState = $exclusionValue;
      } else {
        // Might allow defining inline styles for easier
        // though less ideal configuration
        $createdState = self::getCreatedStateForSite(
          $siteWithTitle, $currentPageTitle, $wgBADIConfig
        );
      }
      $created = $createdState === 'existing';
      $uncreated = $createdState === 'missing';
      $checking = $createdState === 'checking';
      // $erred = $createdState === 'erred';

      $class = $created
        ? $wgBADIConfig['createdLinkClass']
        : ($uncreated
          ? $wgBADIConfig['uncreatedLinkClass']
          : ($checking
            ? $wgBADIConfig['checkingLinkClass']
            : $wgBADIConfig['erredLinkClass']));
      $styles = $created
        ? $wgBADIConfig['createdLinkInlineStyles']
        : ($uncreated
          ? $wgBADIConfig['uncreatedLinkInlineStyles']
          : ($checking
            ? $wgBADIConfig['checkingLinkInlineStyles']
            : $wgBADIConfig['erredLinkInlineStyles']));

      $siteWithTitle = $uncreated
        ? str_replace_assoc([
            '{{SITE_EDITING}}' => $site_editing,
            '{{CURRENT_PAGE_TITLE}}' => $currentPageTitle
        ], $wgBADIConfig['site_editing_templates'])
        : $siteWithTitle;

      $link_items .= str_replace_assoc([
        '{{STYLES}}' => isset($styles) && $styles ? 'style="'.($styles).'"' : '',
        '{{CLASS}}' => $class,
        '{{LOCALIZED_LINK}}' => $siteWithTitle,
        '{{LOCALIZED_TITLE}}' => $siteTitle
      ], $wgBADIConfig['external_site_templates']);
    }

    if ($link_items === '') {
      return false;
    }

    // Todo: Any other way to write than directly using `echo`?
    //    Other extensions appear to set a property on `$toolbox`;
    //    for raw HTML, we may need to do this way
    echo str_replace_assoc([
      '{{LOCALIZED_INTRO}}' => isset(
          $wgBADIConfig['external_intro'][$wgLanguageCode]
        )
          ? $wgBADIConfig['external_intro'][$wgLanguageCode]
          : (isset($wgBADIConfig['external_intro']['default']) ?
              $wgBADIConfig['external_intro']['default'] :
              wfMessage('external-pages-w-same-title')->plain()),
      '{{LINK_ITEMS}}' => $link_items
    ], $wgBADIConfig['external_sites_templates']);

    return true;
  }
  /**
   * Hook (`LoadExtensionSchemaUpdates`) for SQL installation/updating
   * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
   * @param object $updater
   * @return boolean
   */
  public static function onLoadExtensionSchemaUpdates ($updater = null) {
    $table = 'ext_badipagescreatedlinks';
    $base = __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR;

    switch ($updater->getDB()->getType()) {
      case 'mysql':
        $updater->addExtensionTable(
          $table,
          $base . DIRECTORY_SEPARATOR . $table . '.sql'
        ); // Initially install tables
        break;
      default:
        echo "\nBADIPagesCreatedLinks currently does not " +
            "support your database type\n\n";
        break;
    }
    return true;
  }
}
