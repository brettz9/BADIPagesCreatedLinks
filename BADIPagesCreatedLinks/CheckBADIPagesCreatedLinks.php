<?php
// For php maintenance/runJobs.php
// error_reporting( E_ALL );
// ini_set( 'display_errors', 1 );

require('JobQueuer.php');

/**
 * For asynchronous requests
 * @see https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers
 */
class CheckBADIPagesCreatedLinks extends JobQueuer {
  static $id = 'CheckBADIPagesCreatedLinks';
  public function __construct ($title, $params) {
		parent::__construct(self::$id, $title, $params);
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
    $params['type'] = self::$id;

    $title = Title::newFromText(
      implode(DIRECTORY_SEPARATOR, [$ns, $type, $params['articleTitle']]) . uniqid(),
      NS_SPECIAL
    );

    parent::queue($params, $title);
  }

  /**
	 * Execute the job
	 *
	 * @return boolean
	 */
	public function run () {
		// Load data from $this->params and $this->title
    $table = $this->params['table'];
    $badiConfig = $this->params['badiConfig'];
    $url = $this->params['url'];
    $rowID = $this->params['row_id'];
    $insertCache = $this->params['insertCache'];
    $updateCache = $this->params['updateCache'];
    $currTime = $this->params['curr_time'];

    // Store default options to be able to return back to them
    //  later (in case MediaWiki or other extensions will rely on it)
    $defaultOpts = stream_context_get_options(stream_context_get_default());

    // Temporarily change context for the sake of `get_headers()`
    //  (Wikipedia, though not MediaWiki, disallows HEAD requests
    //  without a user-agent specified)
    stream_context_set_default(
      isset($badiConfig['stream_context']) &&
        count($badiConfig['stream_context'])
        ? $badiConfig['stream_context']
        : [
          'http' => [
            'user_agent' => (
              isset($badiConfig['user-agent'])
                ? $badiConfig['user-agent']
                : wfMessage('user-agent')->plain()
            )
          ]
        ]
    );

    $headers = get_headers($url, 1);

    stream_context_set_default($defaultOpts); // Set it back to original value

    // Todo: Distinguish codes to add "erred" `remote_status`
    $oldPageExists = !!(
      (isset($headers['Last-Modified']) && $headers['Last-Modified']) ||
      (strpos($headers[0], '200') !== false)
    );
    $createdState = $oldPageExists ? 'existing' : 'missing';

    $dbr = wfGetDB(DB_SLAVE);
    if ($updateCache) {
      $dbr->update(
        $table,
        ['remote_status' => $createdState],
        ['id' => $rowID],
        __METHOD__
      );
    }
    else if ($insertCache) {
      $dbr->insert($table, [
        'url' => $url,
        'remote_status' => $createdState,
        'last_checked' => $currTime
      ], __METHOD__);
    }

		return true;
	}
}
