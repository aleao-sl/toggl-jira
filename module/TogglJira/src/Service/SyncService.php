<?php
declare(strict_types=1);

namespace TogglJira\Service;

use AJT\Toggl\TogglClient;
use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use TogglJira\Entity\WorkLogEntry;
use TogglJira\Hydrator\WorkLogHydrator;
use TogglJira\Jira\Api;

class SyncService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const REQUIRED_TIME_SPENT = 28800;

    private const FILE_CACHE_LOGS = 'data/cache/logs.json';

    /**
     * @var Api
     */
    private $api;

    /**
     * @var TogglClient
     */
    private $togglClient;

    /**
     * @var WorkLogHydrator
     */
    private $workLogHydrator;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $fillIssueID;

    /**
     * @var string
     */
    private $fillIssueComment;

    /**
     * @var bool
     */
    private $notifyUsers;

    /**
     * @var array
     */
    private $cacheData;

    public function __construct(
        Api $api,
        GuzzleClient $togglClient,
        WorkLogHydrator $workLogHydrator,
        string $username,
        string $fillIssueID = null,
        string $fillIssueComment = '',
        bool $notifyUsers = true
    ) {
        $this->api = $api;
        $this->togglClient = $togglClient;
        $this->workLogHydrator = $workLogHydrator;
        $this->username = $username;
        $this->fillIssueID = $fillIssueID;
        $this->fillIssueComment = $fillIssueComment;
        $this->notifyUsers = $notifyUsers;
    }

    /**
     * @throws Exception
     */
    public function sync(DateTimeInterface $startDate, DateTimeInterface $endDate, bool $overwrite, bool $dryRun): void
    {
        // Make sure we always start and end at 0:00. We only sync per day.
        $startDate = new DateTime($startDate->format('Y-m-d'));
        $endDate = new DateTime($endDate->format('Y-m-d'));

        $user = $this->api->getUser($this->username);

        if (!isset($user['key'])) {
            throw new InvalidArgumentException(sprintf('No user found for username %s.', $this->username));
        }

        // Iterate over each day and process all time entries.
        while ($startDate <= $endDate) {
            // Fetch time entries once per day, use the startDate +1 day at 0:00:00, to make sure we cover the full day
            // in the iteration.
            $clonedStartDate = clone $startDate;
            $timeEntries = $this->getTimeEntries(
                $startDate,
                $clonedStartDate->add(new DateInterval('PT23H59M59S'))
            );

            if ($timeEntries === null) {
                break;
            }

            $startDate->modify('+1 day');

            if (empty($timeEntries)) {
                continue;
            }

            $workLogs = $this->parseTimeEntries(array_reverse($timeEntries));

            // Don't fill the current day, since the day might not be over yet
            // Otherwise, use the filler issue to add the remaining time in order to have the full day filled
            // Also, only for week days
            if ($this->fillIssueID &&
                $clonedStartDate->format('d-m-Y') !== (new DateTime())->format('d-m-Y') &&
                $clonedStartDate->format('N') <= 5
            ) {
                if(!$dryRun) {
                $workLogs = $this->fillTimeToFull($workLogs, $clonedStartDate);
                }
            }

            if(!$dryRun) {
                $this->addWorkLogsToApi($workLogs, $user['key'], $overwrite, $this->notifyUsers);
            }
        }

        if(!$dryRun) {
            $this->logger->info('All done for today, time to go home!');
        }
        else{
            $this->logger->info('DryRun option executed!');
        }
    }

    private function getTimeEntries(DateTimeInterface $startDate, DateTimeInterface $endDate): ?array
    {
        try {
            /** @var array $timeEntries */
            return $this->togglClient->getTimeEntries(
                [
                    'start_date' => $startDate->format(DATE_ATOM),
                    'end_date' => $endDate->format(DATE_ATOM),
                ]
            )->toArray();
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get time entries from Toggl',
                ['exception' => $e]
            );

            return null;
        }
    }

    private function getProjects(array $timeEntry): ?array
    {
        try {

            $cacheKey = 'wid' . $timeEntry['wid'];
            if(!isset($this->cacheData[$cacheKey])){
                $this->cacheData[$cacheKey] = $this->togglClient->GetProjects(
                    ['id' => $timeEntry['wid']])->toArray();
            }
            /** @var array $timeEntries */
            return $this->cacheData[$cacheKey];

        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get projects from Toggl',
                ['exception' => $e]
            );

            return null;
        }
    }

    /**
     * @throws Exception
     */
    private function parseTimeEntries(array $timeEntries): array
    {
        $workLogEntries = [];

        foreach ($timeEntries as $timeEntry) {

            $projects = $this->getProjects($timeEntry);
            $workLogEntry = $this->parseTimeEntry($timeEntry, $projects);

            if (!$workLogEntry) {
                continue;
            }

            $existingKey = md5((string)$timeEntry['id']);

            if (isset($workLogEntries[$existingKey])) {
                $this->addTimeToExistingTimeEntry($workLogEntries[$existingKey], $workLogEntry);
                continue;
            }

            $workLogEntries[$existingKey] = $workLogEntry;

            $this->logger->info('Found time entry for issue', [
                'uploaded' => $this->checkLogId($workLogEntry->getId()) ? "Yes" : "No",
                'issueID' => $workLogEntry->getIssueID(),
                'spentOn' => $workLogEntry->getSpentOn()->format('Y-m-d'),
                'timeSpent' => round($workLogEntry->getTimeSpent() / 60 / 60, 2) . ' hours',
                'logId' => $workLogEntry->getId()
            ]);
        }

        return $workLogEntries;
    }

    /**
     * @throws Exception
     */
    private function parseTimeEntry(array $timeEntry, array $projects): ?WorkLogEntry
    {

        if (!isset($timeEntry['description'])) {
            $this->logger->error('Missing description information.', [$timeEntry['start']]);
            return null;
        }

        $data = [
            'timeSpent' => $timeEntry['duration'],
            'comment' => $timeEntry['description'],
            'spentOn' => $timeEntry['start'],
            'id'      => $timeEntry['id']
        ];

        foreach ($projects as $project)
        {
            if (!isset($timeEntry['pid'])) {
                $this->logger->error('Missing timeEntry ID.', [$timeEntry['description'], $timeEntry['start']]);
                return null;
            }

            if ($project['id'] == $timeEntry['pid']){
                $data['issueID'] = $project['name'];
                break;
            }
        }

        if (strpos($data['issueID'], '-') === false) {
            $this->logger->warning('Could not parse issue string, cannot link to Jira');
            return null;
        }

        if ($data['timeSpent'] < 0) {
            $this->logger->info('0 seconds, or timer still running, skipping', [
                'issueID' => $data['issueID']
            ]);
            return null;
        }

        return $this->workLogHydrator->hydrate($data, new WorkLogEntry());
    }

    private function addTimeToExistingTimeEntry(WorkLogEntry $existingWorkLog, WorkLogEntry $newWorkLog): WorkLogEntry
    {
        $timeSpent = $existingWorkLog->getTimeSpent();
        $timeSpent += $newWorkLog->getTimeSpent();

        $existingWorkLog->setTimeSpent($timeSpent);

        if (!preg_match("/{$existingWorkLog->getComment()}/", $existingWorkLog->getComment())) {
            $existingWorkLog->setComment($existingWorkLog->getComment() . "\n" . $newWorkLog->getComment());
        }

        $this->logger->info('Added time spent for issue', [
            'issueID' => $newWorkLog->getIssueID(),
            'spentOn' => $newWorkLog->getSpentOn()->format('Y-m-d'),
            'timeSpent' => round($newWorkLog->getTimeSpent() / 60 / 60, 2) . ' hours',
        ]);

        return $existingWorkLog;
    }

    private function addWorkLogsToApi(
        array $workLogEntries,
        string $userKey,
        bool $overwrite,
        bool $notifyUsers = true
    ): void {
        /** @var WorkLogEntry $workLogEntry */
        foreach ($workLogEntries as $workLogEntry) {
            try {
                if (!$this->checkLogId($workLogEntry->getId())) {

                    $result = $this->api->addWorkLogEntry(
                        $workLogEntry->getIssueID(),
                        $workLogEntry->getTimeSpent(),
                        $userKey,
                        $workLogEntry->getComment(),
                        $workLogEntry->getSpentOn()->format('Y-m-d\TH:i:s.vO'),
                        $overwrite,
                        $notifyUsers
                    );

                    if (isset($result->getResult()['errorMessages']) && \count($result->getResult()['errorMessages']) > 0) {
                        $this->logger->error(implode("\n", $result->getResult()['errorMessages']), [
                            'issueID'   => $workLogEntry->getIssueID(),
                            'logId'     => $workLogEntry->getId(),
                        ]);
                    } else {

                        $this->saveLogId($workLogEntry->getId());
                        $this->logger->info('Saved work logs entry', [
                            'issueID'   => $workLogEntry->getIssueID(),
                            'spentOn'   => $workLogEntry->getSpentOn()->format('Y-m-d'),
                            'timeSpent' => round($workLogEntry->getTimeSpent() / 60 / 60, 2) . ' hours',
                            'logId'     => $workLogEntry->getId(),
                        ]);

                    }
                }
            } catch (Exception $e) {
                $this->logger->error('Could not add worklog entry', ['exception' => $e]);
            }
        }
    }

    private function fillTimeToFull(array $workLogEntries, DateTime $processDate): array
    {
        $timeSpent = 0;

        /** @var WorkLogEntry $workLogEntry */
        foreach ($workLogEntries as $workLogEntry) {
            if ($workLogEntry->getIssueID() === $this->fillIssueID) {
                $fillIssue = $workLogEntry;
            }

            $timeSpent += $workLogEntry->getTimeSpent();
        }

        if ($timeSpent >= self::REQUIRED_TIME_SPENT) {
            return $workLogEntries;
        }

        $fillTime = self::REQUIRED_TIME_SPENT - $timeSpent + 60;

        if (!isset($fillIssue)) {
            $fillIssue = new WorkLogEntry();
            $fillIssue->setIssueID($this->fillIssueID);
            $fillIssue->setComment($this->fillIssueComment);
            $fillIssue->setSpentOn($processDate);
            $fillIssue->setTimeSpent($fillTime);

            $workLogEntries[] = $fillIssue;
        } else {
            $fillIssue->setTimeSpent($fillIssue->getTimeSpent() + $fillTime);
        }


        return $workLogEntries;
    }

    private function saveLogId($id)
    {

        $fileContent = [];

        if (!file_exists(self::FILE_CACHE_LOGS)) {
            touch(self::FILE_CACHE_LOGS);
        }

        $fileContentJson = file_get_contents(self::FILE_CACHE_LOGS);
        if ($fileContentJson) {
            $fileContent = json_decode($fileContentJson, true);
        }

        $fileContent[$id] = true;
        $fileContentJson = json_encode($fileContent);
        file_put_contents(self::FILE_CACHE_LOGS, $fileContentJson);

    }

    private function checkLogId($id)
    {

        if (!file_exists(self::FILE_CACHE_LOGS)) {
            touch(self::FILE_CACHE_LOGS);
        }

        $fileContent = [];
        $fileContentJson = file_get_contents(self::FILE_CACHE_LOGS);

        if($fileContentJson){
            $fileContent = json_decode($fileContentJson,true);
        }

        return isset($fileContent[$id]);

    }
}
