<?php
/**
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\DigitalOceanFilestorage;

use Aws\S3\S3Client;
use GuzzleHttp\RedirectMiddleware;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing AfterLogic Software License
 * @copyright Copyright (c) 2018, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected static $sStorageType = 'digitalocean';
	protected $oClient = null;
	protected $sUserPublicId = null;

	protected $sBucketPrefix;
	protected $sBucket;
	protected $sRegion;
	protected $sHost;
	protected $sAccessKey;
	protected $sSecretKey;

	
	/***** private functions *****/
	/**
	 * Initializes Module.
	 * 
	 * @ignore
	 */
	public function init() 
	{
		$this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::GetItems::after', array($this, 'onAfterGetItems'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
		$this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy')); 
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'));
		$this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));
		$this->subscribeEvent('Files::GetItems::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));

		$this->subscribeEvent('System::download-file-entry::before', array($this, 'onBeforeDownloadFileEntry'));

		$this->sBucketPrefix = $this->getConfig('BucketPrefix');
		$this->sBucket = \strtolower($this->sBucketPrefix . $this->getTenantName());
		$this->sHost = $this->getConfig('Host');
		$this->sRegion = $this->getConfig('Region');
		$this->sAccessKey = $this->getConfig('AccessKey');
		$this->sSecretKey = $this->getConfig('SecretKey');
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
			}
	
			$endpoint = "https://".$this->sBucket.".".$this->sRegion.".".$this->sHost;
			$this->oClient = $this->getS3Client($endpoint, true);
		}
		
		return $this->oClient;
	}	

	protected function getPublicUserId()
	{
		if (!isset($this->sUserPublicId))
		{
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			$this->sUserPublicId = $oUser->PublicId;
		}

		return $this->sUserPublicId;
	}

	protected function getTenantName()
	{
		if (!isset($this->sTenantName))
		{
			$this->sTenantName = \Aurora\System\Api::getTenantName();
		}

		return $this->sTenantName;
	}

	
	/**
	 * Write to the $aResult variable information about DropBox storage.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetStorages($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

		if (!$this->getConfig('Disabled', true))
		{		
			$mResult[] = [
				'Type' => self::$sStorageType, 
				'IsExternal' => true,
				'DisplayName' => 'Digital Ocean'
			];
		}
	}
	
	/**
	 * Returns directory name for the specified path.
	 * 
	 * @param string $sPath Path to the file.
	 * @return string
	 */
	protected function getDirName($sPath)
	{
		$sPath = dirname($sPath);
		return str_replace(DIRECTORY_SEPARATOR, '/', $sPath); 
	}
	
	/**
	 * Returns base name for the specified path.
	 * 
	 * @param string $sPath Path to the file.
	 * @return string
	 */
	protected function getBaseName($sPath)
	{
		$aPath = explode('/', $sPath);
		return end($aPath); 
	}
	
	/**
	 * 
	 * @param type $oItem
	 * @return type
	 */
	protected function getItemHash($oItem)
	{
		return \Aurora\System\Api::EncodeKeyValues(array(
			'UserId' => \Aurora\System\Api::getAuthenticatedUserId(), 
			'Type' => $oItem->TypeStr,
			'Path' => '',
			'Name' => $oItem->FullPath
		));			
	}	
	
	protected function hasThumb($sName)
	{
		return in_array(
			pathinfo($sName, PATHINFO_EXTENSION), 
			['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'bmp']
		);
	}

	/**
	 * Populates file info.
	 * 
	 * @param string $sType Service type.
	 * @param \Dropbox\Client $oClient DropBox client.
	 * @param array $aData Array contains information about file.
	 * @return \Aurora\Modules\Files\Classes\FileItem|false
	 */
	protected function populateFileInfo($aData)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		$mResult = false;
		if ($aData)
		{
			$sKey = $aData['Key'];
			list($sPath, $sFile) = \Sabre\Uri\split($aData['Key']);

			$sUserPublicId = $this->getPublicUserId();
			$sPath = substr($sPath, strlen($sUserPublicId));
/*
			$oObject = $this->getClient()->HeadObject([
				'Bucket' => $this->sBucket,
				'Key' => $aData['Key'],
			]);
*/
			$bIsFolder = substr($aData['Key'], -1) === '/';

			$sName = basename($aData['Key']);
			
			$mResult /*@var $mResult \Aurora\Modules\Files\Classes\FileItem */ = new  \Aurora\Modules\Files\Classes\FileItem();
			$mResult->IsExternal = true;
			$mResult->TypeStr = self::$sStorageType;
			$mResult->IsFolder = $bIsFolder;
			$mResult->Id = $sFile;
			$mResult->Name = $sName;
			$mResult->Path = $sPath;
			$mResult->Size = $aData['Size'];

			$mResult->Owner = $sUserPublicId;

//			if (!$mResult->IsFolder)
//			{
//				$mResult->LastModified =  date("U",strtotime($aData->getServerModified()));
//			}

			$mResult->FullPath = $mResult->Name !== '' ? $mResult->Path . '/' . $mResult->Name : $mResult->Path ;
			$mResult->ContentType = \Aurora\System\Utils::MimeContentType($mResult->Name);
			
			$mResult->Thumb = $this->hasThumb($mResult->Name);

			if ($mResult->IsFolder)
			{
				$mResult->AddAction([
					'list' => []
				]);
			}
			else
			{
				$mResult->AddAction([
					'view' => [
						'url' => '?download-file/' . $this->getItemHash($mResult) .'/view'
					]
				]);
				$mResult->AddAction([
					'download' => [
						'url' => '?download-file/' . $this->getItemHash($mResult)
					]
				]);
			}
		}
		return $mResult;
	}	
	
	/**
	 * Writes to the $mResult variable open file source if $sType is DropBox account type.
	 * 
	 * @ignore
	 * @param int $iUserId Identifier of the authenticated user.
	 * @param string $sType Service type.
	 * @param string $sFullPath File path.
	 * @param string $sName File name.
	 * @param boolean $bThumb **true** if thumbnail is expected.
	 * @param mixed $mResult
	 */
	public function onGetFile($aArgs, &$mResult)
	{
		if ($aArgs['Type'] === self::$sStorageType)
		{
			return true;
		}
	}	
	
	/**
	 * Writes to $aData variable list of DropBox files if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetItems($aArgs, &$mResult)
	{
		$sUserPublicId = $this->getPublicUserId();

		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();

			if ($oClient)
			{
				$Root = $sUserPublicId . '/';
				$Path =  rtrim($Root . ltrim($aArgs['Path'], '/'), '/') . '/';
				$iSlashesCount = substr_count($Path, '/');

				$results = $oClient->getPaginator('ListObjectsV2', [
					'Bucket' => $this->sBucket,
					'Prefix' => $Path
				]);

				$sFilter = 'Contents[?starts_with(Key, `' . $Path . '`)%s]';
				if (!empty($aArgs['Pattern']))
				{
					$sFilter = sprintf($sFilter,  '&& contains(Key, `' . $aArgs['Pattern'] . '`)'); 
				}
				else
				{
					$sFilter = sprintf($sFilter,  ''); 
				}
				foreach ($results->search($sFilter) as $item) 
				{
					$iItemSlashesCount = substr_count($item['Key'], '/');
					if ($iItemSlashesCount === $iSlashesCount && substr($item['Key'], -1) !== '/' || 
						$iItemSlashesCount === $iSlashesCount + 1 && substr($item['Key'], -1) === '/' || !empty($aArgs['Pattern']))
					{
						$oItem /*@var $oItem \Aurora\Modules\Files\Classes\FileItem */ = $this->populateFileInfo($item);
						if ($oItem)
						{
							$mResult[] = $oItem;
						}
					}
				}
			}
			
			return true;
		}
	}	

	/**
	 * Creates folder if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterCreateFolder($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;
				
				$sUserPublicId = $this->getPublicUserId();

				$res = $this->getClient()->putObject([
					'Bucket' => $this->sBucket,
					'Key' => $sUserPublicId . $aArgs['Path'].'/'.$aArgs['FolderName'] . '/',
					'Body' => ''
				]);

				if ($res)
				{
					$mResult = true;
				}
			}
			return true;
		}
	}	

	/**
	 * Creates file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onCreateFile($aArgs, &$Result)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$Result = false;

				$sUserPublicId = $this->getPublicUserId();
	
				$Path = $sUserPublicId . $aArgs['Path'].'/'.$aArgs['Name'];
				$rData = $aArgs['Data'];
				if (!is_resource($aArgs['Data']))
				{
					$rData = fopen('php://memory','r+');
					fwrite($rData, $aArgs['Data']);
					rewind($rData);					
				}

				$aMetadata = isset($aArgs['ExtendedProps']) ? $aArgs['ExtendedProps'] : [];

				$res = $this->getClient()->putObject([
					'Bucket' => $this->sBucket,
					'Key' => $Path,
					'Body' => $rData,
					'Metadata' => $aMetadata
				]);

				if ($res)
				{
					$Result = true;
				}

				return true;
			}
		}
	}	

	/**
	 * Deletes file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterDelete($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				$sUserPublicId = $this->getPublicUserId();
					
				$aObjects = [];
				foreach ($aArgs['Items'] as $aItem)
				{
					$aObjects[]= [
						'Key' => $sUserPublicId . $aItem['Path'].'/'.$aItem['Name']
					];
				}
				
				$res = $oClient->deleteObjects([
					'Bucket' => $this->sBucket,
					'Delete' => [
						'Objects' => $aObjects,
					]
				]);	
				if ($res)	
				{
					$mResult = true;
				}
			}
			return true;
		}
	}	

	protected function copyObject($sFromPath, $sToPath, $sOldName, $sNewName, $bMove = false)
	{
		$mResult = false;

		$sUserPublicId = $this->getPublicUserId();

		$sFullFromPath = $this->sBucket . '/' . $sUserPublicId . $sFromPath . '/' . $sOldName;
		$sFullToPath = $sUserPublicId . $sToPath.'/'.$sNewName;

		$oClient = $this->getClient();
		$res = $oClient->copyObject([
			'Bucket' => $this->sBucket,
			'Key' => $sFullToPath,
			'CopySource' => $sFullFromPath
		]);
		if ($res)	
		{
			if ($bMove)
			{
				$res = $oClient->deleteObject([
					'Bucket' => $this->sBucket,
					'Key' => $sUserPublicId . $sFromPath.'/'.$sOldName
				]);					
			}
			$mResult = true;
		}

		return $mResult;
	}

	/**
	 * Renames file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterRename($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = $this->copyObject($aArgs['Path'], $aArgs['Path'], $aArgs['Name'], $aArgs['NewName'], true);
			}
		}
	}	

	/**
	 * Moves file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterMove($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$this->copyObject($aFile['FromPath'], $aArgs['ToPath'], $aFile['Name'], $aFile['Name'], true);
					}
					$mResult = true;
				}
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
	public function onAfterCopy($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$this->copyObject($aFile['FromPath'], $aArgs['ToPath'], $aFile['Name'], $aFile['Name']);
					}
					$mResult = true;
				}
			}
			return true;
		}
	}		
	
	protected function _getFileInfo($sPath, $sName)
	{
		$mResult = false;
		$oClient = $this->GetClient();
		if ($oClient)
		{
			$mResult = $oClient->getMetadata($sPath.'/'.$sName);
		}
		
		return $mResult;
	}


	/**
	 * @ignore
	 * @todo not used
	 * @param object $oAccount
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param boolean $mResult
	 * @param boolean $bBreak
	 */
	public function onAfterGetFileInfo($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		if (self::$sStorageType === $aArgs['Type'])
		{
			$mFileInfo = $this->_getFileInfo($aArgs['Path'], $aArgs['Id']);
			if ($mFileInfo)
			{
				$mResult = $this->PopulateFileInfo($mFileInfo);
			}
			return true;
		}		
	}	
	
	/**
	 * @ignore
	 * @todo not used
	 * @param object $oItem
	 * @return boolean
	 */
	public function onAfterPopulateFileItem($aArgs, &$oItem)
	{
	}	
	
	protected function getMetadataLink($sLink)
	{
		$oClient = $this->getClient();
        $response = $oClient->postToAPI(
            '/2/sharing/get_shared_link_metadata', 
			array(
				'url' => $sLink
			)
		);

        if ($response->getHttpStatusCode() === 404) return null;
        if ($response->getHttpStatusCode() !== 200) return null;

        $metadata = $response->getDecodedBody();
        if (array_key_exists("is_deleted", $metadata) && $metadata["is_deleted"]) return null;
        return $metadata;
	}
	
	public function CheckUrlFile(&$aArgs, &$mResult)
	{
		if (strpos($aArgs['Path'], '.url') !== false)
		{
			list($sUrl, $sPath) = explode('.url', $aArgs['Path']);
			$sUrl .= '.url';
			$aArgs['Path'] = $sUrl;
			$this->prepareArgs($aArgs);
			if ($sPath)
			{
				$aArgs['Path'] .= $sPath;
			}
		}
	}

	protected function prepareArgs(&$aData)
	{
		$aPathInfo = pathinfo($aData['Path']);
		$sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
		if ($sExtension === 'url')
		{
			$aArgs = array(
				'UserId' => $aData['UserId'],
				'Type' => $aData['Type'],
				'Path' => $aPathInfo['dirname'],
				'Name' => $aPathInfo['basename'],
				'IsThumb' => false
			);
			$mResult = false;
			\Aurora\System\Api::GetModuleManager()->broadcastEvent(
				'Files',
				'GetFile', 
				$aArgs,
				$mResult
			);	
			if (is_resource($mResult))
			{
				$aUrlFileInfo = \Aurora\System\Utils::parseIniString(stream_get_contents($mResult));
				if ($aUrlFileInfo && isset($aUrlFileInfo['URL']))
				{
					if (false !== strpos($aUrlFileInfo['URL'], 'dl.dropboxusercontent.com') || 
						false !== strpos($aUrlFileInfo['URL'], 'dropbox.com'))
					{
						$aData['Type'] = 'dropbox';
						$aMetadata = $this->getMetadataLink($aUrlFileInfo['URL']);
						if (isset($aMetadata['path']))
						{
							$aData['Path'] = $aMetadata['path'];
						}
					}
				}
			}		
		}
	}	
	/***** private functions *****/
	
	/**
	 * Passes data to connect to service.
	 * 
	 * @ignore
	 * @param string $aArgs Service type to verify if data should be passed.
	 * @param boolean|array $mResult variable passed by reference to take the result.
	 */
	public function onGetSettings($aArgs, &$mResult)
	{
		$oUser = \Aurora\System\Api::getAuthenticatedUser();
		
		if (!empty($oUser))
		{
			$aScope = array(
				'Name' => 'storage',
				'Description' => $this->i18N('SCOPE_FILESTORAGE'),
				'Value' => false
			);
			if ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin)
			{
				$aScope['Value'] = $this->issetScope('storage');
				$mResult['Scopes'][] = $aScope;
			}
			if ($oUser->Role === \Aurora\System\Enums\UserRole::NormalUser)
			{
				if ($aArgs['OAuthAccount'] instanceof \Aurora\Modules\OAuthIntegratorWebclient\Classes\Account)
				{
					$aScope['Value'] = $aArgs['OAuthAccount']->issetScope('storage');
				}
				if ($this->issetScope('storage'))
				{
					$mResult['Scopes'][] = $aScope;
				}
			}
		}	
	}
	
	public function onAfterUpdateSettings($aArgs, &$mResult)
	{
		$sScope = '';
		if (isset($aArgs['Scopes']) && is_array($aArgs['Scopes']))
		{
			foreach($aArgs['Scopes'] as $aScope)
			{
				if ($aScope['Name'] === 'storage')
				{
					if ($aScope['Value'])
					{
						$sScope = 'storage';
						break;
					}
				}
			}
		}
		$this->setConfig('Scopes', $sScope);
		$this->saveModuleConfig();
	}

	public function onBeforeDownloadFileEntry()
	{
		$sHash = (string) \Aurora\System\Application::GetPathItemByIndex(1, '');
		$sAction = (string) \Aurora\System\Application::GetPathItemByIndex(2, '');
		$iOffset = (int) \Aurora\System\Application::GetPathItemByIndex(3, '');
		$iChunkSize = (int) \Aurora\System\Application::GetPathItemByIndex(4, '');

		$aValues = \Aurora\System\Api::DecodeKeyValues($sHash);

		if ($aValues['Type'] === self::$sStorageType)
		{
			$iUserId = isset($aValues['UserId']) ? (int) $aValues['UserId'] : 0;
			$sPath = isset($aValues['Path']) ? $aValues['Path'] : '';
			$sFileName = isset($aValues['Name']) ? $aValues['Name'] : '';
			$sPublicHash = isset($aValues['PublicHash']) ? $aValues['PublicHash'] : null;

			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			$sUserPublicId = $oUser->PublicId;

			$aArgs = [
				'Bucket' => $this->sBucket,
				'Key' => $sUserPublicId . $sPath . $sFileName,
			];

			if ($sAction === '' || $sAction === 'download')
			{
				$aArgs['ResponseContentType'] = 'application/octet-stream';
				$aArgs['ResponseContentDisposition'] = 'attachment; filename="'. $sFileName .'"';
			}

			if ($sAction === 'thumb') 
			{
				if (!empty($sHash))
				{
					\Aurora\System\Managers\Response::verifyCacheByKey($sHash);
				}
				$oCache = new \Aurora\System\Managers\Cache('thumbs', \Aurora\System\Api::getUserUUIDById($iUserId));
				$sCacheFileName = \md5('Raw/Thumb/'.$sHash.'/'.$sFileName);
				$sThumb = $oCache->get($sCacheFileName);

				if (!$sThumb)
				{
					$oObject = $this->getClient()->GetObject($aArgs);

					$rResource = $oObject['Body'];

					$iRotateAngle = 0;
					$oImageManager = new \Intervention\Image\ImageManager(['driver' => 'Gd']);
					$oThumb = $oImageManager->make($rResource);
					if ($iRotateAngle > 0)
					{
						$oThumb = $oThumb->rotate($iRotateAngle);
					}
					$sThumb = $oThumb->heighten(100)->widen(100)->response();
					$oCache->set($sCacheFileName, $sThumb);
				}

				$sContentType = \MailSo\Base\Utils::MimeContentType($sFileName);
				\Aurora\System\Managers\Response::OutputHeaders(false, $sContentType, $sFileName);

				echo $sThumb;
				exit;
			} 			

			//Creating a presigned URL
			$cmd = $this->getClient()->getCommand('GetObject', $aArgs);
			$request = $this->getClient()->createPresignedRequest($cmd, '+5 minutes');			
			header('Location: ' . (string) $request->getUri());
			exit;
		}
	}


}
