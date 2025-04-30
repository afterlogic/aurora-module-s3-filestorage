<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\S3Filestorage;

use Afterlogic\DAV\Constants;
use Afterlogic\DAV\FS\Shared\File as SharedFile;
use Afterlogic\DAV\FS\Shared\Directory as SharedDirectory;
use Aurora\Api;
use Aws\S3\S3Client;
use Aurora\System\Exceptions\ApiException;
use Aurora\Modules\PersonalFiles\Module as PersonalFiles;

/**
 * Adds ability to work with S3 file storage inside Aurora Files module.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends PersonalFiles
{
    protected $aRequireModules = ['PersonalFiles'];

    protected $oClient = null;
    protected $sUserPublicId = null;

    protected $sBucketPrefix;
    protected $sBucket;
    protected $sRegion;
    protected $sHost;
    protected $sAccessKey;
    protected $sSecretKey;

    protected $oTenantForDelete = null;
    protected $oUserForDelete = null;

    protected $sTenantName;

    /***** private functions *****/
    /**
     * Initializes Module.
     *
     * @ignore
     */
    public function init()
    {
        $personalFiles = PersonalFiles::getInstance();
        if ($personalFiles && !$this->oModuleSettings->Disabled) {
            $personalFiles->setConfig('Disabled', true);
        }

        parent::init();

        $this->subscribeEvent('Core::DeleteTenant::before', array($this, 'onBeforeDeleteTenant'));
        $this->subscribeEvent('Core::DeleteTenant::after', array($this, 'onAfterDeleteTenant'));

        $this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
        $this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));

        $this->subscribeEvent('AddToContentSecurityPolicyDefault', array($this, 'onAddToContentSecurityPolicyDefault'));

        $sTenantName = $this->getTenantName();
        $sTenantName = $sTenantName ? $sTenantName : '';
        $this->sBucketPrefix = $this->oModuleSettings->BucketPrefix;
        $this->sBucket = \strtolower($this->sBucketPrefix . \str_replace([' ', '.'], '-', $sTenantName));
        $this->sHost = $this->oModuleSettings->Host;
        $this->sRegion = $this->oModuleSettings->Region;
        $this->sAccessKey = $this->oModuleSettings->AccessKey;
        $this->sSecretKey = $this->oModuleSettings->SecretKey;
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    public function onAddToContentSecurityPolicyDefault($aArgs, &$aAddDefault)
    {
        $aAddDefault[] = "https://" . $this->sHost;
        $aAddDefault[] = "https://" . $this->sBucket . "." . $this->sHost;
    }

    /**
     * Obtains list of module settings for authenticated user.
     *
     * @param int|null $TenantId Tenant ID
     *
     * @return array
     */
    public function GetSettings($TenantId = null)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

        $oSettings = $this->oModuleSettings;

        // TODO: temporary desabled getting tenant setting. It must return all settings, not only region and host
        // $aSettings = [];
        // if (!empty($TenantId)) {
        //     \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        //     $oTenant = \Aurora\System\Api::getTenantById($TenantId);
        //     $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        //     if ($oTenant && ($oAuthenticatedUser->isAdmin() || $oAuthenticatedUser->IdTenant === $oTenant->Id)) {
        //         $aSettings = [
        //             'Region' => $oSettings->GetTenantValue($oTenant->Name, 'Region', ''),
        //             'Host' => $oSettings->GetTenantValue($oTenant->Name, 'Host', ''),
        //         ];
        //     }
        // } else {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        $aSettings = [
            'AccessKey' => $oSettings->AccessKey,
            'SecretKey' => $oSettings->SecretKey,
            'Region' => $oSettings->Region,
            'Host' => $oSettings->Host,
            'BucketPrefix' => $oSettings->BucketPrefix,
        ];
        // }


        return $aSettings;
    }

    /**
     * Updates module's settings - saves them to config.json file.
     *
     * @param string $AccessKey
     * @param string $SecretKey
     * @param string $Region
     * @param string $Host
     * @param string $BucketPrefix
     * @param int|null $TenantId
     *
     * @return boolean
     */
    public function UpdateS3Settings($AccessKey, $SecretKey, $Region, $Host, $BucketPrefix, $TenantId = null)
    {
        $oSettings = $this->oModuleSettings;

        // TODO: temporary desabled saving tenant setting. It must save all settings, not only region and host
        // if (!empty($TenantId)) {
        // \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        // $oTenant = \Aurora\System\Api::getTenantById($TenantId);
        // $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        // if ($oTenant && ($oAuthenticatedUser->isAdmin() || $oAuthenticatedUser->IdTenant === $oTenant->Id)) {
        //     return $oSettings->SaveTenantSettings($oTenant->Name, [
        //         'Region' => $Region,
        //         'Host' => $Host
        //     ]);
        // }
        // } else {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);

        $oSettings->AccessKey = $AccessKey;
        $oSettings->SecretKey = $SecretKey;
        $oSettings->Region = $Region;
        $oSettings->Host = $Host;
        $oSettings->BucketPrefix = $BucketPrefix;
        return $oSettings->Save();
        // }

        // return false;
    }

    public function GetUsersFolders($iTenantId)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($oUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant !== $iTenantId) {
            throw new ApiException(\Aurora\System\Notifications::AccessDenied, null, 'AccessDenied');
        } else {
            Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        }

        if (!empty($iTenantId)) {
            $this->sBucket = $this->getBucketForTenant($iTenantId);

            $results = $this->getClient(true)->listObjectsV2([
                'Bucket' => $this->getBucketForTenant($iTenantId),
                'Prefix' => '',
                'Delimiter' => '/'
            ]);

            $aUsersFolders = [];
            if (is_array($results['CommonPrefixes']) && count($results['CommonPrefixes']) > 0) {
                foreach ($results['CommonPrefixes'] as $aPrefix) {
                    if (substr($aPrefix['Prefix'], -1) === '/') {
                        $aUsersFolders[] = \rtrim($aPrefix['Prefix'], '/');
                    }
                }
            }

            return $aUsersFolders;
        } else {
            throw new ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }
    }


    protected function getS3Client()
    {
        $options = [
            'region' => $this->sRegion,
            'version' => 'latest',
            'credentials' => [
                'key'    => $this->sAccessKey,
                'secret' => $this->sSecretKey,
            ]
        ];
        if (!empty($this->sHost)) {
            $options['endpoint'] = 'https://' . $this->sHost;
        }
        return new S3Client($options);
    }

    /**
     * Obtains DropBox client if passed $sType is DropBox account type.
     *
     * @param boolean $bRenew
     * @return S3Client
     */
    protected function getClient($bRenew = false)
    {
        if ($this->oClient === null || $bRenew) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

            $this->oClient = $this->getS3Client();

            if (!$this->oClient->doesBucketExist($this->sBucket)) {
                $sBucketLocation = $this->oModuleSettings->BucketLocation;

                $aOptions = [
                    'Bucket' => $this->sBucket,
                ];

                if (!empty($sBucketLocation)) {
                    $aOptions['CreateBucketConfiguration'] = [
                        'LocationConstraint' => $sBucketLocation,
                    ];
                }

                $this->oClient->createBucket($aOptions);

                $res = $this->oClient->putBucketCors([
                    'Bucket' => $this->sBucket,
                    'CORSConfiguration' => [
                        'CORSRules' => [
                            [
                                'AllowedHeaders' => [
                                    '*',
                                ],
                                'AllowedMethods' => [
                                    'GET',
                                    'PUT',
                                    'POST',
                                    'DELETE',
                                    'HEAD'
                                ],
                                'AllowedOrigins' => [
                                    (\Aurora\System\Api::isHttps() ? "https" : "http") . "://" . $_SERVER['HTTP_HOST']
                                ],
                                'MaxAgeSeconds' => 0,
                            ],
                        ],
                    ],
                    'ContentMD5' => '',
                ]);
            }
        }

        return $this->oClient;
    }

    protected function getUserPublicId()
    {
        if (!isset($this->sUserPublicId)) {
            $oUser = \Aurora\System\Api::getAuthenticatedUser();
            $this->sUserPublicId = $oUser->PublicId;
        }

        return $this->sUserPublicId;
    }

    /**
     * getTenantName
     *
     * @return string
     */
    protected function getTenantName()
    {
        if (!isset($this->sTenantName)) {
            $this->sTenantName = \Aurora\System\Api::getTenantName();
        }

        return $this->sTenantName;
    }

    protected function getBucketForTenant($iIdTenant)
    {
        $mResult = false;
        $oTenant = \Aurora\Api::getTenantById($iIdTenant);
        if ($oTenant instanceof \Aurora\Modules\Core\Models\Tenant) {
            $mResult = \strtolower($this->sBucketPrefix . \str_replace([' ', '.'], '-', $oTenant->Name));
        }

        return $mResult;
    }

    protected function copyObject($sFromPath, $sToPath, $sOldName, $sNewName, $bIsFolder = false, $bMove = false)
    {
        $mResult = false;

        $sUserPublicId = $this->getUserPublicId();

        $sSuffix = $bIsFolder ? '/' : '';

        $sFullFromPath = $this->sBucket . '/' . $sUserPublicId . $sFromPath . '/' . $sOldName . $sSuffix;
        $sFullToPath = $sUserPublicId . $sToPath . '/' . $sNewName . $sSuffix;

        $oClient = $this->getClient();

        $oObject = $oClient->headObject([
            'Bucket' => $this->sBucket,
            'Key' => urldecode($sUserPublicId . $sFromPath . '/' . $sOldName . $sSuffix)
        ]);

        $aMetadata = [];
        $sMetadataDirective = 'COPY';
        if ($oObject) {
            $aMetadata = $oObject->get('Metadata');
            $aMetadata['filename'] = $sNewName;
            $sMetadataDirective = 'REPLACE';
        }

        $res = $oClient->copyObject([
            'Bucket' => $this->sBucket,
            'Key' => $sFullToPath,
            'CopySource' => $sFullFromPath,
            'Metadata' => $aMetadata,
            'MetadataDirective' => $sMetadataDirective
        ]);

        if ($res) {
            if ($bMove) {
                $res = $oClient->deleteObject([
                    'Bucket' => $this->sBucket,
                    'Key' => $sUserPublicId . $sFromPath . '/' . $sOldName . $sSuffix
                ]);
            }
            $mResult = true;
        }

        return $mResult;
    }

    protected function getDirectory($sUserPublicId, $sType, $sPath = '')
    {
        $oDirectory = null;

        if ($sUserPublicId) {
            $oDirectory = \Afterlogic\DAV\Server::getNodeForPath('files/' . $sType . '/' . \trim($sPath, '/'), $sUserPublicId);
        }

        return $oDirectory;
    }

    protected function copy($UserId, $FromType, $FromPath, $FromName, $ToType, $ToPath, $ToName, $IsMove = false)
    {
        $sUserPublicId = \Aurora\Api::getUserPublicIdById($UserId);

        $sPath = 'files/' . $FromType . $FromPath . '/' . $FromName;
        $oItem = \Afterlogic\DAV\Server::getNodeForPath($sPath, $sUserPublicId);

        $oToDirectory = $this->getDirectory($sUserPublicId, $ToType, $ToPath);
        $bIsSharedFile = ($oItem instanceof SharedFile || $oItem instanceof SharedDirectory);
        $bIsSharedToDirectory = ($oToDirectory instanceof SharedDirectory);
        $iNotPossibleToMoveSharedFileToSharedFolder = 0;
        if (class_exists('\Aurora\Modules\SharedFiles\Enums\ErrorCodes')) {
            $iNotPossibleToMoveSharedFileToSharedFolder = \Aurora\Modules\SharedFiles\Enums\ErrorCodes::NotPossibleToMoveSharedFileToSharedFolder;
        }
        if ($IsMove && $bIsSharedFile && $bIsSharedToDirectory) {
            throw new ApiException($iNotPossibleToMoveSharedFileToSharedFolder);
        }

        if (($oItem instanceof SharedFile || $oItem instanceof SharedDirectory) && !$oItem->isInherited()) {
            $oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
            $oPdo->updateSharedFileSharePath(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $oItem->getName(), $ToName, $FromPath, $ToPath, $oItem->getGroupId());

            $oItem = $oItem->getNode();
        } else {
            $ToName = $this->getManager()->getNonExistentFileName(
                $sUserPublicId,
                $ToType,
                $ToPath,
                $ToName
            );
            if (!$bIsSharedToDirectory) {
                $oItem->copyObjectTo($ToType, $ToPath, $ToName);
            } else {
                $oToDirectory->createFile($ToName, $oItem->get(false));
            }
            $oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
            $oPdo->updateShare(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $FromType, $FromPath . '/' . $FromName, $ToType, $ToPath . '/' . $ToName);
            if ($IsMove) {
                \Afterlogic\DAV\Server::deleteNode($sPath, $sUserPublicId);
            }
        }
    }

    // /**
    //  * Moves file if $aData['Type'] is DropBox account type.
    //  *
    //  * @ignore
    //  * @param array $aData
    //  */
    // public function onAfterMove(&$aArgs, &$mResult)
    // {
    // 	\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

    // 	if ($this->checkStorageType($aArgs['FromType']))
    // 	{
    // 		$mResult = false;

    // 		$UserId = $aArgs['UserId'];
    // 		Api::CheckAccess($UserId);

    // 		// if ($aArgs['ToType'] === $aArgs['FromType'])
    // 		// {
    // 			foreach ($aArgs['Files'] as $aFile)
    // 			{
    // 				$ToName = isset($aFile['NewName']) ? $aFile['NewName'] : $aFile['Name'];
    // 				$this->copy($UserId, $aArgs['FromType'], $aFile['FromPath'], $aFile['Name'], $aArgs['ToType'], $aArgs['ToPath'], $ToName, true);
    // 			}
    // 			$mResult = true;
    // 		// }
    // 	}

    // }

    // /**
    //  * Copies file if $aData['Type'] is DropBox account type.
    //  *
    //  * @ignore
    //  * @param array $aData
    //  */
    // public function onAfterCopy(&$aArgs, &$mResult)
    // {
    // 	\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

    // 	if ($this->checkStorageType($aArgs['FromType']))
    // 	{
    // 		$mResult = false;

    // 		$UserId = $aArgs['UserId'];
    // 		Api::CheckAccess($UserId);

    // 		// if ($aArgs['ToType'] === $aArgs['FromType'])
    // 		// {
    // 			foreach ($aArgs['Files'] as $aFile)
    // 			{
    // 				$ToName = isset($aFile['NewName']) ? $aFile['NewName'] : $aFile['Name'];
    // 				$this->copy($UserId, $aArgs['FromType'], $aFile['FromPath'], $aFile['Name'], $aArgs['ToType'], $aArgs['ToPath'], $ToName, false);
    // 			}
    // 			$mResult = true;
    // 		// }
    // 	}
    // }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterGetQuota($aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type'])) {
            $aQuota = [0, 0];
            $oNode = \Afterlogic\DAV\Server::getNodeForPath('files/' . static::$sStorageType);

            if (is_a($oNode, 'Afterlogic\\DAV\\FS\\S3\\' . ucfirst(static::$sStorageType) . '\\Root')) {
                $aQuota = $oNode->getQuotaInfo();
            }
            $iSpaceLimitMb = $aQuota[1];

            $aArgs = [
                'UserId' => \Aurora\System\Api::getAuthenticatedUserId()
            ];
            $this->broadcastEvent(
                'GetUserSpaceLimitMb',
                $aArgs,
                $iSpaceLimitMb
            );

            $mResult = [
                'Used' => $aQuota[0],
                'Limit' => $iSpaceLimitMb
            ];
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterGetSubModules($aArgs, &$mResult)
    {
        array_unshift($mResult, 's3.' . static::$sStorageType);
    }


    protected function isNeedToReturnBody()
    {
        $sMethod = $this->oHttp->GetPost('Method', null);

        return ((string) \Aurora\System\Router::getItemByIndex(2, '') === 'thumb' ||
            $sMethod === 'SaveFilesAsTempFiles' ||
            $sMethod === 'GetFilesForUpload'
        );
    }

    protected function isNeedToReturnWithContectDisposition()
    {
        $sAction = (string) \Aurora\System\Router::getItemByIndex(2, 'download');
        return $sAction ===  'download';
    }

    protected function deleteUserFolder($IdTenant, $PublicId)
    {
        $bResult = false;
        try {
            $oS3Client = $this->getS3Client();
            $res = $oS3Client->deleteMatchingObjects(
                $this->getBucketForTenant($IdTenant),
                $PublicId . '/'
            );
            $bResult = true;
        } catch(\Exception $oEx) {
            $bResult = false;
        }

        return $bResult;
    }

    /**
     * Puts file content to $mResult.
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onGetFile($aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type'])) {
            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);

            $sUserPiblicId = \Aurora\Api::getUserPublicIdById($UserId);

            try {
                $sPath = 'files/' . $aArgs['Type'] . $aArgs['Path'] . '/' . $aArgs['Name'];
                /** @var \Afterlogic\DAV\FS\S3\File $oNode */
                $oNode = \Afterlogic\DAV\Server::getNodeForPath($sPath, $sUserPiblicId);

                if ($oNode instanceof \Afterlogic\DAV\FS\File) {
                    $sExt = \pathinfo($aArgs['Name'], PATHINFO_EXTENSION);

                    $oS3FilestorageModule = \Aurora\System\Api::GetModule('S3Filestorage');
                    $bRedirectToUrl = $oS3FilestorageModule ? $oS3FilestorageModule->getConfig('RedirectToOriginalFileURLs', true) : true;
    
                    $bNoRedirect = isset($aArgs['NoRedirect']) ? $aArgs['NoRedirect'] : !$bRedirectToUrl;
                    
                    if ($this->isNeedToReturnBody() || \strtolower($sExt) === 'url' || $bNoRedirect) {
                        $mResult = $oNode->get(false);
                    } else {
                        $sUrl = $oNode->getUrl($this->isNeedToReturnWithContectDisposition());
                        if (!empty($sUrl)) {
                            \Aurora\System\Api::Location($sUrl);
                            exit;
                        }
                    }
                }
            } catch (\Sabre\DAV\Exception\NotFound $oEx) {
                $mResult = false;
                //				echo(\Aurora\System\Managers\Response::GetJsonFromObject('Json', \Aurora\System\Managers\Response::FalseResponse(__METHOD__, 404, 'Not Found')));
                $this->oHttp->StatusHeader(404);
                exit;
            }

            return true;
        }
    }

    public function onBeforeDeleteTenant($aArgs, &$mResult)
    {
        $this->oTenantForDelete = \Aurora\Api::getTenantById($aArgs['TenantId']);
    }

    public function onAfterDeleteTenant($aArgs, &$mResult)
    {
        if ($this->oTenantForDelete instanceof \Aurora\Modules\Core\Models\Tenant) {
            try {
                $oS3Client = $this->getS3Client();
                $oS3Client->deleteBucket([
                    'Bucket' => \strtolower($this->sBucketPrefix . \str_replace([' ', '.'], '-', $this->oTenantForDelete->Name))
                ]);
                $this->oTenantForDelete = null;
            } catch(\Exception $oEx) {
            }
        }
    }

    public function onBeforeDeleteUser($aArgs, &$mResult)
    {
        if (isset($aArgs['UserId'])) {
            $this->oUserForDelete = \Aurora\System\Api::getUserById($aArgs['UserId']);
        }
    }

    public function onAfterDeleteUser($aArgs, $mResult)
    {
        if ($this->oUserForDelete instanceof \Aurora\Modules\Core\Models\User) {
            if ($this->deleteUserFolder($this->oUserForDelete->IdTenant, $this->oUserForDelete->PublicId)) {
                $this->oUserForDelete = null;
            }
        }
    }

    public function TestConnection($Region, $Host, $AccessKey = null, $SecretKey = null, $TenantId = null)
    {
        $mResult = true;

        if (isset($TenantId)) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

            $oTenant = \Aurora\System\Api::getTenantById($TenantId);

            if ($oTenant) {
                $AccessKey = $this->oModuleSettings->AccessKey;
                $SecretKey = $this->oModuleSettings->SecretKey;
            }
        } else {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        }
        try {
            $options = [
                'region' => $Region,
                'version' => 'latest',
                'credentials' => [
                    'key'    => $AccessKey,
                    'secret' => $SecretKey,
                ]
            ];
            if (!empty($Host)) {
                $options['endpoint'] = 'https://' . $Host;
            }
            $s3Client = new S3Client($options);

            $buckets = $s3Client->listBuckets();
        } catch(\Exception $e) {
            $mResult = false;
            Api::LogException($e);
        }
        return $mResult;
    }
}
