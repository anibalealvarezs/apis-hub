<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Helpers\Helpers;
use Entities\Job;
use Enums\JobStatus;

$em = Helpers::getManager();
$jobRepo = $em->getRepository(Job::class);

$failedJobs = $jobRepo->findBy(
    ['status' => JobStatus::failed->value],
    ['updatedAt' => 'DESC'],
    5
);

echo "Last 5 Failed Jobs (with Timestamps):\n";
echo "------------------------------------\n";
foreach ($failedJobs as $job) {
    echo "ID: " . $job->getUuid() . "\n";
    echo "Time: " . $job->getUpdatedAt()->format('Y-m-d H:i:s') . "\n";
    echo "Channel: " . $job->getChannel() . "\n";
    echo "Message: " . $job->getMessage() . "\n";
    echo "------------------------------------\n";
}
