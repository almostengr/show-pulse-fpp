<?php

namespace App;

use Exception;

require_once "ShowPulseWorker.php";

$worker = new ShowPulseWorker();
while (true) {
    try {
        $worker->getWebsiteApiKey();
        $worker->getFppStatus();
        $worker->postStatus();
        $worker->insertNextRequest();
        $sleepTime = $worker->calculateSleepTime();
        sleep($sleepTime);
        $worker->resetFailureCount();
    } catch (Exception $e) {
        $worker->logFailure($e->getMessage());
        $sleepTime = $worker->exponentialSleepTime();
        $worker->increaseFailureCount();
        sleep($sleepTime);
    }
}