<?php

declare (strict_types=1);
namespace NBH\Performance\Vendor\Sabberworm\CSS\RuleSet;

use function NBH\Performance\Vendor\Safe\class_alias;
if (!\class_exists(RuleContainer::class, \false) && !\interface_exists(RuleContainer::class, \false)) {
    /**
     * @deprecated in v9.2, will be removed in v10.0.  Use `DeclarationList` instead, which is a direct replacement.
     */
    class_alias(DeclarationList::class, RuleContainer::class);
}
