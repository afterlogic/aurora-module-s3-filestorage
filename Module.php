<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\S3Filestorage;

use Aws\S3\S3Client;

/**
 * Adds ability to work with S3 file storage inside Aurora Files module.
 * 
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\Modules\PersonalFiles\Module
{
	protected $oClient = null;
	protected $sUserPublicId = null;

	protected $sBucketPrefix;
	protected $sBucket;
	protected $sRegion;
	protected $sHost;
	protected $sAccessKey;
	protected $sSecretKey;

	protected $aSettings = null;

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


		$this->sBucketPrefix = $this->getConfig('BucketPrefix');
		$this->sBucket = \strtolower($this->sBucketPrefix . \str_replace(' ', '-', $this->getTenantName()));
		$this->sHost = $this->getConfig('Host');
		$this->sRegion = $this->getConfig('Region');
		$this->sAccessKey = $this->getConfig('AccessKey');
		$this->sSecretKey = $this->getConfig('SecretKey');
	}
	
	/**
	 * Obtains list of module settings for authenticated user.
	 * 
	 * @return array
	 */
	public function GetSettings($TenantId = null)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

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
	
	protected function getS3Client($endpoint, $bucket_endpoint = false)
	{
		$signature_version = 'v4';
		if (!$bucket_endpoint)
		{
			$signature_version = 'v4-unsigned-body';
		}

		return S3Client::factory([
			'region' => $this->sRegion,
			'version' => 'latest',
			'endpoint' => $endpoint,
			'credentials' => [
				'key'    => $this->sAccessKey,
				'secret' => $this->sSecretKey,
			],
			'bucket_endpoint' => $bucket_endpoint,
			'signature_version' => $signature_version
		]);					
	}

	/**
	 * Obtains DropBox client if passed $sType is DropBox account type.
	 * 
	 * @param string $sType Service type.
	 * @return \Dropbox\Client
	 */
	protected function getClient()
	{
		if ($this->oClient === null)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

			$endpoint = "https://".$this->sRegion.".".$this->sHost;

			$oS3Client = $this->getS3Client($endpoint);

			if(!$oS3Client->doesBucketExist($this->sBucket)) 
			{
				$oS3Client->createBucket([
					'Bucket' => $this->sBucket
				]);

				$res = $oS3Client->putBucketCors([
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
									(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]"
								],
								'MaxAgeSeconds' => 0,
							],
						],
					],
					'ContentMD5' => '',
				]);				
			}
	
			$endpoint = "https://".$this->sBucket.".".$this->sRegion.".".$this->sHost;
			$this->oClient = $this->getS3Client($endpoint, true);
		}
		
		return $this->oClient;
	}	

	protected function getUserPublicId()
	{
		if (!isset($this->sUserPublicId))
		{
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			$this->sUserPublicId = $oUser->PublicId;
		}

		return $this->sUserPublicId;
	}

	/**
	 * getTenantName
	 *
	 * @return void
	 */
	protected function getTenantName()
	{
		if (!isset($this->sTenantName))
		{
			$this->sTenantName = \Aurora\System\Api::getTenantName();
		}

		return $this->sTenantName;
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
		if ($oObject)
		{
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

		if ($res)	
		{
			if ($bMove)
			{
				$res = $oClient->deleteObject([
					'Bucket' => $this->sBucket,
					'Key' => $sUserPublicId . $sFromPath.'/'.$sOldName . $sSuffix
				]);					
			}
			$mResult = true;
		}

		return $mResult;
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
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			if ($aArgs['ToType'] === $aArgs['FromType'])
			{
				foreach ($aArgs['Files'] as $aFile)
				{
					$this->copyObject($aFile['FromPath'], $aArgs['ToPath'], $aFile['Name'], $aFile['Name'], $aFile['IsFolder'], true);
				}
				$mResult = true;
			}
			return true;
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
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			$mResult = false;

			if ($aArgs['ToType'] === $aArgs['FromType'])
			{
				foreach ($aArgs['Files'] as $aFile)
				{
					$this->copyObject($aFile['FromPath'], $aArgs['ToPath'], $aFile['Name'], $aFile['Name'], $aFile['IsFolder']);
				}
				$mResult = true;
			}
			return true;
		}
	}		

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetQuota($aArgs, &$mResult)
	{
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$aQuota = [0, 0];
			$oNode = \Afterlogic\DAV\Server::getInstance()->tree->getNodeForPath('files/' . self::$sStorageType);

			if (is_a($oNode, 'Afterlogic\\DAV\\FS\\S3\\' . ucfirst(self::$sStorageType) . '\\Root'))
			{
				$aQuota = $oNode->getQuotaInfo();
			}

			$mResult = [
				'Used' => $aQuota[0],
				'Limit' => $aQuota[1]
			];

			return true;
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
			$sUserPiblicId = \Aurora\Api::getUserPublicIdById($aArgs['UserId']);
			
			try
			{
				$oServer = \Afterlogic\DAV\Server::getInstance();
				$oServer->setUser($sUserPiblicId);
				$sPath = 'files/' . $aArgs['Type'] . $aArgs['Path'] . '/' . $aArgs['Name'];
				$oNode = $oServer->tree->getNodeForPath($sPath);		

				if ($oNode instanceof \Afterlogic\DAV\FS\S3\File)
				{
					if ($this->isNeedToReturnBody())
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
				header("HTTP/1.0 404 Not Found");
				die('File not found');
			}
			
			return true;
		}
	}
	
	public function onBeforeDeleteTenant($aArgs, &$mResult)
	{
		$oTenant = \Aurora\Modules\Core\Module::Decorator()->GetTenantUnchecked($aArgs['TenantId']);
		if ($oTenant instanceof \Aurora\Modules\Core\Classes\Tenant)
		{	try
			{
				$oS3Client = $this->getS3Client(
					"https://".$this->sRegion.".".$this->sHost
				);
				$oS3Client->deleteBucket([
					'Bucket' => \strtolower($this->sBucketPrefix . \str_replace(' ', '-', \Afterlogic\DAV\Server::getTenantName($oTenant->Name)))
				]);
			}
			catch(\Exception $oEx){}
		}
	}	
}
