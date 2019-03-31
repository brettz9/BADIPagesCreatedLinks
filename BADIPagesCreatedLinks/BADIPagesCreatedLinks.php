<?php

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

class JobQueuer extends Job {
  /**
   * `Job` constructor only has 2 arguments
   * @param string $id
   * @param string $title
   * @param array $params
   */
  public function __construct ($id, $title, $params) {
		parent::__construct($id, $title, $params);
	}
  /**
   * @param array $jobParams Set any job parameters you want to have available when your job runs
   *    Can also be an empty array.
   *    These values will be available to your job via `$this->params['param_name']`
   *    e.g., `$jobParams = ['limit' => $limit, 'cascade' => true];`
   * @param string $title The article title that the job will use when running
   *    Adds unique ID by default; useful for creating several batch jobs with
   *      the same base title.
   *    The idea is for the db to have a title reference that will be used by your
   *    job to create/update a title or for troubleshooting by having a title
   *    reference that is not vague
   */
  public static function queue ($jobParams, $title = NULL) {
    if ($title === NULL) {
      $title = Title::newFromText(
        'JobQueuer/' . uniqid(),
        NS_SPECIAL
      );
    }

    /**
     * Instantiate a Job object
     */
    $job = new self($title, $jobParams);

    /**
     * Insert the job into the database
     */
    JobQueueGroup::singleton()->push($job);
  }
  /**
   * For performance reasons, if you plan on inserting several jobs
   * into the queue, it’s best to add them to a single array and
   * then push them all at once into the queue
   * @param array $jobSet Has different titles and jobParams
   */
  public static function queueArray ($jobSet) {
    $jobs = [];
    foreach ($jobSet as $jobInfo) {
      $jobs[] = new self($jobInfo->title, $jobInfo->jobParams);
    }
    JobQueueGroup::singleton()->push($jobs);
  }
}

/**
 * For asynchronous requests
 * @see https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers
 */
class CheckBADIPagesCreatedLinks extends JobQueuer {
  public function __construct ($title, $params) {
		parent::__construct('checkBADIPagesCreatedLinks', $title, $params);
	}

  /**
	 * Execute the job
	 *
	 * @return boolean
	 */
	public function run () {
		// Load data from $this->params and $this->title
    $wgBADIConfig = $this->params['wgBADIConfig;'];
    $url = $this->params['url'];
    $rowID = $this->params['row_id'];
    $cache = $this->params['cache'];
    $update = $this->params['update'];
    $currTime = $this->params['curr_time'];

    // Store default options to be able to return back to them
    //  later (in case MediaWiki or other extensions will rely on it)
    $defaultOpts = stream_context_get_options(stream_context_get_default());

    // Temporarily change context for the sake of `get_headers()`
    //  (Wikipedia, though not MediaWiki, disallows HEAD requests
    //  without a user-agent specified)
    stream_context_set_default(
      isset($wgBADIConfig['stream_context']) &&
        count($wgBADIConfig['stream_context'])
        ? $wgBADIConfig['stream_context']
        : [
          'http' => [
            'user_agent' => (
              isset($wgBADIConfig['user-agent'])
                ? $wgBADIConfig['user-agent']
                : wfMessage('user-agent').plain()
            )
          ]
        ]
    );

    $headers = get_headers($url, 1);

    stream_context_set_default($defaultOpts); // Set it back to original value

    // Todo: Distinguish codes to add "erred" `remote_status`
    $oldPageExists = !!($headers['Last-Modified'] ||
      (strpos($headers[0], '200') !== false));
    $createdState = $oldPageExists ? 'existing' : 'missing';

    $dbr = wfGetDB(DB_SLAVE);
    if ($update) {
      $dbr->update(
        $table,
        ['remote_status' => $createdState],
        ['id' => $rowID],
        __METHOD__
      );
    }
    else if ($cache) {
      $dbr->insert($table, [
        'url' => $url,
        'remote_status' => $createdState,
        'last_checked' => $currTime
      ], __METHOD__);
    }

		return true;
	}

  /**
   *
   * @param array $params
   * @param string $type
   * @param string $ns
   */
  public static function queue (
    $params,
    $type = 'CheckLinks',
    $ns = 'BADIPagesCreatedLinks'
  ) {

    $title = Title::newFromText(
      implode(DIRECTORY_SEPARATOR, [$ns, $type, $params->articleTitle]) . uniqid(),
      NS_SPECIAL
    );

    parent::queue($params, $title);
  }
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
   * @param array $wgBADIConfig The extension config object
   * @return string `["existing"|"missing"|"checking"|"erred"]` Created state of the page
   */
  private static function getCreatedStateForSite ($url, $wgBADIConfig) {
    $cache = false;
    $update = false;
    $rowID = null;
    $currTime = null;

    $table = 'ext_badipagescreatedlinks';

    if (!$wgBADIConfig['no_cache']) {
      $cache = true;
      $dbr = wfGetDB(DB_SLAVE);
      $res = $dbr->select(
        $table,
        ['remote_status', 'last_checked'],
        ['url' => $url],
        __METHOD__
      );
      if ($res) {
        $row = $res->fetchRow();
        $rowID = $row->id;
        if ($row->remote_status === 'existing' && $wgBADIConfig['cache_existing'] ||
          $row->remote_status !== 'existing' && $wgBADIConfig['cache_nonexisting']
        ) {
          $timeout = $row->remote_status === 'existing'
            ? $wgBADIConfig['cache_existing_timeout']
            : $wgBADIConfig['cache_nonexisting_timeout'];

          $currTime = time();
          if ($currTime <= ($row->last_checked + $timeout)) {
            return $row->remote_status;
          }
          $update = true;
        }
        else {
          $cache = false;
        }
      }
      // Todo: With a debugging flag, we could update the database to
      //    "checking" `remote_status` for the URL, but don't need the
      //    performance hit.
      CheckBADIPagesCreatedLinks::queue([
        // Not sure if global is available during jobs, so saving a
        //   local copy
        'url' => $url,
        'wgBADIConfig' => $wgBADIConfig,
        'cache' => $cache,
        'update' => $update,
        'row_id' => $rowID,
        'curr_time' => $currTime
      ]);
      return 'pending';
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
    wfLoadExtensionMessages('BADIPagesCreatedLinks');

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

    $link_items = '';
    foreach ($badi_sites as $i => $badi_site) {
      // If the site is explicitly unspecified for the given language
      //   (or default), ignore it
      if ($badi_site == null) {
        continue;
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

      // Might allow defining inline styles for easier
      // though less ideal configuration
      $createdState = self::getCreatedStateForSite(
        $siteWithTitle, $wgBADIConfig
      );
      $created = $createdState === 'existing';
      $uncreated = $createdState === 'missing';
      $pending = $createdState === 'pending';
      // $erred = $createdState === 'erred';

      $class = $created
        ? $wgBADIConfig['createdLinkClass']
        : $uncreated
          ? $wgBADIConfig['uncreatedLinkClass']
          : $pending
            ? $wgBADIConfig['pendingLinkClass']
            : $wgBADIConfig['erredLinkClass'];
      $styles = $created
        ? $wgBADIConfig['createdLinkInlineStyles']
        : $uncreated
          ? $wgBADIConfig['uncreatedLinkInlineStyles']
          : $pending
            ? $wgBADIConfig['pendingLinkInlineStyles']
            : $wgBADIConfig['erredLinkInlineStyles'];

      $siteWithTitle = $uncreated
        ? str_replace_assoc([
            '{{SITE_EDITING}}' => $site_editing,
            '{{CURRENT_PAGE_TITLE}}' => $currentPageTitle
        ], $wgBADIConfig['site_editing_templates'])
        : $siteWithTitle;

      $link_items .= str_replace_assoc([
        '{{STYLES}}' => isset($styles) ? 'style="'.($styles).'"' : '',
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
        $updater->addExtensionTable([
          $table,
          $base . DIRECTORY_SEPARATOR . $table . '.sql'
        ]); // Initially install tables
        break;
      default:
        echo "\nBADIPagesCreatedLinks currently does not " +
            "support your database type\n\n";
        break;
    }
    return true;
  }
}

?>
