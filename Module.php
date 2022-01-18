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

/**
 * Adds ability to work with S3 file storage inside Aurora Files module.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2020, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\Modules\PersonalFiles\Module
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

	protected $aSettings = null;

	protected $oTenantForDelete = null;
	protected $oUserForDelete = null;

	/***** private functions *****/
	/**
	 * Initializes Module.
	 *
	 * @ignore
	 */
	public function init()
	{
		parent::init();

		$this->subscribeEvent('Core::DeleteTenant::before', array($this, 'onBeforeDeleteTenant'));
		$this->subscribeEvent('Core::DeleteTenant::after', array($this, 'onAfterDeleteTenant'));

		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
		$this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));
		
		$this->subscribeEvent('AddToContentSecurityPolicyDefault', array($this, 'onAddToContentSecurityPolicyDefault'));

		$this->denyMethodsCallByWebApi([
			'DeleteUserFolder',
			'GetUserByUUID'
		]);

		$this->sBucketPrefix = $this->getConfig('BucketPrefix');
		$this->sBucket = \strtolower($this->sBucketPrefix . \str_replace([' ', '.'], '-', $this->getTenantName()));
		$this->sHost = $this->getConfig('Host');
		$this->sRegion = $this->getConfig('Region');
		$this->sAccessKey = $this->getConfig('AccessKey');
		$this->sSecretKey = $this->getConfig('SecretKey');
	}

	public function onAddToContentSecurityPolicyDefault($aArgs, &$aAddDefault)
	{
		$aAddDefault[] = "https://" . $this->sHost;
		$aAddDefault[] = "https://" . $this->sBucket . "." . $this->sHost;
	}
	
	/**
	 * Obtains list of module settings for authenticated user.
	 *
	 * @return array
	 */
	public function GetSettings($TenantId = null)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

		if (!isset($this->aSettings))
		{
			$oSettings = $this->GetModuleSettings();
			if (!empty($TenantId))
			{
				\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
				$oTenant = \Aurora\System\Api::getTenantById($TenantId);

				if ($oTenant)
				{
					$this->aSettings = [
						'Region' => $oSettings->GetTenantValue($oTenant->Name, 'Region', ''),
						'Host' => $oSettings->GetTenantValue($oTenant->Name, 'Host', ''),
					];
				}
			}
			else
			{
				$this->aSettings = [
					'AccessKey' => $oSettings->GetValue('AccessKey', ''),
					'SecretKey' => $oSettings->GetValue('SecretKey', ''),
					'Region' => $oSettings->GetValue('Region', ''),
					'Host' => $oSettings->GetValue('Host', ''),
					'BucketPrefix' => $oSettings->GetValue('BucketPrefix', ''),
				];
			}
		}

		return $this->aSettings;
	}

	 /**
	  * Updates module's settings - saves them to config.json file.
	  * @param string $AccessKey
	  * @param string $SecretKey
	  * @param string $Region
	  * @param string $Host
	  * @param string $BucketPrefix
	  * @return boolean
	  */
	public function UpdateS3Settings($AccessKey, $SecretKey, $Region, $Host, $BucketPrefix, $TenantId = null)
	{
	 	$oSettings = $this->GetModuleSettings();

	 	if (!empty($TenantId))
	 	{
	 		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
	 		$oTenant = \Aurora\System\Api::getTenantById($TenantId);

	 		if ($oTenant)
	 		{
	 			$oSettings->SetTenantValue($oTenant->Name, 'Region', $Region);
				$oSettings->SetTenantValue($oTenant->Name, 'Host', $Host);
	 			return $oSettings->SaveTenantSettings($oTenant->Name);
	 		}
	 	}
	 	else
	 	{
	 		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);

	 		$oSettings->SetValue('AccessKey', $AccessKey);
	 		$oSettings->SetValue('SecretKey', $SecretKey);
	 		$oSettings->SetValue('Region', $Region);
	 		$oSettings->SetValue('Host', $Host);
	 		$oSettings->SetValue('BucketPrefix', $BucketPrefix);
	 		return $oSettings->Save();
	 	}
	}

	public function GetUsersFolders($iTenantId)
	{
		$this->sBucket = $this->getBucketForTenant($iTenantId);

		$results = $this->getClient(true)->listObjectsV2([
			'Bucket' => $this->getBucketForTenant($iTenantId),
			'Prefix' => '',
			'Delimiter' => '/'
		]);

		$aUsersFolders = [];
		if (is_array($results['CommonPrefixes']) && count($results['CommonPrefixes']) > 0)
		{
			foreach ($results['CommonPrefixes'] as $aPrefix)
			{
				if (substr($aPrefix['Prefix'], -1) === '/')
				{
					$aUsersFolders[] = \rtrim($aPrefix['Prefix'], '/');
				}
			}
		}

		return $aUsersFolders;
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
		return new  S3Client($options);
	}

	/**
	 * Obtains DropBox client if passed $sType is DropBox account type.
	 *
	 * @param string $sType Service type.
	 * @return \Dropbox\Client
	 */
	protected function getClient($bRenew = false)
	{
		if ($this->oClient === null || $bRenew)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

			$this->oClient = $this->getS3Client();

			if(!$this->oClient->doesBucketExist($this->sBucket))
			{
				$this->oClient->createBucket([
					'Bucket' => $this->sBucket
				]);

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
		$oTenant = \Aurora\Modules\Core\Module::getInstance()->GetTenantUnchecked($iIdTenant);
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
		$sFullToPath = $sUserPublicId . $sToPath.'/'.$sNewName . $sSuffix;

		$oClient = $this->getClient();

		$oObject = $oClient->HeadObject([
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
					'Key' => $sUserPublicId . $sFromPath.'/'.$sOldName . $sSuffix
				]);
			}
			$mResult = true;
		}

		return $mResult;
	}

	protected function copyOrMove($UserId, $FromType, $FromPath, $FromName, $ToType, $ToPath, $ToName, $IsMove = false)
	{
		$sUserPublicId = \Aurora\Api::getUserPublicIdById($UserId);
		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($sUserPublicId);

		$sPath = 'files/' . $FromType . $FromPath . '/' . $FromName;

		$oItem = $oServer->tree->getNodeForPath($sPath);

		if (($oItem instanceof SharedFile || $oItem instanceof SharedDirectory) && !$oItem->isInherited())
		{
			$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
			$oPdo->updateSharedFileSharePath($oItem->getOwner(), $oItem->getName(), $FromPath, $ToPath);

			$oItem = $oItem->getNode();
		}
		else
		{
			$oItem->copyObjectTo($ToType, $ToPath, $ToName, $IsMove);
			$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
			$oPdo->updateShare(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $FromType, $FromPath . '/' . $FromName, $ToType, $ToPath . '/' . $ToName);
		}
	}

	/**
	 * Moves file if $aData['Type'] is DropBox account type.
	 *
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterMove(&$aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		if ($this->checkStorageType($aArgs['FromType']))
		{
			$mResult = false;

			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			if ($aArgs['ToType'] === $aArgs['FromType'])
			{	
				foreach ($aArgs['Files'] as $aFile)
				{
					$ToName = isset($aFile['NewName']) ? $aFile['NewName'] : $aFile['Name'];
					$this->copyOrMove($UserId, $aArgs['FromType'], $aFile['FromPath'], $aFile['Name'], $aArgs['ToType'], $aArgs['ToPath'], $ToName, true);
				}
				$mResult = true;
			}
		}

	}

	/**
	 * Copies file if $aData['Type'] is DropBox account type.
	 *
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterCopy(&$aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		if ($this->checkStorageType($aArgs['FromType']))
		{
			$mResult = false;

			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			if ($aArgs['ToType'] === $aArgs['FromType'])
			{	
				foreach ($aArgs['Files'] as $aFile)
				{
					$ToName = isset($aFile['NewName']) ? $aFile['NewName'] : $aFile['Name'];
					$this->copyOrMove($UserId, $aArgs['FromType'], $aFile['FromPath'], $aFile['Name'], $aArgs['ToType'], $aArgs['ToPath'], $ToName, false);
				}
				$mResult = true;
			}
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetQuota($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$aQuota = [0, 0];
			$oNode = \Afterlogic\DAV\Server::getNodeForPath('files/' . static::$sStorageType);

			if (is_a($oNode, 'Afterlogic\\DAV\\FS\\S3\\' . ucfirst(static::$sStorageType) . '\\Root'))
			{
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

	/**
	 * Puts file content to $mResult.
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onGetFile($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$sUserPiblicId = \Aurora\Api::getUserPublicIdById($UserId);

			try
			{
				$oServer = \Afterlogic\DAV\Server::getInstance();
				$oServer->setUser($sUserPiblicId);
				$sPath = 'files/' . $aArgs['Type'] . $aArgs['Path'] . '/' . $aArgs['Name'];
				$oNode = $oServer->tree->getNodeForPath($sPath);

				$sExt = \pathinfo($aArgs['Name'], PATHINFO_EXTENSION);

				$bNoRedirect = (isset($aArgs['NoRedirect']) && $aArgs['NoRedirect']) ? true : false;

				if ($oNode instanceof \Afterlogic\DAV\FS\File)
				{
					if ($this->isNeedToReturnBody() || \strtolower($sExt) === 'url' || $bNoRedirect)
					{
						$mResult = $oNode->get(false);
					}
					else if ($this->isNeedToReturnWithContectDisposition())
					{
						$oNode->getWithContentDisposition();
					}
					else
					{
						$oNode->get(true);
					}
				}
			}
			catch (\Sabre\DAV\Exception\NotFound $oEx)
			{
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
		$this->oTenantForDelete = \Aurora\Modules\Core\Module::Decorator()->GetTenantUnchecked($aArgs['TenantId']);
	}

	public function onAfterDeleteTenant($aArgs, &$mResult)
	{
		if ($this->oTenantForDelete instanceof \Aurora\Modules\Core\Models\Tenant)
		{	try
			{
				$oS3Client = $this->getS3Client();
				$oS3Client->deleteBucket([
					'Bucket' => \strtolower($this->sBucketPrefix . \str_replace([' ', '.'], '-', \Afterlogic\DAV\Server::getTenantName($this->oTenantForDelete->Name)))
				]);
				$this->oTenantForDelete = null;
			}
			catch(\Exception $oEx){}
		}
	}

	public function onBeforeDeleteUser($aArgs, &$mResult)
	{
		if (isset($aArgs['UserId']))
		{
			$this->oUserForDelete = \Aurora\System\Api::getUserById($aArgs['UserId']);
		}
	}

	public function onAfterDeleteUser($aArgs, $mResult)
	{
		if ($this->oUserForDelete instanceof \Aurora\Modules\Core\Models\User)
		{
			if ($this->DeleteUserFolder($this->oUserForDelete->IdTenant, $this->oUserForDelete->PublicId))
			{
				$this->oUserForDelete = null;
			}
		}
	}

	public function DeleteUserFolder($IdTenant, $PublicId)
	{
		$bResult = false;
		try
		{
			$oS3Client = $this->getS3Client();
			$res = $oS3Client->deleteMatchingObjects(
				$this->getBucketForTenant($IdTenant),
				$PublicId . '/'
			);
			$bResult = true;
		}
		catch(\Exception $oEx)
		{
			$bResult = false;
		}

		return $bResult;
	}

	public function TestConnection($Region, $Host, $AccessKey = null, $SecretKey = null, $TenantId = null)
	{
		$mResult = true;

		if (isset($TenantId)) {
			
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

			$oTenant = \Aurora\System\Api::getTenantById($TenantId);

			if ($oTenant) {
				$AccessKey = $this->getConfig('AccessKey');
				$SecretKey = $this->getConfig('SecretKey');
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
		}
		catch(\Exception $e) {
		
			$mResult = false;
			Api::LogException($e);
		}
		return $mResult;
	}
}
