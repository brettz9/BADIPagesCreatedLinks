<?php

require('JobQueuer.php');

/**
 * For asynchronous requests
 * @see https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers
 */
class CheckBADIPagesCreatedLinks extends JobQueuer {
  public function __construct ($title, $params) {
		parent::__construct('CheckBADIPagesCreatedLinks', $title, $params);
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
