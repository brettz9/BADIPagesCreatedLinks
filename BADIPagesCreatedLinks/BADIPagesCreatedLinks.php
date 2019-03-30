<?php

// https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
// https://www.mediawiki.org/wiki/Manual:Database_access
// https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers

/*
// Enable to get system messages during testing
$wgMainCacheType = CACHE_NONE;
$wgCacheDirectory = false;
*/

class JobQueuer extends Job {
  /**
   * `Job` constructor only has 2 arguments
   * @param $id
   * @param $title
   * @param $params
   */
  public function __construct($id, $title, $params) {
		parent::__construct($id, $title, $params);
	}
  /**
   * @param jobParams Set any job parameters you want to have available when your job runs
   *    Can also be an empty array.
   *    These values will be available to your job via `$this->params['param_name']`
   *    e.g., `$jobParams = [ 'limit' => $limit, 'cascade' => true ];`
   * @param title The article title that the job will use when running
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
   * @param {Array} jobSet Has different titles and jobParams
   */
  public static function queueArray ($jobSet) {
    $jobs = [];
    foreach ($jobInfo as $jobSet) {
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
  public function __construct($title, $params) {
		parent::__construct('checkBADIPagesCreatedLinks', $title, $params);
	}
  /**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		// Load data from $this->params and $this->title
    $articleTitle = $this->params['articleTitle'];
		$article = new Article($articleTitle, 0);

		if ($article) {
			// checkLinks($article, $articleTitle);
		}

		return true;
	}
  public static function queue (
    $articleTitle,
    $type = 'CheckLinks',
    $ns = 'BADIPagesCreatedLinks'
  ) {

    $title = Title::newFromText(
      implode(DIRECTORY_SEPARATOR, [$ns, $type, $articleTitle]) . uniqid(),
      NS_SPECIAL
    );

    parent::queue(['articleTitle' => $articleTitle], $title);
  }
}

class BADIPagesCreatedLinks {
  /*
   * Utility to determine whether a page is created already (false if not);
   * relies on built-in PHP function, `get_headers()`, which makes a quick
   * HEAD request and which we use to obtain its `Last-Modified` header; if
   * it exists, it has been created already, and if not, it has not yet been
   * created.
   * @private
   * @param {String} url The URL of the site to detect
   * @returns {Boolean} Whether or not the page has been created
   */
  private static function getCreatedStateForSite ($url) {
    global $wgBADIConfig;

    $cache = false;
    $update = false;
    $row = null;
    $dbr = null;
    $curr_time = null;
    $table = 'ext_badipagescreatedlinks';

    if (!$wgBADIConfig['no_cache']) {
      $cache = true;
      $dbr = wfGetDB(DB_SLAVE);
      $res = $dbr->select(
        $table,
        ['remote_exists', 'checked_timestamp'],
        ['url' => $url],
        __METHOD__
      );
      if ($res) {
        $row = $res->fetchRow();
        if ($row->remote_exists && $wgBADIConfig['cache_existing'] ||
          !$row->remote_exists && $wgBADIConfig['cache_nonexisting']
        ) {
          $timeout = $row->remote_exists
            ? $wgBADIConfig['cache_existing_timeout']
            : $wgBADIConfig['cache_nonexisting_timeout'];

          $curr_time = time();
          if ($curr_time <= ($row->checked_timestamp + $timeout)) {
            return !!$row->remote_exists;
          }
          $update = true;
        }
        else {
          $cache = false;
        }
      }
    }

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

    // $title = $article->getTitle();
    // CheckBADIPagesCreatedLinks::queue($title);
    $headers = get_headers($url, 1);

    stream_context_set_default($defaultOpts); // Set it back to original value

    $oldPageExists = !!($headers['Last-Modified'] ||
      (strpos($headers[0], '200') !== false));
    if ($update) {
      $dbr->update(
        $table,
        ['remote_exists' => $oldPageExists],
        ['id' => $row->id],
        __METHOD__
      );
    }
    else if ($cache) {
      $dbr->insert($table, [
        'url' => $url,
        'remote_exists' => $oldPageExists,
        'checked_timestamp' => $curr_time
      ], __METHOD__);
    }
    return $oldPageExists;
  }
  /*
   * Our starting hook function after table creation; adds links to the
   * Toolbox according to a user-configurable and localizable list of
   * links and titles, and styles links differently depending on whether
   * the link has been created at the target site yet or not
   * @param {Object} $this Passed by Mediawiki (required)
   * @returns {Boolean} Whether any links were added
   */
   public static function addPageCreatedLinks ($out) {
    // GET LOCALE MESSAGES
    wfLoadExtensionMessages('BADIPagesCreatedLinks');

    global $wgRequest, $wgLanguageCode, $wgBADIConfig;

    $currentPageTitle = $wgRequest->getText('title');

    if (isset($wgBADIConfig['no_namespaces']) &&
      $wgBADIConfig['no_namespaces'] &&
      strpos($currentPageTitle, ':') !== false
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

    for ($i = 0, $link_items = '', $len = count($badi_sites); $i < $len; $i++) {
      // If the site is explicitly unspecified for the given language
      //   (or default), ignore it
      if ($badi_sites[$i] == null) {
        continue;
      }

      // Let user be able to dynamically determine URL (in this
      //  case one can define an array exclusively as 'default'
      //  which is our fallback)
      $site = str_replace('{{LANGUAGE}}', $wgLanguageCode, $badi_sites[$i]);
      $site_editing = str_replace(
        '{{LANGUAGE}}',
        $wgLanguageCode,
        $badi_sites_editing[$i]
      );

      $siteTitle = $badi_titles[$i];
      $siteWithTitle = str_replace(
        '{{SITE}}',
        $site,
        str_replace(
          '{{CURRENT_PAGE_TITLE}}',
          $currentPageTitle,
          $wgBADIConfig['site_and_title_templates']
        )
      );
      // Might allow defining inline styles for easier
      // though less ideal configuration
      $created = self::getCreatedStateForSite($siteWithTitle);

      $class = $created
        ? $wgBADIConfig['createdLinkClass']
        : $wgBADIConfig['uncreatedLinkClass'];
      $styles = $created
        ? $wgBADIConfig['createdLinkInlineStyles']
        : $wgBADIConfig['uncreatedLinkInlineStyles'];

      $siteWithTitle = $created
        ? $siteWithTitle
        : str_replace(
          '{{CURRENT_PAGE_TITLE}}',
          $currentPageTitle,
          str_replace(
            '{{SITE_EDITING}}',
            $site_editing,
            $wgBADIConfig['site_editing_templates']
          )
        );

      $link_items .= str_replace(
        '{{LOCALIZED_TITLE}}',
        $siteTitle,
        str_replace(
          '{{LOCALIZED_LINK}}',
          $siteWithTitle,
          str_replace(
            '{{CLASS}}',
            $class,
            str_replace(
              '{{STYLES}}',
              isset($styles) ? 'style="'.($styles).'"' : '',
              $wgBADIConfig['external_site_templates']
            )
          )
        )
      );
    }
    if ($link_items === '') {
      return false;
    }
    echo str_replace(
      '{{LINK_ITEMS}}',
      $link_items,
      str_replace(
        '{{LOCALIZED_INTRO}}',
        isset($wgBADIConfig['external_intro'][$wgLanguageCode]) ?
          $wgBADIConfig['external_intro'][$wgLanguageCode] :
          (isset($wgBADIConfig['external_intro']['default']) ?
            $wgBADIConfig['external_intro']['default'] :
            wfMessage('external-pages-w-same-title')->plain()),
        $wgBADIConfig['external_sites_templates']
      )
    );
    return true;
  }

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
