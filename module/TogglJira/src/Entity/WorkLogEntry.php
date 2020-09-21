<?php
declare(strict_types=1);

namespace TogglJira\Entity;

use DateTimeInterface;

class WorkLogEntry
{
    /**
     * @var string
     */
    private $comment;

    /**
     * @var string
     */
    private $issueID;

    /**
     * @var DateTimeInterface
     */
    private $spentOn;

    /**
     * @var int
     */
    private $timeSpent;

    /**
     * @var int
     */
    private $id;


    public function getIssueID(): string
    {
        return $this->issueID;
    }

    public function setIssueID(string $issueID): void
    {
        $this->issueID = $issueID;
    }

    public function getTimeSpent(): int
    {
        return $this->timeSpent;
    }

    public function setTimeSpent(int $timeSpent): void
    {
        $this->timeSpent = $timeSpent;
    }

    public function getSpentOn(): DateTimeInterface
    {
        return $this->spentOn;
    }

    public function setSpentOn(DateTimeInterface $spentOn): void
    {
        $this->spentOn = $spentOn;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): void
    {
        $this->comment = $comment;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
