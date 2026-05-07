<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Helpers\Helpers;
use Entities\Job;

$em = Helpers::getManager();
$jobRepo = $em->getRepository(Job::class);

$allJobs = $jobRepo->findBy(
    [],
    ['updatedAt' => 'DESC'],
    15
);

echo "Last 15 Jobs Status & Ownership:\n";
echo "---------------------------------\n";
foreach ($allJobs as $job) {
    $payload = $job->getPayload() ?? [];
    $owner = $payload['instance_name'] ?? 'None';
    echo "ID: " . $job->getUuid() . "\n";
    echo "Time: " . $job->getUpdatedAt()->format('H:i:s') . "\n";
    echo "Status: " . $job->getStatus() . "\n";
    echo "Channel: " . $job->getChannel() . "\n";
    echo "Owner: " . $owner . "\n";
    echo "Message: " . substr((string)($job->getMessage() ?? ''), 0, 50) . "...\n";
    echo "---------------------------------\n";
}
