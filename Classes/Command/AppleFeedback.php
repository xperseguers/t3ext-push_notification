<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

namespace Causal\PushNotification\Command;

use Causal\PushNotification\Service\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AppleFeedback extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        if (!$this->purgeOutdatedTokens()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function purgeOutdatedTokens(): bool
    {
        $notificationService = NotificationService::getInstance();
        $notificationService->removeStaleTokens();  // works for all tokens

        return true;
    }
}
