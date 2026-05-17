<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Exceptions;

/**
 * Raised when the admin-supplied configuration is unusable.
 *
 * Distinct from engine-side errors (which are `OpenCartOpenSalesTaxException`
 * with a wrapped SDK cause) so the caller can decide whether to log
 * differently.
 */
final class ConfigurationException extends OpenCartOpenSalesTaxException
{
}
