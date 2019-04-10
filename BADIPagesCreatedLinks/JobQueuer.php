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
