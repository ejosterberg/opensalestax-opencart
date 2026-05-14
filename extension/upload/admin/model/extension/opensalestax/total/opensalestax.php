<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Opencart\Admin\Model\Extension\Opensalestax\Total;

/**
 * Empty admin model — OpenCart 4.x requires a model class to exist alongside
 * each settings controller, but our settings persist via the platform's
 * built-in `setting/setting` table. No bespoke schema needed.
 *
 * Reserved for v0.2 (per-jurisdiction surface, customer-group rules).
 */
class Opensalestax extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        // No-op: settings live in oc_setting, which OC manages.
    }

    public function uninstall(): void
    {
        // No-op: settings stay in oc_setting until the merchant clears them.
    }
}
