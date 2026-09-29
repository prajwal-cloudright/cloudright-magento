<?php
/**
 * CloudRight_Payments module registration.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'CloudRight_Payments',
    __DIR__
);
