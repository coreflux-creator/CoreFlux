<?php
/** Shared package contract for Composer outside the standalone document root. */
declare(strict_types=1);

function coreAccountingPortableVendorShim(): string
{
    return "<?php\n/** Composer runtime is packaged beside the public document root. */\n"
        . "return require dirname(__DIR__, 2) . '/private_runtime/vendor/autoload.php';\n";
}
