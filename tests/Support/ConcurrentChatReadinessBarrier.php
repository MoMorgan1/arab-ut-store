<?php

function waitForConcurrentChatRelease(string $readyPath, string $releasePath): void
{
    if ($readyPath === '' && $releasePath === '') {
        return;
    }

    if ($readyPath === '' || $releasePath === '' || ! touch($readyPath)) {
        throw new RuntimeException('Unable to join the concurrent chat readiness barrier.');
    }

    $deadline = microtime(true) + 20;

    while (! file_exists($releasePath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the concurrent chat release barrier.');
        }

        usleep(25_000);
    }
}
