<?php
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
?>
