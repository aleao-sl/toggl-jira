<?php
declare(strict_types=1);

namespace TogglJira\Jira;

use chobie\Jira\Api as BaseApi;
use Exception;

class Api extends BaseApi
{
    public function getUser(string $username): array
    {
        $userDetails = $this->api(self::REQUEST_GET, "/rest/api/2/user", ['username' => $username]);

        return $userDetails->getResult();
    }

    /**
     * @return array|BaseApi\Result|false
     * @throws Exception
     */
    public function addWorkLogEntry(
        string $issueID,
        int $seconds,
        string $userKey,
        string $comment,
        string $created,
        bool $overwrite,
        bool $notifyUsers = true
    ) {
        $notify = $notifyUsers ? 'true' : 'false';
        $params = [
            'timeSpentSeconds' => $seconds,
            'comment' => $comment,
            'started' => $created,
        ];

        $worklogResponse = $this->api(self::REQUEST_GET, "/rest/api/2/issue/{$issueID}/worklog");
        $workLogResult = $worklogResponse->getResult();

        $startedDay = (new \DateTimeImmutable($params['started']))->format('Y-m-d');

        if (isset($workLogResult['worklogs'])) {
            foreach ($workLogResult['worklogs'] as $workLog) {
                $workLogStartedDay = (new \DateTimeImmutable($workLog['started']))->format('Y-m-d');

                if ($startedDay !== $workLogStartedDay || $workLog['author']['key'] !== $userKey) {
                    continue;
                }

                if (!$overwrite) {
                    return $this->api(
                        self::REQUEST_POST,
                        "/rest/api/2/issue/{$issueID}/worklog?adjustEstimate=auto&notifyUsers={$notify}",
                        $params
                    );
                }


                if ($overwrite) {
                    /**
                     * When overwriting the worklogs, delete the existing worklogs first before recreating.
                     */
                    $this->api(self::REQUEST_DELETE, "/rest/api/2/issue/{$issueID}/worklog/{$workLog['id']}");
                }
            }
        }
        return $this->api(self::REQUEST_POST, "/rest/api/2/issue/{$issueID}/worklog?adjustEstimate=auto&notifyUsers={$notify}", $params);
    }
}
