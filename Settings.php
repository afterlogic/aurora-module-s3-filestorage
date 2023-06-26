<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\S3Filestorage;

use Aurora\System\SettingsProperty;

/**
 * @property bool $Disabled
 * @property string $AccessKey
 * @property string $SecretKey
 * @property string $Region
 * @property string $Host
 * @property string $BucketPrefix
 * @property int $PresignedLinkLifetimeMinutes
 * @property bool $RedirectToOriginalFileURLs
 */

class Settings extends \Aurora\System\Module\Settings
{
    protected function initDefaults()
    {
        $this->aContainer = [
            "Disabled" => new SettingsProperty(
                false,
                "bool",
                null,
                "Setting to true disables the module",
            ),
            "AccessKey" => new SettingsProperty(
                "",
                "string",
                null,
                "S3 storage access key",
            ),
            "SecretKey" => new SettingsProperty(
                "",
                "string",
                null,
                "S3 storage secret key",
            ),
            "Region" => new SettingsProperty(
                "",
                "string",
                null,
                "S3 storage region",
            ),
            "Host" => new SettingsProperty(
                "",
                "string",
                null,
                "S3 storage hostname",
            ),
            "BucketPrefix" => new SettingsProperty(
                "",
                "string",
                null,
                "Bucket prefix you wish to be used",
            ),
            "PresignedLinkLifetimeMinutes" => new SettingsProperty(
                60,
                "int",
                null,
                "Lifetime of links with authentication token built into those, for use by external services such as OnlyOffice",
            ),
            "RedirectToOriginalFileURLs" => new SettingsProperty(
                true,
                "bool",
                null,
                "If true, files on S3 storage are obtained via redirect to their actual URLs",
            ),
            "UsePathStyleEndpoint" => new SettingsProperty(
                false,
                "bool",
                null,
                "If true, send requests to an S3 path style endpoint"
            ),
        ];
    }
}
