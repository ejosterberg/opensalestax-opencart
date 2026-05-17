<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Exceptions;

use RuntimeException;

/**
 * Base exception for the OpenCart connector.
 *
 * The connector default is fail-soft (catch and yield to OpenCart's built-in
 * tax). Fail-hard mode rethrows engine / configuration errors wrapped in this
 * type so OpenCart's order-total flow can surface a single recognizable
 * exception class to upstream handlers.
 */
class OpenCartOpenSalesTaxException extends RuntimeException
{
}
